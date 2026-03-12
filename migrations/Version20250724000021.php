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

        // Check whether the column already exists before adding it (MySQL
        // does not support ADD COLUMN IF NOT EXISTS).
        $columns = $this->connection->executeQuery("SHOW COLUMNS FROM {$table} LIKE 'password_algo'")->fetchAllAssociative();
        if (count($columns) === 0) {
            $this->addSql("ALTER TABLE {$table} ADD COLUMN password_algo TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER password");
        }
    }

    public function down(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);
        $table = Database::prefix('accounts');

        $columns = $this->connection->executeQuery("SHOW COLUMNS FROM {$table} LIKE 'password_algo'")->fetchAllAssociative();
        if (count($columns) > 0) {
            $this->addSql("ALTER TABLE {$table} DROP COLUMN password_algo");
        }
        $this->addSql("ALTER TABLE {$table} MODIFY password VARCHAR(32) NOT NULL DEFAULT ''");
    }
}
