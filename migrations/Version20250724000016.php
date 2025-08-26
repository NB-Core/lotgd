<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

use function array_column;
use function array_unshift;
use function array_unique;
use function implode;
use function sprintf;

final class Version20250724000016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert core tables to utf8mb4_unicode_ci';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $dbName        = $this->connection->getDatabase();

        $tables = $schemaManager->listTableNames();
        array_unshift($tables, Database::prefix('mail'));
        $tables = array_unique($tables);

        foreach ($tables as $table) {
            $columns = $this->connection->fetchAllAssociative(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME != ?',
                [$dbName, $table, 'utf8mb4_unicode_ci']
            );

            if (empty($columns)) {
                continue;
            }

            $columnNames = array_column($columns, 'COLUMN_NAME');
            $this->write(sprintf('Converting %s columns: %s', $table, implode(', ', $columnNames)));
            $this->addSql(sprintf('ALTER TABLE %s CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $table));
        }
    }

    public function down(Schema $schema): void
    {
        // Irreversible migration.
    }
}
