<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

/**
 * Grants: one-time links the server issues to a player for something another
 * player started -- an officer may accept an applicant.
 *
 * A grant is used inside the guarded action that follows it, and every grant
 * of its group (all officers offered the same applicant) closes in the same
 * transaction, so only the first click counts. Nothing reads the table while
 * the setting grants_enabled is off.
 */
final class Version20261023000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the granted_navs table for one-time links issued to another player';
    }

    public function up(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $grants = Database::prefix('granted_navs');
        if (!Database::tableExists($grants)) {
            $this->addSql(
                "CREATE TABLE {$grants} (\n"
                . "  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
                . "  acctid int(11) unsigned NOT NULL DEFAULT 0,\n"
                . "  granted_by int(11) unsigned NOT NULL DEFAULT 0,\n"
                . "  action varchar(64) NOT NULL DEFAULT '',\n"
                . "  target text NOT NULL,\n"
                . "  target_key varchar(64) NOT NULL DEFAULT '',\n"
                . "  text varchar(255) NOT NULL DEFAULT '',\n"
                . "  link varchar(255) NOT NULL DEFAULT '',\n"
                . "  method varchar(8) NOT NULL DEFAULT 'POST',\n"
                . "  created datetime NOT NULL DEFAULT '1970-01-01 00:00:00',\n"
                . "  expires_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',\n"
                . "  used_at datetime DEFAULT NULL,\n"
                . "  used_by_action_id char(32) NOT NULL DEFAULT '',\n"
                . "  changed_us bigint(20) unsigned NOT NULL DEFAULT 0,\n"
                . "  PRIMARY KEY (id),\n"
                . "  KEY open_for (acctid, used_at, expires_at),\n"
                . "  KEY target_key (target_key)\n"
                . ") DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`"
            );
        }
    }

    public function down(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $grants = Database::prefix('granted_navs');
        if (Database::tableExists($grants)) {
            $this->addSql("DROP TABLE {$grants}");
        }
    }
}
