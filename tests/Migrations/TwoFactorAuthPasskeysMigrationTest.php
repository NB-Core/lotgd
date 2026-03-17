<?php

declare(strict_types=1);

namespace Lotgd\Tests\Migrations;

use PHPUnit\Framework\TestCase;

final class TwoFactorAuthPasskeysMigrationTest extends TestCase
{
    /**
     * Verify the migration responsible for upgrades creates the passkeys table
     * with the expected schema details from the installer table definition.
     */
    public function testMigrationDefinesPasskeysTableCreation(): void
    {
        $migrationFile = dirname(__DIR__, 2) . '/migrations/Version20250724000022.php';
        $contents = file_get_contents($migrationFile);

        self::assertNotFalse($contents);
        self::assertStringContainsString("Database::prefix('twofactorauth_passkeys')", $contents);
        self::assertStringContainsString('CREATE TABLE {$table}', $contents);
        self::assertStringContainsString('credential_id_hash char(64) NOT NULL DEFAULT', $contents);
        self::assertStringContainsString('PRIMARY KEY (credential_id_hash)', $contents);
        self::assertStringContainsString('KEY acctid (acctid)', $contents);
        self::assertStringContainsString('DEFAULT CHARACTER SET utf8mb4', $contents);
        self::assertStringContainsString('utf8mb4_unicode_ci', $contents);
    }

    /**
     * Ensure the migration stays idempotent and reversible for existing
     * installations by guarding create/drop operations with table existence
     * checks.
     */
    public function testMigrationGuardsCreateAndDropWithTableExistenceChecks(): void
    {
        $migrationFile = dirname(__DIR__, 2) . '/migrations/Version20250724000022.php';
        $contents = file_get_contents($migrationFile);

        self::assertNotFalse($contents);
        self::assertStringContainsString('Database::setDoctrineConnection($this->connection);', $contents);
        self::assertStringContainsString('if (Database::tableExists($table)) {', $contents);
        self::assertStringContainsString('if (!Database::tableExists($table)) {', $contents);
        self::assertStringContainsString('DROP TABLE {$table}', $contents);
    }
}
