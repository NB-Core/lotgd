<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Lotgd/Config/constants.php';
require_once dirname(__DIR__, 2) . '/lib/dbmysqli.php';
require_once dirname(__DIR__, 2) . '/src/Lotgd/MySQL/Database.php';

$settings = new class
{
    public function getSetting(string|int $name, mixed $default = false): mixed
    {
        return $default;
    }
};

$config = require dirname(__DIR__, 2) . '/dbconnect.php';
global $DB_PREFIX;
$DB_PREFIX = $config['DB_PREFIX'] ?? '';
\Lotgd\MySQL\Database::setPrefix($DB_PREFIX);
error_log('Legacy SQL DB_PREFIX=' . $DB_PREFIX);

include __DIR__ . '/installer_sqlstatements.php';

return $sql_upgrade_statements;
