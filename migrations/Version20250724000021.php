<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

final class Version20250724000021 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen password column to VARCHAR(255) and add password_algo column for bcrypt migration';
    }

    public function up(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);
        $table = Database::prefix('accounts');

        $this->addSql("ALTER TABLE {$table} MODIFY password VARCHAR(255) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS password_algo TINYINT UNSIGNED NOT NULL DEFAULT 0");
    }

    public function down(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);
        $table = Database::prefix('accounts');

        $this->addSql("ALTER TABLE {$table} DROP COLUMN IF EXISTS password_algo");
        $this->addSql("ALTER TABLE {$table} MODIFY password VARCHAR(32) NOT NULL DEFAULT ''");
    }
}
