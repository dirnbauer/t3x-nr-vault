<?php

declare(strict_types=1);

/**
 * Bootstrap file for PHPUnit tests.
 */

// Ensure consistent timezone
date_default_timezone_set('UTC');

// Load Composer autoloader
$autoloadFile = dirname(__DIR__) . '/.Build/vendor/autoload.php';
if (!file_exists($autoloadFile)) {
    $autoloadFile = dirname(__DIR__, 4) . '/vendor/autoload.php';
}

if (!file_exists($autoloadFile)) {
    throw new RuntimeException(
        'Could not find autoload.php. Please run "composer install" first.'
    );
}

require_once $autoloadFile;

// Define TYPO3 constants if not already defined
if (!defined('TYPO3_MODE')) {
    define('TYPO3_MODE', 'BE');
}

if (!defined('TYPO3_REQUESTTYPE')) {
    define('TYPO3_REQUESTTYPE', 2); // CLI
}
