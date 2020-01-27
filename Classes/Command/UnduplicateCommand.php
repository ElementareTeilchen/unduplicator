<?php
namespace ElementareTeilchen\Unduplicator\Command;

use Symfony\Component\Console\Input\InputInterface;
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
 * @package sysfile_unduplicator
 */
class UnduplicateCommand extends \Symfony\Component\Console\Command\Command
{

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
    }

    /**
     * Executes the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getDescription());
        #$showDetails = $output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL;

        // get all duplicates
        $tableName = 'sys_file';

        /** @var  $connection Connection */
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($tableName);
        $findQuery = 'select GROUP_CONCAT(uid) as uids,identifier,count(*) as anz from sys_file group by identifier_hash having anz > 1 ';
        $statement = $connection->executeQuery($findQuery);

        $duplicates = [];
        while ($row = $statement->fetch()) {
            $duplicates[] = $row;
        }

        /** @var  $connectionSysFileReference Connection */
        $connectionSysFileReference = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        /** @var  $connectionSysFileMetadata Connection */
        $connectionSysFileMetadata = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_metadata');
        /** @var  $connectionContent Connection */
        $connectionContent = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');
        /** @var  $connectionNews Connection */
        $connectionNews = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_news_domain_model_news');

        foreach ($duplicates as $duplicate) {
            $io->writeln('unduplicate: ' . $duplicate['uids']);
            $duplicateIds = GeneralUtility::trimExplode(',', $duplicate['uids'], true);
            // make sure we have the lower uid at first position
            sort($duplicateIds);

            // prepare internal link syntax
            $wrongT3Link = 't3://file?uid=' . $duplicateIds[0];
            $correctT3Link = 't3://file?uid=' . $duplicateIds[1];

            // update all know locations with possibly wrong links or uid references
            $query = "update tt_content set header_link=replace(header_link, '" . $wrongT3Link . "', '" . $correctT3Link . "') where header_link ='". $wrongT3Link . "'";
            $connectionContent->executeQuery($query);
            $query = "update tt_content set bodytext=replace(bodytext, '" . $wrongT3Link . "', '" . $correctT3Link . "') where bodytext like '%". $wrongT3Link . "%'";
            $connectionContent->executeQuery($query);

            $query = "update tx_news_domain_model_news set bodytext=replace(bodytext, '" . $wrongT3Link . "', '" . $correctT3Link . "') where bodytext like '%". $wrongT3Link . "%'";
            $connectionNews->executeQuery($query);
            $query = "update tx_news_domain_model_news set internalurl=replace(internalurl, '" . $wrongT3Link . "', '" . $correctT3Link . "') where internalurl ='". $wrongT3Link . "'";
            $connectionContent->executeQuery($query);

            $query = 'update sys_file_reference set uid_local=' . $duplicateIds[1] . ' where uid_local=' . $duplicateIds[0];
            $connectionSysFileReference->executeQuery($query);
            $query = "update sys_file_reference set link=replace(link, '" . $wrongT3Link . "', '" . $correctT3Link . "') where link ='". $wrongT3Link . "'";
            $connectionSysFileReference->executeQuery($query);

            // finally delete wrongUids and corresponding duplicate metadata
            $query = 'delete from sys_file_metadata where file=' . $duplicateIds[0];
            $connectionSysFileMetadata->executeQuery($query);

            $query = 'delete from sys_file where uid=' . $duplicateIds[0];
            $connection->executeQuery($query);
        }

    }
}
