<?php

declare(strict_types=1);

namespace Lotgd\Tests\Migrations;

use PHPUnit\Framework\TestCase;

final class SettingsSettingWidthMigrationTest extends TestCase
{
    /**
     * Ensure the migration explicitly widens the primary settings identifier
     * column so longer keys such as the 2FA key-material setting fit without
     * truncation on upgrade.
     */
    public function testMigrationWidensSettingsIdentifierColumn(): void
    {
        $migrationFile = dirname(__DIR__, 2) . '/migrations/Version20250724000023.php';
        $contents = file_get_contents($migrationFile);

        self::assertNotFalse($contents);
        self::assertStringContainsString("Database::prefix('settings')", $contents);
        self::assertStringContainsString('VARCHAR(50)', $contents);
        self::assertStringContainsString('VARCHAR(25)', $contents);
        self::assertStringContainsString('secret-backed 2FA key material storage', $contents);
        self::assertStringContainsString('if (! Database::tableExists($table)) {', $contents);
    }

    /**
     * Keep the installer schema and Doctrine metadata aligned with the new
     * column width to prevent schema drift after fresh installs.
     */
    public function testSchemaDefinitionsUseFiftyCharacterSettingIdentifiers(): void
    {
        $installerSchema = file_get_contents(dirname(__DIR__, 2) . '/install/data/tables.php');
        $settingEntity = file_get_contents(dirname(__DIR__, 2) . '/src/Lotgd/Entity/Setting.php');

        self::assertNotFalse($installerSchema);
        self::assertNotFalse($settingEntity);
        self::assertStringContainsString("'name' => 'setting', 'type' => 'varchar(50)'", $installerSchema);
        self::assertStringContainsString("length: 50, name: 'setting'", $settingEntity);
    }
}
