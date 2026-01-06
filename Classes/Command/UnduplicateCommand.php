<?php

declare(strict_types=1);

namespace ElementareTeilchen\Unduplicator\Command;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Result;
use ElementareTeilchen\Unduplicator\Exception\UnduplicatorException;
use ElementareTeilchen\Unduplicator\Metadata\MetadataUpdateHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Backend\Command\ProgressListener\ReferenceIndexProgressListener;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2020 Franz Kugelmann <franz.kugelmann@elementare-teilchen.de>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * Symfony Command for unduplicating stuff like sys_file entries
 *
 * since DB may have (probably has) case-insensitive collation, we must make sure we do a case-sensitive compare.
 * The following can be used:
 * (1) Also Comparing the identifier_hash should work ... but the identifier_hash may not always be set
 * (2) using BINARY, e.g. where BINARY identifer ... but this cannot be used in "GROUP BY"
 * (3) using md5(identifier) or other functions ... may make query less portable across DB servers
 * (4) do extra compare in PHP
 *   => (4) is safest option and used in this class (though it may be a little inefficient)
 */
#[AsCommand(
    name: 'unduplicate:sysfile',
    description: 'Unduplicate duplicate sys_file entries'
)]
class UnduplicateCommand extends Command
{
    /**
     * @var bool
     */
    private $dryRun = false;

    /**
     * @var bool
     */
    private $keepOldest = false;

    private int $storage = -1;

    private ?SymfonyStyle $output = null;

    private ?InputInterface $input = null;

    private array $fieldsToCheck = ['description'];

