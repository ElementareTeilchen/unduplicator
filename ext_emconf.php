<?php

$EM_CONF[$_EXTKEY] = array(
	'title' => 'Finds duplicates in sys_file and unduplicates them',
	'description' => 'fix references in sys_file_reference and softref fields like tt_content::headerlink',
	'category' => 'be',
	'author' => 'Franz Kugelmann',
	'author_email' => 'franz.kugelmann@elementare-teilchen.de',
	'shy' => '',
	'dependencies' => '',
	'conflicts' => '',
	'module' => '',
	'state' => 'stable',
	'internal' => '',
	'createDirs' => '',
	'modify_tables' => '',
	'lockType' => '',
	'author_company' => '',
	'version' => '1.0.0-dev',
	'constraints' => [
		'depends' => [
            'typo3' => '11.5.26-11.9.999'
        ],
		'conflicts' => [],
		'suggests' => []
    ],
	'_md5_values_when_last_written' => '',
);
