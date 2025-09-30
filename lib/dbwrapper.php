<?php

use Lotgd\ErrorHandling;

// Configure the modern error handler before loading legacy settings.
ErrorHandling::configure();

$legacySettings = __DIR__ . '/../settings.php';

if (file_exists($legacySettings)) {
    require_once $legacySettings;
} else {
    $dbconnectPath = __DIR__ . '/../dbconnect.php';

    if (file_exists($dbconnectPath) && (!isset($GLOBALS['config']) || !is_array($GLOBALS['config']))) {
        $configData = require $dbconnectPath;

        if (is_array($configData)) {
            $compatConfig = $configData;
        } elseif (isset($config) && is_array($config)) {
            $compatConfig = $config;
        } else {
            $compatConfig = [
                'DB_HOST' => $DB_HOST ?? '',
                'DB_USER' => $DB_USER ?? '',
                'DB_PASS' => $DB_PASS ?? '',
                'DB_NAME' => $DB_NAME ?? '',
                'DB_PREFIX' => $DB_PREFIX ?? '',
                'DB_USEDATACACHE' => $DB_USEDATACACHE ?? 0,
                'DB_DATACACHEPATH' => $DB_DATACACHEPATH ?? '',
            ];
        }

        if (is_array($compatConfig)) {
            $GLOBALS['config'] = $compatConfig;
        }
    }
}

// Legacy compatibility - database functions now reside in Lotgd\MySQL
require_once 'lib/dbmysqli.php';
