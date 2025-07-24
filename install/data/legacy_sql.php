<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/constants.php';
require_once dirname(__DIR__, 2) . '/lib/dbmysqli.php';
require_once dirname(__DIR__, 2) . '/src/Lotgd/MySQL/Database.php';

class LegacySettings {
    public function getSetting(string|int $name, mixed $default = false): mixed
    {
        return $default;
    }
}
class_alias('LegacySettings', 'Lotgd\Settings');

$settings = new \Lotgd\Settings();

include __DIR__ . '/installer_sqlstatements.php';

return $sql_upgrade_statements;
