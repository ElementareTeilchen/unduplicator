<?php

declare(strict_types=1);

namespace ElementareTeilchen\Unduplicator\Metadata;

use Doctrine\DBAL\Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

class MetadataUpdateHandler
{
    private string|bool $force = false;

    public function __construct(
        private readonly bool $dryRun,
        private readonly InputInterface $input,
        private readonly SymfonyStyle $output,
        private readonly array $fieldsToCheck,
        private readonly ConnectionPool $connectionPool
    ) {
        $this->force = $input->getOption('force'); // force will be null if no value is passed -> overwrite
        if ($this->force === null) {
            $this->force = 'overwrite';
        }
    }

    /**
     * @throws Exception
     */
    public function updateMetadata(
        int $masterFileUid,
        array $masterMetadataRecords,
        int $oldFileUid,
        array $oldMetadataRecords
    ): bool {

        $deleteRecords = true;
        // iterate over all languages
        foreach ($oldMetadataRecords as $oldMetadata) {

            $masterMetadata = [];
            foreach ($masterMetadataRecords as $metadata) {
                if ($metadata['sys_language_uid'] === $oldMetadata['sys_language_uid']) {
                    $masterMetadata = $metadata;
                    break;
                }
            }

            $metadataObject = new MetadataUpdateObject(
                $masterFileUid,
                $masterMetadata,
                $oldMetadata,
                $this->fieldsToCheck
            );

            $deleteRecords = $this->updateMetadataRecord($metadataObject) && $deleteRecords;
        }

        return $deleteRecords;
    }

    public function updateMetadataRecord(MetadataUpdateObject $metadata): bool
    {
        if ($metadata->isOldEmtpyt() || $metadata->isOldSameAsMaster()) { // check if record is empty or if the values are the same as in master

            if ($this->output->isVerbose()) {
                $this->output->writeln("\t<info>Old metadata " . $metadata->getOldUid() . ' is empty or same as in master for sys_language_uid ' . $metadata->getLanguageUid() . '</info>');
            }

            $this->metadataHandleNoUpdate($metadata->getLanguageUid(), $metadata->getOldUid());

        } elseif (!$metadata->isOldEmtpyt() && ($metadata->isMasterEmpty() || $this->force !== false)) { // check if master record has metadata, if not, copy the old ones

            if ($this->output->isVerbose()) {
                if ($metadata->isMasterEmpty()) {
                    $this->output->writeln("\t<info>Master metadata " . $metadata->getMasterUid() . ' is empty for sys_language_uid ' . $metadata->getLanguageUid() . '</info>');
                } elseif ($this->force !== false) {
                    if ($this->force !== 'keep') {
                        $this->output->writeln("\t<info>Force overwriting metadata in master.</info>");
                    } else {
                        $this->output->writeln("\t<info>Force keeping metadata in master.</info>");
                    }
                }
            }

            $this->metadataHandleUpdate($metadata);

        } else {

            return $this->metadataHandleConflict($metadata);
        }
        return true;
    }

    /**
     * @param $languageUid
     */
    public function metadataHandleNoUpdate($languageUid, mixed $oldUid): void
    {
        if ($this->output->isVerbose()) {
            $this->output->writeln("\t -> <info>Deleting old metadata record</info>");
        }

        if (!$this->dryRun) {
            $this->deleteMetadataRecord($oldUid);
            $this->deleteMetadataReference($oldUid);
        }
    }

    public function metadataHandleUpdate(MetadataUpdateObject $metadata): void
    {

        if ($this->force === false ||
            $this->force === 'overwrite' ||
            $metadata->isMasterEmpty() && $this->force === 'keep-nonempty') {

            if ($this->output->isVerbose()) {
                $this->output->writeln("\t -> <info>" . ($metadata->getMasterUid() ? 'Updating' : 'Creating') . ' master metadata record</info>');
            }

            if (!$this->dryRun) {
                if ($metadata->hasMaster()) {
                    $this->updateMasterMetadata($metadata->getMasterUid(), $metadata->getOldMetadata());
                } else {
                    $this->createMasterMetadata($metadata->getMasterFileUid(), $metadata->getOldMetadata());
                }
            }
        }

        if ($this->output->isVerbose()) {
            $this->output->writeln("\t -> <info>Deleting old metadata record " . $metadata->getOldUid() . '</info>');
        }

        if (!$this->dryRun) {
            $this->deleteMetadataRecord($metadata->getOldUid());
            $this->deleteMetadataReference($metadata->getOldUid());
        }
    }

