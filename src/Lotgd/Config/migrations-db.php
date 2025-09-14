<?php

use Lotgd\MySQL\Database;

$db = require dirname(__DIR__, 3) . '/dbconnect.php';

return [
    'driver' => $db['DB_DRIVER'] ?? 'pdo_mysql',
    'host' => $db['DB_HOST'] ?? 'localhost',
    'dbname' => $db['DB_NAME'] ?? '',
    'user' => $db['DB_USER'] ?? '',
    'password' => $db['DB_PASS'] ?? '',
    'charset' => 'utf8mb4',
    Database::class . '::prefix' => $db['DB_PREFIX'] ?? '',
];
