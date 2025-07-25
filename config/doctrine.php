<?php

$config = require dirname(__DIR__) . '/dbconnect.php';

return [
    'driver' => 'pdo_mysql',
    'host' => $config['DB_HOST'] ?? 'localhost',
    'dbname' => $config['DB_NAME'] ?? '',
    'user' => $config['DB_USER'] ?? '',
    'password' => $config['DB_PASS'] ?? '',
    'charset' => 'utf8mb4',
    'migrations_paths' => [
        'Lotgd\Migrations' => dirname(__DIR__) . '/migrations',
    ],
];
