<?php
namespace ElementareTeilchen\Unduplicator\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
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

 * @package sysfile_unduplicator
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
     * @var SymfonyStyle
     */
    private $output;

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
        $this->setDescription('Finds duplicates in sys_file and unduplicates them');
        $this->setHelp('currently fix references in ' . LF .
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
            null,
            InputOption::VALUE_NONE,
            'If set, all database updates are not executed'
        )
        ->addOption(
                'identifier',
                null,
                InputOption::VALUE_REQUIRED,
                'Only use this identifier'
        )
        ->addOption(
            'storage',
            null,
            InputOption::VALUE_REQUIRED,
            'Only use this storage',
            -1
        );
    }

    /**
     * Executes the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = new SymfonyStyle($input, $output);
        $this->output->title($this->getDescription());

        $this->dryRun = $input->getOption('dry-run');
        $onlyThisIdentifier = $input->getOption('identifier');
        $onlyThisStorage = (int) $input->getOption('storage') ;

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->count('*')
            ->addSelect('identifier', 'storage')
            ->from('sys_file')
            ->groupBy('identifier', 'storage')
            ->having('COUNT(*) > 1');
        $whereExpressions = [];
        if ($onlyThisIdentifier) {
            $whereExpressions[] = $queryBuilder->expr()->eq(
                    'identifier', $queryBuilder->createNamedParameter($onlyThisIdentifier, \PDO::PARAM_STR)
            );
        }
        if ($onlyThisStorage > -1) {
            $whereExpressions[] = $queryBuilder->expr()->eq(
                    'storage', $queryBuilder->createNamedParameter($onlyThisStorage, Connection::PARAM_INT)
            );
        }
        if ($whereExpressions) {
            $queryBuilder->where(...$whereExpressions);
        }
        $statement = $queryBuilder
            ->execute();

        if (!$statement->rowCount()) {
            return 0;
        }

        while ($row = $statement->fetchAssociative()) {
            $identifier = $row['identifier'] ?? '';
            if ($identifier === '') {
                $this->output->warning('Found empty identifier');
                continue;
            }
            $storage = (int) $row['storage'];

            $files = $this->findDuplicateFilesForIdentifier($identifier, $storage);
            $originalUid = null;
            $originalIdentifier = null;
            foreach ($files as $fileRow) {
                $identifier = $fileRow['identifier'];
                // save uid and identifier of first entry (sort descending by uid, is newest)
                if ($originalUid === null) {
                    $originalIdentifier = $identifier;
                    $originalUid = $fileRow['uid'];
                    continue;
                }
                if ($originalIdentifier !== $identifier) {
                    // identifier is not the same, skip this one (may happen because of case-insensitive DB queries)
                    continue;
                }

                $oldFileUid = (int)$fileRow['uid'];
                $this->output->writeln(sprintf('Unduplicate sys_file: uid=%d identifier="%s", storage=%s (keep uid=%d)',
                    $oldFileUid, $identifier, $storage, $originalUid));
                if (!$this->dryRun) {
                    $this->findAndUpdateReferences($originalUid, $oldFileUid);
                    $this->deleteOldFileRecord($fileRow['uid']);
                }
            }
        }
        return 1;
    }

    private function findDuplicateFilesForIdentifier(string $identifier, int $storage): array
    {
        $fileQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');

        return $fileQueryBuilder->select('uid', 'identifier')
            ->from('sys_file')
            ->where(
                $fileQueryBuilder->expr()->eq(
                    'identifier',
                    $fileQueryBuilder->createNamedParameter($identifier, \PDO::PARAM_STR)
                ),
                $fileQueryBuilder->expr()->eq(
                    'storage',
                    $fileQueryBuilder->createNamedParameter($storage, Connection::PARAM_INT)
                )
            )
            ->orderBy('uid', 'DESC')
            ->execute()
            ->fetchAllAssociative();
    }

    private function findAndUpdateReferences(int $originalUid, int $oldFileUid)
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
            ->execute();

        if (!$referenceStatement->rowCount()) {
            return;
        }

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

            if (!$this->dryRun) {
                if ($referenceRow['tablename'] === 'sys_file_metadata'
                    && $this->metadataRecordExists($originalUid)
                ) {
                    $this->deleteReferencedRecord($referenceRow);
                    $this->deleteReference($referenceRow);
                } else {
                    $this->updateReferencedRecord($originalUid, $referenceRow);
                    $this->updateReference($originalUid, $referenceRow);
                }
            }
        }
        $this->output->table($tableHeaders, $tableRows);
    }

    private function updateReferencedRecord(int $originalUid, array $referenceRow)
    {
        if (empty($referenceRow['softref_key'])) {
            $value = $originalUid;
        } else {
            $old = 't3://file?uid=' . $referenceRow['ref_uid'];
            $new = 't3://file?uid=' . $originalUid;
            $recordQueryBuilder = $this->connectionPool->getQueryBuilderForTable($referenceRow['tablename']);
            $record = $recordQueryBuilder->select($referenceRow['field'])
                ->from($referenceRow['tablename'])
                ->where(
                    $recordQueryBuilder->expr()->eq(
                        'uid',
                        $recordQueryBuilder->createNamedParameter($referenceRow['recuid'])
                    )
                )
                ->execute()
                ->fetchAssociative();
            $value = preg_replace('/' . preg_quote($old, '/') . '([^\d]|$)' . '/i', $new . '\1', $record[$referenceRow['field']]);
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
            )
            ->execute();
    }

    private function updateReference(int $originalUid, array $referenceRow)
    {
        $referenceUpdateQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $referenceUpdateExpr = $referenceUpdateQueryBuilder->expr();
        $referenceUpdateQueryBuilder->update('sys_refindex')
            ->set('ref_uid', $originalUid)
            ->where(
                $referenceUpdateExpr->eq(
                    'hash',
                    $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['hash'], \PDO::PARAM_STR)
                ),
                $referenceUpdateExpr->eq(
                    'tablename',
                    $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['tablename'], \PDO::PARAM_STR)
                ),
                $referenceUpdateExpr->eq(
                    'recuid',
                    $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['recuid'], \PDO::PARAM_STR)
                ),
                $referenceUpdateExpr->eq(
                    'field',
                    $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['field'], \PDO::PARAM_STR)
                ),
                $referenceUpdateExpr->eq(
                    'ref_table',
                    $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['ref_table'], \PDO::PARAM_STR)
                ),
                $referenceUpdateExpr->eq(
                    'ref_uid',
                    $referenceUpdateQueryBuilder->createNamedParameter($referenceRow['ref_uid'], \PDO::PARAM_STR)
                )
            )
            ->execute();
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
            ->execute();
    }

    private function deleteReference(array $referenceRow)
    {
        $referenceDeleteQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $referenceDeleteExpr = $referenceDeleteQueryBuilder->expr();
        $referenceDeleteQueryBuilder->delete('sys_refindex')
            ->where(
                $referenceDeleteExpr->eq(
                    'hash',
                    $referenceDeleteQueryBuilder->createNamedParameter($referenceRow['hash'], \PDO::PARAM_STR)
                ),
                $referenceDeleteExpr->eq(
                    'tablename',
                    $referenceDeleteQueryBuilder->createNamedParameter($referenceRow['tablename'], \PDO::PARAM_STR)
                ),
                $referenceDeleteExpr->eq(
                    'recuid',
                    $referenceDeleteQueryBuilder->createNamedParameter($referenceRow['recuid'], \PDO::PARAM_STR)
                ),
                $referenceDeleteExpr->eq(
                    'field',
                    $referenceDeleteQueryBuilder->createNamedParameter($referenceRow['field'], \PDO::PARAM_STR)
                ),
                $referenceDeleteExpr->eq(
                    'ref_table',
                    $referenceDeleteQueryBuilder->createNamedParameter($referenceRow['ref_table'], \PDO::PARAM_STR)
                ),
                $referenceDeleteExpr->eq(
                    'ref_uid',
                    $referenceDeleteQueryBuilder->createNamedParameter($referenceRow['ref_uid'], \PDO::PARAM_STR)
                )
            )
            ->execute();
    }

    private function metadataRecordExists(int $originalUid): bool
    {
        $metadataQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $count = $metadataQueryBuilder->count('*')
            ->from('sys_file_metadata')
            ->where(
                $metadataQueryBuilder->expr()->eq(
                    'file',
                    $metadataQueryBuilder->createNamedParameter($originalUid, \PDO::PARAM_INT)
                )
            )
            ->execute()
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
            ->execute();
    }
}
