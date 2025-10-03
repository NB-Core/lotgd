<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

final class Version20250724000020 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add severity column to gamelog table';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $table = Database::prefix('gamelog');
        $columns = $schemaManager->listTableColumns($table);

        if (! isset($columns['severity'])) {
            $this->addSql("ALTER TABLE $table ADD severity VARCHAR(16) NOT NULL DEFAULT 'info' AFTER category");
        }
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $table = Database::prefix('gamelog');
        $columns = $schemaManager->listTableColumns($table);

        if (isset($columns['severity'])) {
            $this->addSql("ALTER TABLE $table DROP COLUMN severity");
        }
    }
}
