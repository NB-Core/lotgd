<?php

require_once dirname(__DIR__) . '/dbconnect.php';

return [
    'driver' => 'pdo_mysql',
    'host' => $DB_HOST ?? 'localhost',
    'dbname' => $DB_NAME ?? '',
    'user' => $DB_USER ?? '',
    'password' => $DB_PASS ?? '',
    'charset' => 'utf8mb4',
    'migrations_paths' => [
        'Lotgd\Migrations' => dirname(__DIR__) . '/migrations',
    ],
];
