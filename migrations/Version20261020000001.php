<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

/**
 * Give every account a page revision for partial rendering.
 *
 * With partial rendering on, a page navigation consumes the allowed links by
 * moving accounts.page_revision on with a conditional update. Two requests that
 * start from the same page -- a double click, a second tab, a second device --
 * then cannot both run: the one that loses the update is turned away before it
 * does anything. Players without partial rendering never touch the column.
 */
final class Version20261020000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add accounts.page_revision, the navigation revision partial rendering consumes atomically';
    }

    public function up(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $table = Database::prefix('accounts');

        if (! Database::tableExists($table)) {
            return;
        }

        $columns = $this->connection->fetchAllAssociative("SHOW COLUMNS FROM {$table} LIKE 'page_revision'");
        if ($columns !== []) {
            return;
        }

        $this->addSql("ALTER TABLE {$table} ADD COLUMN page_revision INT UNSIGNED NOT NULL DEFAULT 0");
    }

    public function down(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $table = Database::prefix('accounts');

        if (! Database::tableExists($table)) {
            return;
        }

        $columns = $this->connection->fetchAllAssociative("SHOW COLUMNS FROM {$table} LIKE 'page_revision'");
        if ($columns === []) {
            return;
        }

        $this->addSql("ALTER TABLE {$table} DROP COLUMN page_revision");
    }
}
