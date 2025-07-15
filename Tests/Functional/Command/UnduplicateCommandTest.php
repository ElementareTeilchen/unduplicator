<?php

declare(strict_types=1);

namespace ElementareTeilchen\Unduplicator\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class UnduplicateCommandTest extends FunctionalTestCase
{

    const BASE_COMMAND = 'unduplicate:sysfile -n';

    #[Test] public function unduplicateCommandReturnsZeroIfNoDuplicates(): void
    {
        $result = $this->executeConsoleCommand(self::BASE_COMMAND);
        self::assertEquals(0, $result['status']);
    }

    #[Test] public function unduplicateCommandIgnoresNonDuplicates(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_non_duplicates.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND);

        // Should be no changes in the DB
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_non_duplicates.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test] public function unduplicateCommandIgnoresNonDuplicatesByCase(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_non_duplicates_case_sensitive.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND);
        // Should be no changes in the DB
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_non_duplicates_case_sensitive.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test] public function unduplicateCommandFixesDuplicates(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND);

        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    /**
     * * abc.jpg
     * * ABC.jpg
     * * ABC.jpg
     * should remove one ABC.jpg
     */
    #[Test] public function unduplicateCommandFixesDuplicatesWithMixCaseSensitives(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_mix_casesensitive.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND);

        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_mix_casesensitive_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test] public function unduplicateCommandFixesDuplicatesWithReferences(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_references.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND);

        // the references are updated, so that the newer sys_file entry (uid=2) is used
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_references_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test] public function unduplicateCommandFixesDuplicatesWithReferencesDeleted(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_references_deleted.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND);

        // the references are updated, so that the newer sys_file entry (uid=2) is used
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_references_deleted_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }


    #[Test] public function unduplicateCommandFixesDuplicatesWithMetadata(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_metadata.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND);

        // the references are updated, so that the newer sys_file entry (uid=2) is used
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_metadata_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test] public function unduplicateCommandKeepOldestWithMetadata(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_metadata.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' --keep-oldest');

        // the references are updated, so that the newer sys_file entry (uid=2) is used
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_metadata_oldest_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test] public function unduplicateCommandForceOverwriteWithMetadata(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_metadata.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' --force');

        // the references are updated, so that the newer sys_file entry (uid=2) is used
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_metadata_force_overwrite_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test] public function unduplicateCommandForceKeepWithMetadata(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_metadata.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' --force keep');

        // the references are updated, so that the newer sys_file entry (uid=2) is used
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_metadata_force_keep_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    #[Test] public function unduplicateCommandForceKeepNonEmptyWithMetadata(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_metadata.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND . ' --force keep-nonempty');

        // the references are updated, so that the newer sys_file entry (uid=2) is used
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_metadata_force_keep_nonempty_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }

    /**
     * Provide a processed file for the test run, so that it can be deleted
     * @var array<string, string>
     */
    protected array $pathsToProvideInTestInstance = [
        'typo3/sysext/frontend/Resources/Public/Icons/Extension.svg' => 'fileadmin/_processed_/3/c/csm_myfile_975bcb8fba.jpg',
    ];

    #[Test] public function unduplicateCommandFixesDuplicatesWithProcessedFiles(): void
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_processed_files.csv');

        $result = $this->executeConsoleCommand(self::BASE_COMMAND);

        // the processed files are updated, so that the newer sys_file entry (uid=2) is used
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_processed_files_RESULT.csv');
        self::assertEquals(0, $result['status']);
    }


    /**
     * based on TYPO3\CMS\Core\Tests\Functional\Command\AbstractCommandTest::executeConsoleCommand
     *   we had to change path for typo3 command because EXT:core/bin/typo3 does not exist in Composer installation
     */
    protected function executeConsoleCommand(string $cmdline, ...$args): array
    {
        $typo3File = __DIR__ . '/../../../.Build/bin/typo3';
        if (!file_exists($typo3File)) {
            throw new RuntimeException(
                sprintf('Executable file <typo3> not found (using path <%s>). Make sure config:bin-dir is set to .Build/bin in composer.json', $typo3File)
            );
        }

        $cmd = vsprintf(PHP_BINARY . ' ' . $typo3File
            . ' ' . $cmdline, array_map(escapeshellarg(...), $args));

        $output = '';

        $handle = popen($cmd, 'r');
        while (!feof($handle)) {
            $output .= fgets($handle, 4096);
        }
        $status = pclose($handle);

        return [
            'status' => $status,
            'output' => $output,
        ];
    }
}
