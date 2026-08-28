<?php

declare(strict_types=1);

/**
 * Container-local readiness probe.
 *
 * The endpoint deliberately avoids the game bootstrap so it cannot create a
 * session or trigger gameplay. Every failure has the same empty response;
 * operational details remain available only through container logs.
 */

const REQUIRED_EXTENSIONS = [
    'gd',
    'mbstring',
    'mysqli',
    'opcache',
    'pdo',
    'pdo_mysql',
    'zip',
];

header('Cache-Control: no-store');
http_response_code(503);

try {
    foreach (REQUIRED_EXTENSIONS as $extension) {
        if (! extension_loaded($extension)) {
            throw new RuntimeException('required extension unavailable');
        }
    }

    $configurationPath = '/var/www/html/dbconnect.php';
    clearstatcache(true, $configurationPath);
    if (! is_file($configurationPath) || ! is_readable($configurationPath)) {
        throw new RuntimeException('database configuration unavailable');
    }

    // Resolve the canonical path so that opcache_invalidate and require use
    // the real filesystem path rather than the symlink.  With
    // opcache.validate_timestamps=0 OPcache keeps its own realpath cache that
    // clearstatcache() cannot clear; passing the resolved path directly
    // bypasses any stale symlink entry that OPcache may have cached while the
    // target file did not yet exist.
    $resolvedPath = realpath($configurationPath);
    if ($resolvedPath === false) {
        throw new RuntimeException('database configuration unavailable');
    }

    // Legacy or hand-edited configuration files may print migration notices.
    // Discard all such output so readiness can never disclose their contents.
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($resolvedPath, true);
    }
    ob_start();
    try {
        $configuration = require $resolvedPath;
    } finally {
        ob_end_clean();
    }
    if (! is_array($configuration)) {
        // Keep the probe compatible with pre-array 2.x configuration files.
        $configuration = [
            'DB_HOST' => $DB_HOST ?? null,
            'DB_USER' => $DB_USER ?? null,
            'DB_PASS' => $DB_PASS ?? null,
            'DB_NAME' => $DB_NAME ?? null,
        ];
    }

    foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'] as $key) {
        if (! isset($configuration[$key]) || ! is_string($configuration[$key])) {
            throw new RuntimeException('database configuration incomplete');
        }
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $database = new mysqli(
        $configuration['DB_HOST'],
        $configuration['DB_USER'],
        $configuration['DB_PASS'],
        $configuration['DB_NAME'],
    );
    $result = $database->query('SELECT 1');
    $row = $result instanceof mysqli_result ? $result->fetch_row() : null;
    // mysqlnd may return native integers while other drivers return strings.
    $ready = is_array($row) && isset($row[0]) && (string) $row[0] === '1';
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $database->close();

    if (! $ready) {
        throw new RuntimeException('database readiness query failed');
    }

    http_response_code(204);
} catch (Throwable) {
    // Never expose credentials, driver errors, filesystem paths, or traces.
    error_log('LOTGD readiness check failed');
}
