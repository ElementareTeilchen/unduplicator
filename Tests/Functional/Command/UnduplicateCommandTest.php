<?php
declare(strict_types=1);
namespace ElementareTeilchen\Unduplicator\Tests\Functional\Command;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class UnduplicateCommandTest extends FunctionalTestCase
{

    /**
     * @test
     */
    public function unduplicateCommandReturnsZeroIfNoDuplicates()
    {
        $result = $this->executeConsoleCommand('unduplicate:sysfile');
        self::assertEquals(0, $result['status']);
    }

    /**
     * @test
     */
    public function unduplicateCommandIgnoresNonDuplicates()
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_non_duplicates.csv');

        $result = $this->executeConsoleCommand('unduplicate:sysfile');

        // Should be no changes in the DB
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_non_duplicates.csv');
        self::assertEquals(0, $result['status']);
    }

    /**
     * @test
     */
    public function unduplicateCommandIgnoresNonDuplicatesByCase()
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_non_duplicates_case_sensitive.csv');

        $result = $this->executeConsoleCommand('unduplicate:sysfile');
        // Should be no changes in the DB
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_non_duplicates_case_sensitive.csv');
        self::assertEquals(0, $result['status']);
    }

    /**
     * @test
     */
    public function unduplicateCommandFixesDuplicates()
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates.csv');

        $result = $this->executeConsoleCommand('unduplicate:sysfile');

        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_RESULT.csv');
        self::assertEquals(1, $result['status']);
    }


    /**
     * @test
     */
    public function unduplicateCommandFixesDuplicatesWithReferences()
    {
        $this->importCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_references.csv');

        $result = $this->executeConsoleCommand('unduplicate:sysfile');

        // the references are updated, so that the newer sys_file entry (uid=2) is used
        $this->assertCSVDataSet(__DIR__ . '/DataSet/sys_file_duplicates_with_references_RESULT.csv');
        self::assertEquals(1, $result['status']);
    }


    /**
     * based on TYPO3\CMS\Core\Tests\Functional\Command\AbstractCommandTest::executeConsoleCommand
     *   we had to change path for typo3 command because EXT:core/bin/typo3 does not exist in Composer installation
     */
    protected function executeConsoleCommand(string $cmdline, ...$args): array
    {
        $typo3File = __DIR__ . '/../../../.Build/bin/typo3';
        if (!file_exists($typo3File)) {
            throw new \RuntimeException(
                sprintf('Executable file <typo3> not found (using path <%s>). Make sure config:bin-dir is set to .Build/bin in composer.json', $typo3File)
            );
        }

        $cmd = vsprintf(PHP_BINARY . ' ' . $typo3File
            . ' ' . $cmdline, array_map('escapeshellarg', $args));

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
