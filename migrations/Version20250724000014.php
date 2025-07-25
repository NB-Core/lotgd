<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

use function dirname;
use function file_exists;
use function file_put_contents;
use function is_array;
use function var_export;

final class Version20250724000014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert legacy dbconnect.php using globals to array format';
    }

    public function up(Schema $schema): void
    {
        $file = dirname(__DIR__) . '/dbconnect.php';
        if (!file_exists($file)) {
            return;
        }

        $config = require $file;
        if (is_array($config)) {
            return; // already new style
        }

        $keys = [
            'DB_HOST',
            'DB_USER',
            'DB_PASS',
            'DB_NAME',
            'DB_PREFIX',
            'DB_USEDATACACHE',
            'DB_DATACACHEPATH',
        ];

        $values = [];
        foreach ($keys as $key) {
            if (isset($GLOBALS[$key])) {
                $values[$key] = $GLOBALS[$key];
            }
        }

        if (empty($values)) {
            return; // nothing we can migrate
        }

        $timezone = date_default_timezone_get();
        $dbconnect = "<?php\n";
        $dbconnect .= "//Migrated automatically on " . date('M d, Y h:i a', time()) . " (Timezone: {$timezone})\n";
        $dbconnect .= "return [\n";
        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
            $dbconnect .= "    '{$key}' => " . var_export($value, true) . ",\n";
        }
        $dbconnect .= "];\n";

        if (file_put_contents($file, $dbconnect) === false) {
            throw new \RuntimeException("Failed to write to file: {$file}");
        }
    }

    public function down(Schema $schema): void
    {
        // not reversible
    }
}