    public function metadataHandleConflict(MetadataUpdateObject $metadata): bool
    {
        $interactive = $this->input->getOption('interactive');

        $this->output->writeln("\t<error>Old metadata " . $metadata->getOldUid() . ' with sys_language_uid ' . $metadata->getLanguageUid() . ' is not empty and conflicts with the master data. ' . ($interactive ? 'Please choose what to do.' : 'Not deleting this record') . '.</error>');
        if ($this->output->isVerbose()) {
            $this->output->writeln("\t -> Old metadata   : <comment>" . json_encode($metadata->getOldClean()) . '</comment>');
            $this->output->writeln("\t -> Master metadata: <comment>" . json_encode($metadata->getMasterClean()) . '</comment>');
        }

        if ($interactive) {
            while (true) {
                switch ($this->output->ask('<info>Keep OLD or MASTER metadata or SKIP record [o,m,s,?]?</info> ', '?')) {
                    case 'o':
                        $this->output->writeln("\t<info>Keeping OLD metadata</info>");
                        $this->metadataHandleUpdate($metadata);
                        return true;
                    case 'm':
                        $this->output->writeln("\t<info>Keeping MASTER metadata</info>");
                        $this->metadataHandleNoUpdate($metadata->getLanguageUid(), $metadata->getOldUid());
                        return true;
                    case 's':
                        $this->output->writeln("\t<info>Skipping record. Not deleting any duplicate records related to file " . $metadata->getMasterFileUid() . '</info>');
                        return false;
                    case 'h':
                    default:
                        $this->output->text([
                            '    o - keep OLD metadata record',
                            '    m - keep MASTER metadata record',
                            '    s - SKIP handling of record for now',
                            '    ? - HELP',
                        ]);
                        break;
                }
            }
        }

        return false;
    }

    private function updateMasterMetadata(int $masterMetadataUid, array $metadata): void
    {
        $metadataUpdateQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $metadataUpdateQueryBuilder->update('sys_file_metadata');
        foreach ($this->fieldsToCheck as $field) {
            if (!isset($metadata[$field])) {
                $this->output->writeln("\t\t<warning>Field \'" . $field . "\' does not exist</warning>");
                continue;
            }
            $metadataUpdateQueryBuilder->set($field, $metadata[$field]);
        }
        $metadataUpdateQueryBuilder->where(
            $metadataUpdateQueryBuilder->expr()->eq(
                'uid',
                $metadataUpdateQueryBuilder->createNamedParameter($masterMetadataUid, Connection::PARAM_INT)
            )
        )
            ->executeStatement();
    }

    private function createMasterMetadata(int $masterFileUid, array $metadata): void
    {
        unset($metadata['uid']);
        $metadata['file'] = $masterFileUid;
        $metadataUpdateQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $metadataUpdateQueryBuilder->insert('sys_file_metadata')
            ->values($metadata)
            ->executeStatement();
    }

    public function deleteMetadataRecord(int $uid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        return $queryBuilder
            ->delete('sys_file_metadata')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                )
            )
            ->executeStatement();
    }

    private function deleteMetadataReference(int $uid): void
    {
        $referenceDeleteQueryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $referenceDeleteExpr = $referenceDeleteQueryBuilder->expr();
        $referenceDeleteQueryBuilder
            ->delete('sys_refindex')
            ->where(
                $referenceDeleteExpr->eq(
                    'tablename',
                    $referenceDeleteQueryBuilder->createNamedParameter('sys_file_metadata', Connection::PARAM_STR)
                ),
                $referenceDeleteExpr->eq(
                    'recuid',
                    $referenceDeleteQueryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                )
            )->executeStatement();
    }
}
