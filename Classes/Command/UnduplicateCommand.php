<?php

namespace ElementareTeilchen\Unduplicator\Command;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Result;
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
class UnduplicateCommand extends Command
{
    /**
     * @var ConnectionPool
     */
    private $connectionPool;

    /**
     * @var bool
     */
    private $dryRun = false;

    /**
     * @var String|bool
     */
    private $force = false;

    /**
     * @var bool
     */
    private $keepOldest = false;

    /**
     * @var SymfonyStyle
     */
    private $output;

    /**
     * @var array
     */
    private $fieldsToCheck = ['description'];

    public function __construct($name = null, ConnectionPool $connectionPool = null)
    {
        parent::__construct($name);
        $this->connectionPool = $connectionPool ?: GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * Configure the command by defining the name, options and arguments
     */
    public function configure()
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
     * Executes the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = new SymfonyStyle($input, $output);
        $this->output->title($this->getDescription());

        // Update the reference index
        $this->updateReferenceIndex($input);

        $this->dryRun = $input->getOption('dry-run');
        $this->force = $input->getOption('force'); // force will be null if no value is passed -> overwrite
        if ($this->force === null) {
            $this->force = 'overwrite';
        }
        $this->keepOldest = $input->getOption('keep-oldest');
        $onlyThisIdentifier = $input->getOption('identifier');
        $onlyThisStorage = (int)$input->getOption('storage');

        if ($input->hasArgument('meta-fields')) {
            $this->fieldsToCheck = array_map('trim', explode(',', $input->getOption('meta-fields')));
        } elseif (ExtensionManagementUtility::isLoaded('filemetadata')) {
            // add default values in case the filemetadata extension is loaded
            $this->fieldsToCheck = array_merge($this->fieldsToCheck, ['caption', 'copyright']);
        }

        $this->output->writeln('<info>Using metadata fields: ' . implode(', ', $this->fieldsToCheck) . '</info>');

        $statement = $this->findDuplicates($onlyThisIdentifier, $onlyThisStorage);
        $foundDuplicates = 0;
        while ($row = $statement->fetchAssociative()) {
            $identifier = $row['identifier'] ?? '';
            if ($identifier === '') {
                $this->output->warning('Found empty identifier');
                continue;
            }
            $storage = (int)$row['storage'];

            $files = $this->findDuplicateFilesForIdentifier($identifier, $storage);
            $masterFileUid = null;
            $masterFileIdentifier = null;
            foreach ($files as $fileRow) {
                $identifier = $fileRow['identifier'];
                // save uid and identifier of master entry (sort descending by uid), which we want to keep
                if ($masterFileUid === null) {
                    $masterFileIdentifier = $identifier;
                    $masterFileUid = $fileRow['uid'];
                    continue;
                }
                if ($masterFileIdentifier !== $identifier) {
                    // identifier is not the same, skip this one (may happen because of case-insensitive DB queries)
                    continue;
                }
                $foundDuplicates++;

                $this->processDuplicate($masterFileUid, $fileRow['uid'], $identifier, $storage);
            }
        }
        if (!$foundDuplicates) {
            $this->output->success('No duplicates found');
        }
        return 0;
    }

