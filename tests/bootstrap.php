<?php

// PHPUnit's executable defines this marker, while Pest invokes PHPUnit without it.
// The application permits in-memory secret locks only in an actual test process.
if (! defined('PHPUNIT_COMPOSER_INSTALL')) {
    define('PHPUNIT_COMPOSER_INSTALL', dirname(__DIR__).'/vendor/autoload.php');
}

require_once dirname(__DIR__).'/vendor/autoload.php';
