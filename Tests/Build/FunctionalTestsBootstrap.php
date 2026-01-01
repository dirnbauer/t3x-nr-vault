<?php

declare(strict_types=1);

use TYPO3\TestingFramework\Core\Testbase;

/**
 * Bootstrap for functional tests.
 *
 * Uses TYPO3's testing framework to set up an isolated test environment
 * with its own database and file system.
 */

// Load Composer autoloader
$autoloadFile = dirname(__DIR__, 2) . '/.Build/vendor/autoload.php';
if (!file_exists($autoloadFile)) {
    $autoloadFile = dirname(__DIR__, 5) . '/vendor/autoload.php';
}

if (!file_exists($autoloadFile)) {
    throw new RuntimeException(
        'Could not find autoload.php. Please run "composer install" first.',
    );
}

require_once $autoloadFile;

// Initialize the testing framework
$testbase = new Testbase();
$testbase->defineOriginalRootPath();
$testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
$testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
