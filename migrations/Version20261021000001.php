<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

/**
 * Guarded game actions: an account version and the log of completed actions.
 *
 * accounts.version moves on with every change to an account's game state, so
 * an action can tell that the account changed since its page was rendered.
 * action_log records each action a player completed, keyed by the id the
 * form carried, with its result: the same submission arriving twice finds its
 * row and is answered from it instead of running again. Both are only read
 * while the setting guarded_actions is on.
 */
final class Version20261021000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add accounts.version and the action_log table for guarded game actions';
    }

    public function up(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $accounts = Database::prefix('accounts');
        if (Database::tableExists($accounts)) {
            $columns = $this->connection->fetchAllAssociative("SHOW COLUMNS FROM {$accounts} LIKE 'version'");
            if ($columns === []) {
                $this->addSql("ALTER TABLE {$accounts} ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 0");
            }
        }

        $log = Database::prefix('action_log');
        if (!Database::tableExists($log)) {
            $this->addSql(
                "CREATE TABLE {$log} (\n"
                . "  acctid int(11) unsigned NOT NULL DEFAULT 0,\n"
                . "  action_id char(32) NOT NULL DEFAULT '',\n"
                . "  action_name varchar(64) NOT NULL DEFAULT '',\n"
                . "  params_hash char(64) NOT NULL DEFAULT '',\n"
                . "  request_uri varchar(255) NOT NULL DEFAULT '',\n"
                . "  result text NOT NULL,\n"
                . "  created datetime NOT NULL DEFAULT '1970-01-01 00:00:00',\n"
                . "  PRIMARY KEY (acctid, action_id),\n"
                . "  KEY created (created)\n"
                . ") DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`"
            );
        }
    }

    public function down(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $log = Database::prefix('action_log');
        if (Database::tableExists($log)) {
            $this->addSql("DROP TABLE {$log}");
        }

        $accounts = Database::prefix('accounts');
        if (Database::tableExists($accounts)) {
            $columns = $this->connection->fetchAllAssociative("SHOW COLUMNS FROM {$accounts} LIKE 'version'");
            if ($columns !== []) {
                $this->addSql("ALTER TABLE {$accounts} DROP COLUMN version");
            }
        }
    }
}
