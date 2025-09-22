<?php

use Lotgd\MySQL\Database;

$db     = require dirname(__DIR__, 3) . '/dbconnect.php';
$prefix = $db['DB_PREFIX'] ?? '';

return [
    'migrations_paths' => [
        'Lotgd\\Migrations' => dirname(__DIR__, 3) . '/migrations',
    ],
    'table_storage' => [
        'table_name' => Database::prefix('doctrine_migration_versions', $prefix),
    ],
];