    private ?MetadataUpdateHandler $metadataHandler = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository
    ) {
        parent::__construct();
    }

    /**
     * Configure the command by defining the name, options and arguments
     */
    public function configure(): void
    {
        $this->setDescription('Finds duplicates in sys_file and unduplicates them.
        By default it will use the newest (highest uid) record as master and delete the older records.');
        $this->setHelp(
            'currently fix references in ' . LF .
            '- sys_file_reference::link ' . LF .
            '- sys_file_reference::uid_local ' . LF .
            '- tt_content::headerlink ' . LF .
            '- tt_content::bodytext ' . LF .
            '- tx_news_domain_model_news::bodytext ' . LF .
            '- tx_news_domain_model_news::internalurl ' . LF .
            'AND the remove the duplicate in sys_file and sys_file_metadata'
        );
        $this->addOption(
            'dry-run',
            'd',
            InputOption::VALUE_NONE,
            'If set, all database updates are not executed'
        )
            ->addOption(
                'identifier',
                'i',
                InputOption::VALUE_REQUIRED,
                'Only use this identifier'
            )
            ->addOption(
                'storage',
                's',
                InputOption::VALUE_REQUIRED,
                'Only use this storage',
                -1
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Force keep or overwrite of metadata of the master record in case of conflict. Possible values: keep, keep-nonempty.
                Default: overwrite. Keep-nonempty is keeping only nonempty metadata in master, but updating the empty.',
                false
            )
            ->addOption(
                'keep-oldest',
                'o',
                InputOption::VALUE_NONE,
                'Use the oldest record as master instead of the newest',
            )
            ->addOption(
                'interactive',
                'a',
                InputOption::VALUE_NONE,
                'When encountering a conflict, ask for user input to determine which record should be kept.',
            )
            ->addOption(
                'meta-fields',
                'm',
                InputOption::VALUE_REQUIRED,
                'Specify a comma seperated list of fields to check for metadata comparison.
                Default description, with EXT:filemetadata: description, caption, copyright'
            )->addOption(
                'update-refindex',
                'u',
                InputOption::VALUE_NONE,
                'Setting this option automatically updates the reference index and does not ask on command line.
                Alternatively, use -n to avoid the interactive mode'
            );
    }

    /**
     * make sure we do not have duplicate records with same language
     * @throws UnduplicatorException
     */
    public function checkDuplicateMetadataRecords(array $oldMetadataRecords): void
    {
        $languageRecords = [];
        foreach ($oldMetadataRecords as $oldMetadata) {
            if (isset($languageRecords[$oldMetadata['sys_language_uid']])) {
                throw new UnduplicatorException('More than one metadata record for language ' . $oldMetadata['sys_language_uid'], 7813804023);
            }
            $languageRecords[$oldMetadata['sys_language_uid']][] = $oldMetadata;
        }
    }

    /**
     * Executes the command
     *
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = new SymfonyStyle($input, $output);
        $this->output->title($this->getDescription());

        // Update the reference index
        $this->updateReferenceIndex($input);

        $this->dryRun = $input->getOption('dry-run');
        $this->keepOldest = $input->getOption('keep-oldest');
        $onlyThisIdentifier = $input->getOption('identifier');
        $this->storage = (int)$input->getOption('storage') ?? -1;

        if ($input->hasArgument('meta-fields')) {
            $this->fieldsToCheck = array_map(trim(...), explode(',', (string)$input->getOption('meta-fields')));
        } elseif (ExtensionManagementUtility::isLoaded('filemetadata')) {
            // add default values in case the filemetadata extension is loaded
            $this->fieldsToCheck = array_merge($this->fieldsToCheck, ['caption", "copyright']);
        }

        $this->output->writeln('<info>Using metadata fields: ' . implode(', ', $this->fieldsToCheck) . '</info>');

        $this->metadataHandler = new MetadataUpdateHandler($this->dryRun, $this->input, $this->output, $this->fieldsToCheck, $this->connectionPool);

        try {
            $this->runOn($onlyThisIdentifier);
        } catch (UnduplicatorException $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');
        }
        return 0;
    }

    /**
     * @throws Exception
     */
    public function runOn(mixed $onlyThisIdentifier): void
    {
        $hasConflicts = false;
        $statement = $this->findDuplicates($onlyThisIdentifier);
        $foundDuplicates = 0;
        while ($row = $statement->fetchAssociative()) {
            $identifier = $row['identifier'] ?? '';
            if ($identifier === '') {
                $this->output->warning('Found empty identifier');
                continue;
            }

            $storage = (int)$row['storage'];
            $files = $this->findDuplicateFilesForIdentifier($identifier, $storage);

            $masterMetadataRecords = null;
            $masterFileIdentifier = null;
            $masterFileUid = null;
            foreach ($files as $fileRow) {
                $identifier = $fileRow['identifier'];
                // save uid and identifier of master entry (sort descending by uid), which we want to keep
                if ($masterMetadataRecords === null) {
                    $masterFileIdentifier = $identifier;
                    $masterFileUid = $fileRow['uid'];
                    $masterMetadataRecords = $this->getMetadataRecords($masterFileUid);
                    $this->checkDuplicateMetadataRecords($masterMetadataRecords);
                    continue;
                }
                if ($masterFileIdentifier !== $identifier) {
                    // identifier is not the same, skip this one (may happen because of case-insensitive DB queries)
                    continue;
                }
                $foundDuplicates++;

                $hasConflicts = $this->processDuplicate($masterFileUid, $masterMetadataRecords, $fileRow['uid'], $identifier, $storage) || $hasConflicts;
            }
        }
        if (!$foundDuplicates) {
            $this->output->success('No duplicates found');
        }
        if ($hasConflicts) {
            $this->output->warning('Conflicts found. Manual action required. Run with -v to see details.');
        }
    }

    /**
     * Uses GROUP BY BINARY identifier,storage to make sure we don"t get results for identifiers which are only duplicate
     * if checked case-insensitively.
     *
     * Database may be case-insensitive, e.g. charset "utf8mb5", collation "utf8mb4_unicode_ci".
     */
    public function findDuplicates(mixed $onlyThisIdentifier): Result
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->count('*')
            ->from('sys_file')
            ->having('COUNT(*) > 1');
        $whereExpressions = [];
        if ($onlyThisIdentifier) {
            $whereExpressions[] = $queryBuilder->expr()->eq(
                'identifier',
                $queryBuilder->createNamedParameter($onlyThisIdentifier, Connection::PARAM_STR)
            );
        }
        if ($this->storage > -1) {
            $whereExpressions[] = $queryBuilder->expr()->eq(
                'storage',
                $queryBuilder->createNamedParameter($this->storage, Connection::PARAM_INT)
            );
        }
        if ($whereExpressions) {
            $queryBuilder->where(...$whereExpressions);
        }

        $concreteQueryBuilder = $queryBuilder->getConcreteQueryBuilder();

        // GROUP BY BINARY `identifier`,`storage
        $concreteQueryBuilder->groupBy('MD5(identifier)');
        $concreteQueryBuilder->addGroupBy('storage');
        // SELECT MAX(`identifier`) AS identifier,`storage`
        $concreteQueryBuilder->addSelect('MAX(identifier) AS identifier, storage');

        if ($this->output->isDebug()) {
            $this->output->writeln('sql=' . $queryBuilder->getSQL(), OutputInterface::VERBOSITY_VERBOSE);
        }
        return $queryBuilder
            ->executeQuery();
    }

    /**
     * Must make sure we compare identifier case-sensitively, so using "BINARY identifier" here .
     *
     * Database may be case-insensitive, e.g. charset "utf8mb5", collation "utf8mb4_unicode_ci".
     */
    private function findDuplicateFilesForIdentifier(string $identifier, int $storage): array
    {
        $fileQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');

        $fileQueryBuilder->select('uid', 'identifier')
            ->from('sys_file')
            ->where(
                $fileQueryBuilder->expr()->eq(
                    'storage',
                    $fileQueryBuilder->createNamedParameter($storage, Connection::PARAM_INT)
                ),
                $fileQueryBuilder->expr()->comparison(
                    'MD5(' . $fileQueryBuilder->quoteIdentifier('identifier') . ')',
                    $fileQueryBuilder->expr()::EQ,
                    'MD5(' . $fileQueryBuilder->createNamedParameter($identifier, Connection::PARAM_STR) . ')'
                )
            )->orderBy('uid', $this->keepOldest ? 'ASC' : 'DESC');

        return $fileQueryBuilder->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @throws Exception
     */
    public function processDuplicate(
        int $masterFileUid,
        array $masterMetadataRecords,
        int $oldFileUid,
        mixed $identifier,
        int $storage
    ): bool {
        $this->output->writeln(sprintf(
            'Unduplicate sys_file: uid=%d identifier="%s", storage=%s (master uid=%d)',
            $oldFileUid,
            $identifier,
            $storage,
            $masterFileUid
        ));

        $oldMetadataRecords = $this->getMetadataRecords($oldFileUid);
        $this->checkDuplicateMetadataRecords($oldMetadataRecords);

        $deleteRecords = $this->metadataHandler->updateMetaData($masterFileUid, $masterMetadataRecords, $oldFileUid, $oldMetadataRecords);

        if ($deleteRecords) {
            $this->findAndUpdateReferences($masterFileUid, $oldFileUid);

            if ($this->output->isVerbose()) {
                $this->output->writeln("\t<comment>Deleting sys_file and processedFile records</comment>");
            }
            if (!$this->dryRun) {
                $this->deleteOldFileRecord($oldFileUid);
                $this->findAndDeleteOldProcessedFile($oldFileUid);
            }
        } else {
            if ($this->output->isVerbose()) {
                $this->output->writeln("\t<comment>Keeping sys_file and processedFile records</comment>");
            }
        }

        return !$deleteRecords;
    }

    private function getMetadataRecords(int $uid): array
    {
        $metadataQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $metadataQueryBuilder->addSelect('*');

        return $metadataQueryBuilder->from('sys_file_metadata')
            ->where(
                $metadataQueryBuilder->expr()->eq(
                    'file',
                    $metadataQueryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @throws Exception
     */
    private function findAndUpdateReferences(int $masterFileUid, int $oldFileUid): void
    {
        $referenceStatement = $this->getSysRefIndexData($oldFileUid);

        if (!$referenceStatement->rowCount()) {
            return;
        }

        $tableRows = [];
        while ($referenceRow = $referenceStatement->fetchAssociative()) {
            $tableRows[] = [
                $referenceRow['hash'],
                $referenceRow['tablename'],
                $referenceRow['recuid'],
                $referenceRow['field'],
                $referenceRow['softref_key'],
            ];

            if (!$this->dryRun) {
                $this->updateReferencedRecord($masterFileUid, $referenceRow);
                $this->updateReference($masterFileUid, $referenceRow);
            }
        }
        if ($this->output->isVerbose()) {
            $this->output->writeln(' -> <comment>Updated sys_refindex</comment>');
            $tableHeaders = [
                'hash',
                'tablename',
                'recuid',
                'field',
                'softref_key',
            ];
            $this->output->table($tableHeaders, $tableRows);
        }
    }

    public function getSysRefIndexData(int $oldFileUid): Result
    {
        $referenceQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $referenceExpr = $referenceQueryBuilder->expr();
        return $referenceQueryBuilder->select('*')
            ->from('sys_refindex')
            ->where(
                $referenceExpr->eq(
                    'ref_table',
                    $referenceQueryBuilder->createNamedParameter('sys_file')
                ),
                $referenceExpr->eq(
                    'ref_uid',
                    $referenceQueryBuilder->createNamedParameter($oldFileUid)
                ),
                $referenceExpr->neq(
                    'tablename',
                    $referenceQueryBuilder->createNamedParameter('sys_file_metadata')
                )
            )
            ->executeQuery();
    }

    private function updateReferencedRecord(int $masterFileUid, array $referenceRow): void
    {
        if (empty($referenceRow['softref_key'])) {
            $value = $masterFileUid;
        } else {
            $recordQueryBuilder = $this->connectionPool->getQueryBuilderForTable($referenceRow['tablename']);
            $recordQueryBuilder->getRestrictions()->removeAll();
            $record = $recordQueryBuilder->select($referenceRow['field'])
                ->from($referenceRow['tablename'])
                ->where(
                    $recordQueryBuilder->expr()->eq(
                        'uid',
                        $recordQueryBuilder->createNamedParameter($referenceRow['recuid'])
                    )
                )
                ->executeQuery()->fetchAssociative();

            $value = $record[$referenceRow['field']];

            // update file references
            $old = 't3://file?uid=' . $referenceRow['ref_uid'];
            $new = 't3://file?uid=' . $masterFileUid;
            $value = preg_replace('/' . preg_quote($old, '/') . '([^\d]|$)' . '/i', $new . '$1', (string)$value);

            // update rte_ckeditor_image references
            if (ExtensionManagementUtility::isLoaded('rte_ckeditor_image')) {
                // replace data-htmlarea-file-uid="312421"
                $old = 'data-htmlarea-file-uid="' . $referenceRow['ref_uid'] . '"';
                $new = 'data-htmlarea-file-uid="' . $masterFileUid . '"';
                $value = preg_replace('/' . preg_quote($old, '/') . '([^\d]|$)' . '/i', $new . '$1', $value);
            }
        }

        $recordUpdateQueryBuilder = $this->connectionPool->getQueryBuilderForTable($referenceRow['tablename']);
        $recordUpdateQueryBuilder->getRestrictions()->removeAll();
        $recordUpdateExpr = $recordUpdateQueryBuilder->expr();
        $recordUpdateQueryBuilder->update($referenceRow['tablename'])
            ->set($referenceRow['field'], $value)
            ->where(
                $recordUpdateExpr->eq(
                    'uid',
                    $recordUpdateQueryBuilder->createNamedParameter($referenceRow['recuid'], Connection::PARAM_INT)
                )
            )->executeStatement();
    }

    private function updateReference(int $masterFileUid, array $referenceRow): void
    {
        $referenceUpdateQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $referenceUpdateExpr = $referenceUpdateQueryBuilder->expr();
        $referenceUpdateQueryBuilder->update('sys_refindex')
            ->set('ref_uid', $masterFileUid)->where(
                $referenceUpdateExpr->eq(
                    'hash',
                    $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['hash'], Connection::PARAM_STR)
                ),
                $referenceUpdateExpr->eq(
                    'tablename',
                    $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['tablename'], Connection::PARAM_STR)
                ),
                $referenceUpdateExpr->eq(
                    'recuid',
                    $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['recuid'], Connection::PARAM_STR)
                ),
                $referenceUpdateExpr->eq(
                    'field',
                    $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['field'], Connection::PARAM_STR)
                ),
                $referenceUpdateExpr->eq(
                    'ref_table',
                    $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['ref_table'], Connection::PARAM_STR)
                ),
                $referenceUpdateExpr->eq(
                    'ref_uid',
                    $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['ref_uid'], Connection::PARAM_STR)
                )
            )->executeStatement();
    }

    private function deleteOldFileRecord(int $oldFileUid): void
    {
        $fileDeleteQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $fileDeleteQueryBuilder->delete('sys_file')
            ->where(
                $fileDeleteQueryBuilder->expr()->eq(
                    'uid',
                    $fileDeleteQueryBuilder->createNamedParameter($oldFileUid, Connection::PARAM_INT)
                )
            )
            ->executeStatement();
    }

    private function findAndDeleteOldProcessedFile(int $oldFileUid): void
    {
        $recordQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_processedfile');
        $results = $recordQueryBuilder->select('identifier', 'storage')
            ->from('sys_file_processedfile')
            ->where(
                $recordQueryBuilder->expr()->eq(
                    'original',
                    $recordQueryBuilder->createNamedParameter($oldFileUid)
                )
            )
            ->executeQuery();
        while ($record = $results->fetchAssociative()) {
            // delete each file from file system
            $this->deleteProcessedFile($record['identifier'], $record['storage']);
        }
        // delete all records in sys_file_processedfile
        $recordQueryBuilder->delete('sys_file_processedfile')
            ->where(
                $recordQueryBuilder->expr()->eq(
                    'original',
                    $recordQueryBuilder->createNamedParameter($oldFileUid, Connection::PARAM_INT)
                )
            )
            ->executeStatement();
    }

    private function deleteProcessedFile(mixed $identifier, int $storageId): void
    {
        if (empty($identifier) || empty($storageId)) {
            $this->output->writeln('<error>Empty identifier or storage id. Aborting delete of processed file</error>');
            return;
        }

        $storage = $this->storageRepository->getStorageObject($storageId);
        $storagePath = Environment::getPublicPath() . DIRECTORY_SEPARATOR . $storage->getRootLevelFolder()->getPublicUrl();
        $file = rtrim($storagePath, '/') . $identifier;

        $this->output->writeln('<info>Deleting processed file ' . $file . '</info>');

        if (file_exists($file)) {
            unlink($file);
            // delete all empty parent folders
            $dir = dirname($file);
            while ($dir !== $storagePath && count(scandir($dir)) === 2) {
                rmdir($dir);
                $dir = dirname($dir);
            }
        }
    }

    /**
     * Function to update the reference index
     * - if the option --update-refindex is set, do it
     * - otherwise, if in interactive mode (not having -n set), ask the user
     * - otherwise assume everything is fine
     *
     * @param InputInterface $input holds information about entered parameters
     * @param SymfonyStyle $io necessary for outputting information
     */
    protected function updateReferenceIndex(InputInterface $input)
    {
        // Check for reference index to update
        if ($input->hasOption('update-refindex') && $input->getOption('update-refindex')) {
            $updateReferenceIndex = true;
        } elseif ($input->isInteractive()) {
            $updateReferenceIndex = $this->output->confirm('Should the reference index be updated right now?', false);
        } else {
            $updateReferenceIndex = false;
        }

        // Update the reference index
        if ($updateReferenceIndex) {
            $progressListener = GeneralUtility::makeInstance(ReferenceIndexProgressListener::class);
            $progressListener->initialize($this->output);
            $referenceIndex = GeneralUtility::makeInstance(ReferenceIndex::class);
            $referenceIndex->updateIndex(false, $progressListener);
        } else {
            $this->output->note('Reference index is assumed to be up to date, continuing.');
        }
    }
}
