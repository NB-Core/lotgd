<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

/**
 * Widen the main settings identifier column for longer core setting keys.
 */
final class Version20250724000023 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Increase settings.setting to VARCHAR(50) for new secret-backed 2FA key material storage in the main settings table';
    }

    public function up(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $table = Database::prefix('settings');

        if (! Database::tableExists($table)) {
            return;
        }

        $this->addSql("ALTER TABLE {$table} MODIFY setting VARCHAR(50) NOT NULL DEFAULT ''");
    }

    public function down(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $table = Database::prefix('settings');

        if (! Database::tableExists($table)) {
            return;
        }

        $this->addSql("ALTER TABLE {$table} MODIFY setting VARCHAR(25) NOT NULL DEFAULT ''");
    }
}
