<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;

require_once dirname(__DIR__, 2) . '/src/Lotgd/Config/constants.php';

$config = require dirname(__DIR__, 2) . '/dbconnect.php';
global $DB_PREFIX;
$DB_PREFIX = $config['DB_PREFIX'] ?? '';

require_once dirname(__DIR__, 2) . '/lib/dbmysqli.php';
Database::setPrefix($DB_PREFIX);
error_log('Legacy SQL DB_PREFIX=' . $DB_PREFIX);

$settings = new class
{
    public function getSetting(string|int $name, mixed $default = false): mixed
    {
        return $default;
    }
};

include __DIR__ . '/installer_sqlstatements.php';

return $sql_upgrade_statements;
