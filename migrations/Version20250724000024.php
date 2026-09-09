<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

/**
 * Give the DEBUG mode profiling table a timestamp so it can be expired.
 *
 * Every other log table is cleaned up by the new day routine. This one had no
 * date column at all, so `debug` mode grew it by two rows per page view plus one
 * per module hook, for as long as the setting stayed on, with no way to trim it.
 */
final class Version20250724000024 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add debug.date so DEBUG mode runtime samples can be expired by the expiredebug setting';
    }

    public function up(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $table = Database::prefix('debug');

        if (! Database::tableExists($table)) {
            return;
        }

        $columns = $this->connection->fetchAllAssociative("SHOW COLUMNS FROM {$table} LIKE 'date'");
        if ($columns !== []) {
            return;
        }

        $this->addSql("ALTER TABLE {$table} ADD COLUMN date DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00'");
        $this->addSql("ALTER TABLE {$table} ADD INDEX date (date)");
        // Existing rows carry no timestamp. Date them now rather than at the epoch,
        // so the first cleanup run does not silently discard the samples an operator
        // is in the middle of collecting.
        $this->addSql("UPDATE {$table} SET date = NOW() WHERE date = '1970-01-01 00:00:00'");
    }

    public function down(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $table = Database::prefix('debug');

        if (! Database::tableExists($table)) {
            return;
        }

        $columns = $this->connection->fetchAllAssociative("SHOW COLUMNS FROM {$table} LIKE 'date'");
        if ($columns === []) {
            return;
        }

        $this->addSql("ALTER TABLE {$table} DROP INDEX date");
        $this->addSql("ALTER TABLE {$table} DROP COLUMN date");
    }
}
