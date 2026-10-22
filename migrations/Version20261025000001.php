<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

/**
 * Presence and live encounters (docs/ReactiveCore.md, Phase 7): where each
 * player online was last seen, and the challenges between two players that
 * open a shared session of their own.
 *
 * encounter_slots holds one row per player in an open encounter: its
 * primary key is what keeps a player in at most one at a time. Nothing
 * writes the tables while realtime_enabled (presence) and live_encounters
 * (encounters) are off.
 */
final class Version20261025000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the presence, encounters and encounter_slots tables for live encounters';
    }

    public function up(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $presence = Database::prefix('presence');
        if (!Database::tableExists($presence)) {
            $this->addSql(
                "CREATE TABLE {$presence} (\n"
                . "  acctid int(11) unsigned NOT NULL DEFAULT 0,\n"
                . "  place varchar(32) NOT NULL DEFAULT '',\n"
                . "  seen datetime NOT NULL DEFAULT '1970-01-01 00:00:00',\n"
                . "  changed_us bigint(20) unsigned NOT NULL DEFAULT 0,\n"
                . "  PRIMARY KEY (acctid),\n"
                . "  KEY seen (seen)\n"
                . ") DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`"
            );
        }

        $encounters = Database::prefix('encounters');
        if (!Database::tableExists($encounters)) {
            $this->addSql(
                "CREATE TABLE {$encounters} (\n"
                . "  id int(11) unsigned NOT NULL AUTO_INCREMENT,\n"
                . "  kind varchar(16) NOT NULL DEFAULT '',\n"
                . "  challenger int(11) unsigned NOT NULL DEFAULT 0,\n"
                . "  opponent int(11) unsigned NOT NULL DEFAULT 0,\n"
                . "  state varchar(16) NOT NULL DEFAULT '',\n"
                . "  grant_id bigint(20) unsigned NOT NULL DEFAULT 0,\n"
                . "  created datetime NOT NULL DEFAULT '1970-01-01 00:00:00',\n"
                . "  expires_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',\n"
                . "  changed_us bigint(20) unsigned NOT NULL DEFAULT 0,\n"
                . "  PRIMARY KEY (id),\n"
                . "  KEY challenger (challenger),\n"
                . "  KEY opponent (opponent),\n"
                . "  KEY created (created)\n"
                . ") DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`"
            );
        }

        $slots = Database::prefix('encounter_slots');
        if (!Database::tableExists($slots)) {
            $this->addSql(
                "CREATE TABLE {$slots} (\n"
                . "  acctid int(11) unsigned NOT NULL DEFAULT 0,\n"
                . "  encounter_id int(11) unsigned NOT NULL DEFAULT 0,\n"
                . "  PRIMARY KEY (acctid),\n"
                . "  KEY encounter_id (encounter_id)\n"
                . ") DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`"
            );
        }
    }

    public function down(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        foreach (['encounter_slots', 'encounters', 'presence'] as $table) {
            $name = Database::prefix($table);
            if (Database::tableExists($name)) {
                $this->addSql("DROP TABLE {$name}");
            }
        }
    }
}
