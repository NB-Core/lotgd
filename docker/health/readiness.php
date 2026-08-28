<?php

declare(strict_types=1);

/**
 * Return only a status code after verifying the minimum runtime dependencies.
 *
 * This file deliberately lives outside the application document root and does
 * not bootstrap the game, create a session, or mutate application state.
 */
function finishReadinessCheck(int $status): never
{
    http_response_code($status);
    header('Content-Length: 0');
    exit;
}

$requiredExtensions = ['gd', 'json', 'mbstring', 'mysqli', 'pdo', 'pdo_mysql', 'zip'];
foreach ($requiredExtensions as $extension) {
    if (!extension_loaded($extension)) {
        finishReadinessCheck(503);
    }
}

$configPath = '/var/www/html/dbconnect.php';
if (!is_file($configPath) || !is_readable($configPath)) {
    finishReadinessCheck(503);
}

$initialBufferLevel = ob_get_level();
ob_start();

try {
    // Legacy configurations populate variables instead of returning an array.
    $loadedConfig = require $configPath;
    ob_end_clean();
    $config = is_array($loadedConfig) ? $loadedConfig : [
        'DB_HOST' => $DB_HOST ?? '',
        'DB_USER' => $DB_USER ?? '',
        'DB_PASS' => $DB_PASS ?? '',
        'DB_NAME' => $DB_NAME ?? '',
    ];

    foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key])) {
            finishReadinessCheck(503);
        }
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $database = new mysqli(
        $config['DB_HOST'],
        $config['DB_USER'],
        $config['DB_PASS'],
        $config['DB_NAME'],
    );
    $result = $database->query('SELECT 1');
    $ready = $result !== false && $result->fetch_row() === ['1'];
    $database->close();
} catch (Throwable) {
    while (ob_get_level() > $initialBufferLevel) {
        ob_end_clean();
    }

    // The caller only needs a generic status; diagnostic details stay private.
    finishReadinessCheck(503);
}

finishReadinessCheck($ready ? 204 : 503);