    /**
     * Uses GROUP BY BINARY identifier,storage to make sure we don't get results for identifiers which are only duplicate
     * if checked case-insensitively.
     *
     * Database may be case-insensitive, e.g. charset 'utf8mb5', collation 'utf8mb4_unicode_ci'.
     *
     * @param mixed $onlyThisIdentifier
     * @param int $onlyThisStorage
     * @return Result
     */
    public function findDuplicates(mixed $onlyThisIdentifier, int $onlyThisStorage): Result
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->count('*')
            ->from('sys_file')
            ->having('COUNT(*) > 1');
        $whereExpressions = [];
        if ($onlyThisIdentifier) {
            $whereExpressions[] = $queryBuilder->expr()->eq(
                'identifier',
                $queryBuilder->createNamedParameter($onlyThisIdentifier, \PDO::PARAM_STR)
            );
        }
        if ($onlyThisStorage > -1) {
            $whereExpressions[] = $queryBuilder->expr()->eq(
                'storage',
                $queryBuilder->createNamedParameter($onlyThisStorage, Connection::PARAM_INT)
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

        $this->output->writeln('sql=' . $queryBuilder->getSQL(), OutputInterface::VERBOSITY_VERBOSE);

        $statement = $queryBuilder
            ->executeQuery();
        return $statement;
    }

    /**
     * Must make sure we compare identifier case-sensitively, so using "BINARY identifier" here .
     *
     * Database may be case-insensitive, e.g. charset 'utf8mb5', collation 'utf8mb4_unicode_ci'.
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
                )
            )->orderBy('uid', $this->keepOldest ? 'ASC' : 'DESC');

        $whereClause = 'MD5(identifier) = MD5(' . $fileQueryBuilder->createNamedParameter($identifier, \PDO::PARAM_STR) . ')';
        $fileQueryBuilder->add('where', $whereClause);

        return $fileQueryBuilder->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param int $masterFileUid
     * @param int $oldFileUid
     * @param mixed $identifier
     * @param int $storage
     * @throws Exception
     */
    public function processDuplicate(int $masterFileUid, int $oldFileUid, mixed $identifier, int $storage): void
    {
        $this->output->writeln(sprintf(
            'Unduplicate sys_file: uid=%d identifier="%s", storage=%s (master uid=%d)',
            $oldFileUid,
            $identifier,
            $storage,
            $masterFileUid
        ));

        $deleteRecords = $this->findAndUpdateReferences($masterFileUid, $oldFileUid);
        if ($deleteRecords) {
            if ($this->output->isVerbose()) {
                $this->output->writeln('<comment>Deleting sys_file and processedFile records</comment>');
            }
            if (!$this->dryRun) {
                $this->deleteOldFileRecord($oldFileUid);
                $this->findAndDeleteOldProcessedFile($oldFileUid);
            }
        } else {
            if ($this->output->isVerbose()) {
                $this->output->writeln('<comment>Keeping sys_file and processedFile records</comment>');
            }
        }
    }

    /**
     * @param int $masterFileUid
     * @param int $oldFileUid
     * @return bool
     * @throws Exception
     */
    private function findAndUpdateReferences(int $masterFileUid, int $oldFileUid): bool
    {
        $referenceStatement = $this->getSysRefIndexData($oldFileUid);

        if (!$referenceStatement->rowCount()) {
            return true;
        }

        $deleteRecords = true;
        $tableHeaders = [
            'hash',
            'tablename',
            'recuid',
            'field',
            'softref_key',
        ];
        $tableRows = null;
        while ($referenceRow = $referenceStatement->fetchAssociative()) {
            $tableRows[] = [
                $referenceRow['hash'],
                $referenceRow['tablename'],
                $referenceRow['recuid'],
                $referenceRow['field'],
                $referenceRow['softref_key'],
            ];

            if ($referenceRow['tablename'] === 'sys_file_metadata'
                && $this->metadataRecordExists($masterFileUid)
            ) {
                $deleteRecords = $this->updateMetadataRecord($masterFileUid, $referenceRow);
            } else {
                if (!$this->dryRun) {
                    $this->updateReferencedRecord($masterFileUid, $referenceRow);
                    $this->updateReference($masterFileUid, $referenceRow);
                }
            }
        }
        $this->output->table($tableHeaders, $tableRows);
        return $deleteRecords;
    }

    public function getSysRefIndexData(int $oldFileUid): Result
    {
        $referenceQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $referenceExpr = $referenceQueryBuilder->expr();
        $referenceStatement = $referenceQueryBuilder->select('*')
            ->from('sys_refindex')
            ->where(
                $referenceExpr->eq(
                    'ref_table',
                    $referenceQueryBuilder->createNamedParameter('sys_file')
                ),
                $referenceExpr->eq(
                    'ref_uid',
                    $referenceQueryBuilder->createNamedParameter($oldFileUid)
                )
            )
            ->executeQuery();
        return $referenceStatement;
    }

    public function updateMetadataRecord(int $masterFileUid, array $referenceRow): bool
    {

        $oldMetadata = $this->isMetadataRecordPopulated($referenceRow['ref_uid']);
        $masterFileMetadata = $this->isMetadataRecordPopulated($masterFileUid);
        $masterEmoty = !$masterFileMetadata;

        if (!$oldMetadata || $oldMetadata === $masterFileMetadata) { // check if record is empty or if the values are the same as in master
            $this->output->writeln('<info>Deleting old metadata record</info>');

            if (!$this->dryRun) {
                $this->deleteReferencedRecord($referenceRow);
                $this->deleteReference($referenceRow);
            }

        } elseif ($oldMetadata && ($masterEmoty || $this->force !== false)) { // check if master record has metadata, if not, copy the old ones

            if ($masterEmoty) {
                $this->output->writeln('<info>Old metadata is not empty and master is empty, copying values to master. Deleting old metadata record</info>');
            } else if ($this->force !== false) {
                if ($this->force !== 'keep') {
                    $this->output->writeln('<info>Force overwriting metadata in master. Deleting old metadata record</info>');
                } else {
                    $this->output->writeln('<info>Force keeping metadata in master. Deleting old metadata record. Action may be required.</info>');
                }
            }

            if (!$this->dryRun) {
                if ($this->force === false ||
                    $this->force === 'overwrite' ||
                    $masterEmoty && $this->force === 'keep-nonempty') {
                    $this->updateMasterFileMetadata($masterFileUid, $oldMetadata);
                }
                $this->deleteReferencedRecord($referenceRow);
                $this->deleteReference($referenceRow);
            }
        } else {
            $this->output->writeln('<error>Old metadata record is not empty and conflicts with the master data. Not deleting these records. Action required.</error>');
            return false;
        }
        return true;
    }

    private function updateReferencedRecord(int $masterFileUid, array $referenceRow)
    {
        if (empty($referenceRow['softref_key'])) {
            $value = $masterFileUid;
        } else {
            $recordQueryBuilder = $this->connectionPool->getQueryBuilderForTable($referenceRow['tablename']);
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
            $value = preg_replace('/' . preg_quote($old, '/') . '([^\d]|$)' . '/i', $new . '\1', $value);

            // update rte_ckeditor_image references
            if (ExtensionManagementUtility::isLoaded('rte_ckeditor_image')) {
                // replace data-htmlarea-file-uid="312421"
                $old = 'data-htmlarea-file-uid="' . $referenceRow['ref_uid'] . '"';
                $new = 'data-htmlarea-file-uid="' . $masterFileUid . '"';
                $value = preg_replace('/' . preg_quote($old, '/') . '([^\d]|$)' . '/i', $new . '\1', $value);
            }
        }

        $recordUpdateQueryBuilder = $this->connectionPool->getQueryBuilderForTable($referenceRow['tablename']);
        $recordUpdateExpr = $recordUpdateQueryBuilder->expr();
        $recordUpdateQueryBuilder->update($referenceRow['tablename'])
            ->set($referenceRow['field'], $value)
            ->where(
                $recordUpdateExpr->eq(
                    'uid',
                    $recordUpdateQueryBuilder->createNamedParameter($referenceRow['recuid'], \PDO::PARAM_INT)
                )
            )->executeStatement();
    }

    private function updateReference(int $masterFileUid, array $referenceRow)
    {
        $referenceUpdateQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $referenceUpdateExpr = $referenceUpdateQueryBuilder->expr();
        $referenceUpdateQueryBuilder->update('sys_refindex')
            ->set('ref_uid', $masterFileUid)->where($referenceUpdateExpr->eq(
                'hash',
                $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['hash'], \PDO::PARAM_STR)
            ), $referenceUpdateExpr->eq(
                'tablename',
                $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['tablename'], \PDO::PARAM_STR)
            ), $referenceUpdateExpr->eq(
                'recuid',
                $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['recuid'], \PDO::PARAM_STR)
            ), $referenceUpdateExpr->eq(
                'field',
                $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['field'], \PDO::PARAM_STR)
            ), $referenceUpdateExpr->eq(
                'ref_table',
                $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['ref_table'], \PDO::PARAM_STR)
            ), $referenceUpdateExpr->eq(
                'ref_uid',
                $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['ref_uid'], \PDO::PARAM_STR)
            ))->executeStatement();
    }

    private function deleteReferencedRecord(array $referenceRow)
    {
        $recordDeleteQueryBuilder = $this->connectionPool->getQueryBuilderForTable($referenceRow['tablename']);
        $recordDeleteQueryBuilder->delete($referenceRow['tablename'])
            ->where(
                $recordDeleteQueryBuilder->expr()->eq(
                    'uid',
                    $recordDeleteQueryBuilder->createNamedParameter($referenceRow['recuid'], \PDO::PARAM_INT)
                )
            )
            ->executeStatement();
    }

    private function deleteReference(array $referenceRow)
    {
        $referenceDeleteQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $referenceDeleteExpr = $referenceDeleteQueryBuilder->expr();
        $referenceDeleteQueryBuilder->delete('sys_refindex')->where($referenceDeleteExpr->eq(
            'hash',
            $referenceDeleteQueryBuilder->createNamedParameter($referenceRow['hash'], \PDO::PARAM_STR)
        ), $referenceDeleteExpr->eq(
            'tablename',
            $referenceDeleteQueryBuilder->createNamedParameter($referenceRow['tablename'], \PDO::PARAM_STR)
        ), $referenceDeleteExpr->eq(
            'recuid',
            $referenceDeleteQueryBuilder->createNamedParameter($referenceRow['recuid'], \PDO::PARAM_STR)
        ), $referenceDeleteExpr->eq(
            'field',
            $referenceDeleteQueryBuilder->createNamedParameter($referenceRow['field'], \PDO::PARAM_STR)
        ), $referenceDeleteExpr->eq(
            'ref_table',
            $referenceDeleteQueryBuilder->createNamedParameter($referenceRow['ref_table'], \PDO::PARAM_STR)
        ), $referenceDeleteExpr->eq(
            'ref_uid',
            $referenceDeleteQueryBuilder->createNamedParameter($referenceRow['ref_uid'], \PDO::PARAM_STR)
        ))->executeStatement();
    }

    private function metadataRecordExists(int $masterFileUid): bool
    {
        $metadataQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $count = $metadataQueryBuilder->count('*')
            ->from('sys_file_metadata')
            ->where(
                $metadataQueryBuilder->expr()->eq(
                    'file',
                    $metadataQueryBuilder->createNamedParameter($masterFileUid, \PDO::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchOne();

        return $count > 0;
    }

    private function deleteOldFileRecord(int $oldFileUid)
    {
        $fileDeleteQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $fileDeleteQueryBuilder->delete('sys_file')
            ->where(
                $fileDeleteQueryBuilder->expr()->eq(
                    'uid',
                    $fileDeleteQueryBuilder->createNamedParameter($oldFileUid, \PDO::PARAM_INT)
                )
            )
            ->executeStatement();
    }

    private function markOldFileReferenceRecordDeleted(int $oldFileUid)
    {
        $fileDeleteQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $fileDeleteQueryBuilder->update('sys_file_reference')
            ->set('deleted', 1)
            ->where(
                $fileDeleteQueryBuilder->expr()->eq(
                    'uid_local',
                    $fileDeleteQueryBuilder->createNamedParameter($oldFileUid, \PDO::PARAM_INT)
                )
            )
            ->executeStatement();
    }

    private function findAndDeleteOldProcessedFile(int $oldFileUid): void
    {
        $recordQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_processedfile');
        $results = $recordQueryBuilder->select('identifier')
            ->from('sys_file_processedfile')
            ->where(
                $recordQueryBuilder->expr()->eq(
                    'original',
                    $recordQueryBuilder->createNamedParameter($oldFileUid)
                )
            )
            ->executeQuery();
        while ($record = $results->fetchAssociative()) {
            if(empty($record['identifier'])) {
                continue;
            }
            // delete each file from file system
            $this->output->writeln('<info>Deleting processed file ' . $record['identifier'] . '</info>');
            $this->deleteProcessedFile($record['identifier']);
        }
        // delete all records in sys_file_processedfile
        $recordQueryBuilder->delete('sys_file_processedfile')
            ->where(
                $recordQueryBuilder->expr()->eq(
                    'original',
                    $recordQueryBuilder->createNamedParameter($oldFileUid, \PDO::PARAM_INT)
                )
            )
            ->executeStatement();
    }

    private function deleteProcessedFile(mixed $identifier): void
    {
        $file = Environment::getPublicPath() . '/fileadmin' . $identifier;
        if (file_exists($file)) {
            unlink($file);
            // delete all empty parent folders
            $dir = dirname($file);
            while ($dir !== Environment::getPublicPath() . '/fileadmin' && count(scandir($dir)) === 2) {
                rmdir($dir);
                $dir = dirname($dir);
            }
        }
    }

    private function isMetadataRecordPopulated(int $fileId): bool|array
    {
        $metadataQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        foreach ($this->fieldsToCheck as $field) {
            $metadataQueryBuilder->addSelect($field);
        }
        $metadata = $metadataQueryBuilder->from('sys_file_metadata')
            ->where(
                $metadataQueryBuilder->expr()->eq(
                    'file',
                    $metadataQueryBuilder->createNamedParameter($fileId, \PDO::PARAM_INT)
                )
            )
            ->executeQuery()->fetchAssociative();

        // check if the fields are empty
        foreach ($this->fieldsToCheck as $field) {
            if (!empty($metadata[$field])) {
                return $metadata;
            }
        }
        return false;
    }

    private function updateMasterFileMetadata(int $masterFileUid, bool|array $metadata)
    {
        $metadataUpdateQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $metadataUpdateQueryBuilder->update('sys_file_metadata');
        foreach ($this->fieldsToCheck as $field) {
            if (!isset($metadata[$field])) {
                $this->output->writeln('<warning>Field \'' . $field . '\' does not exist, skipping</warning>');
                continue;
            }
            $metadataUpdateQueryBuilder->set($field, $metadata[$field]);
        }
        $metadataUpdateQueryBuilder->where(
            $metadataUpdateQueryBuilder->expr()->eq(
                'file',
                $metadataUpdateQueryBuilder->createNamedParameter($masterFileUid, \PDO::PARAM_INT)
            )
        )
            ->executeStatement();
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
