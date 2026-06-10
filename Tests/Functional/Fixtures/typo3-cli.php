#!/usr/bin/env php
<?php

declare(strict_types=1);

$classLoader = require dirname(__DIR__, 3) . '/vendor/autoload.php';

\TYPO3\TestingFramework\Core\SystemEnvironmentBuilder::run(
    1,
    \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_CLI,
    false
);

\TYPO3\CMS\Core\Core\Bootstrap::init($classLoader, true)
    ->get(\TYPO3\CMS\Core\Console\CommandApplication::class)
    ->run();
