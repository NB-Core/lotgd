<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

/**
 * Duel records (docs/ReactiveCore.md, Live duels): who won and who lost an
 * encounter, written when it is settled, so a bio and the hall of fame count
 * a player's duels without reading every encounter's data.
 */
final class Version20261027000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add encounters.winner and encounters.loser for duel records';
    }

    public function up(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $encounters = Database::prefix('encounters');
        if (!Database::tableExists($encounters)) {
            return;
        }
        foreach (['winner', 'loser'] as $column) {
            if ($this->connection->fetchAllAssociative("SHOW COLUMNS FROM {$encounters} LIKE '{$column}'") === []) {
                $this->addSql("ALTER TABLE {$encounters} ADD COLUMN {$column} int(11) unsigned NULL DEFAULT NULL");
            }
            if ($this->connection->fetchAllAssociative("SHOW INDEX FROM {$encounters} WHERE Key_name = 'kind_{$column}'") === []) {
                $this->addSql("ALTER TABLE {$encounters} ADD KEY kind_{$column} (kind, {$column})");
            }
        }
    }

    public function down(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $encounters = Database::prefix('encounters');
        if (!Database::tableExists($encounters)) {
            return;
        }
        foreach (['winner', 'loser'] as $column) {
            if ($this->connection->fetchAllAssociative("SHOW INDEX FROM {$encounters} WHERE Key_name = 'kind_{$column}'") !== []) {
                $this->addSql("ALTER TABLE {$encounters} DROP KEY kind_{$column}");
            }
            if ($this->connection->fetchAllAssociative("SHOW COLUMNS FROM {$encounters} LIKE '{$column}'") !== []) {
                $this->addSql("ALTER TABLE {$encounters} DROP COLUMN {$column}");
            }
        }
    }
}
