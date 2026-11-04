<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

/**
 * Live duels (docs/ReactiveCore.md, Phase 7): what an encounter is fought
 * for (encounters.stakes), the duel's state (encounters.data: both players'
 * values when it began, their hitpoints, the round), and one row per round
 * holding the two hidden choices until the round is resolved.
 */
final class Version20261026000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add encounters.stakes and encounters.data, and the encounter_rounds table for live duels';
    }

    public function up(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $encounters = Database::prefix('encounters');
        if (Database::tableExists($encounters)) {
            if ($this->connection->fetchAllAssociative("SHOW COLUMNS FROM {$encounters} LIKE 'stakes'") === []) {
                $this->addSql("ALTER TABLE {$encounters} ADD COLUMN stakes varchar(16) NOT NULL DEFAULT 'friendly'");
            }
            if ($this->connection->fetchAllAssociative("SHOW COLUMNS FROM {$encounters} LIKE 'data'") === []) {
                $this->addSql("ALTER TABLE {$encounters} ADD COLUMN data text NULL");
            }
        }

        $rounds = Database::prefix('encounter_rounds');
        if (!Database::tableExists($rounds)) {
            $this->addSql(
                "CREATE TABLE {$rounds} (\n"
                . "  encounter_id int(11) unsigned NOT NULL DEFAULT 0,\n"
                . "  round smallint(5) unsigned NOT NULL DEFAULT 0,\n"
                . "  challenger_choice varchar(16) DEFAULT NULL,\n"
                . "  opponent_choice varchar(16) DEFAULT NULL,\n"
                . "  deadline datetime NOT NULL DEFAULT '1970-01-01 00:00:00',\n"
                . "  resolved_at datetime DEFAULT NULL,\n"
                . "  result text NULL,\n"
                . "  PRIMARY KEY (encounter_id, round)\n"
                . ") DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`"
            );
        }
    }

    public function down(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $rounds = Database::prefix('encounter_rounds');
        if (Database::tableExists($rounds)) {
            $this->addSql("DROP TABLE {$rounds}");
        }
        $encounters = Database::prefix('encounters');
        if (Database::tableExists($encounters)) {
            foreach (['stakes', 'data'] as $column) {
                if ($this->connection->fetchAllAssociative("SHOW COLUMNS FROM {$encounters} LIKE '{$column}'") !== []) {
                    $this->addSql("ALTER TABLE {$encounters} DROP COLUMN {$column}");
                }
            }
        }
    }
}
