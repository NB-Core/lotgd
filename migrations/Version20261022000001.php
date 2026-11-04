<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

/**
 * The realtime outbox: events written with the change they announce, the
 * delivery sequence the relay assigns, and the counter it assigns it from.
 *
 * events holds what a guarded action or a notification wrote, in the same
 * transaction as the change. The relay copies each into event_deliveries under
 * the next number of relay_sequence, whose row it holds locked until commit,
 * so the numbers have no gaps and no two relays hand out the same one. The
 * poll endpoint reads event_deliveries by number. Nothing reads any of this
 * while the setting realtime_enabled is off.
 */
final class Version20261022000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the realtime outbox tables events, event_deliveries and relay_sequence';
    }

    public function up(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);
        $charset = ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`';

        $events = Database::prefix('events');
        if (!Database::tableExists($events)) {
            $this->addSql(
                "CREATE TABLE {$events} (\n"
                . "  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
                . "  event_id char(32) NOT NULL DEFAULT '',\n"
                . "  channel varchar(64) NOT NULL DEFAULT '',\n"
                . "  type varchar(32) NOT NULL DEFAULT '',\n"
                . "  payload text NOT NULL,\n"
                . "  created datetime NOT NULL DEFAULT '1970-01-01 00:00:00',\n"
                . "  relayed_at datetime DEFAULT NULL,\n"
                . "  PRIMARY KEY (id),\n"
                . "  UNIQUE KEY event_id (event_id),\n"
                . "  KEY pending (relayed_at, id)\n"
                . ")" . $charset
            );
        }

        $deliveries = Database::prefix('event_deliveries');
        if (!Database::tableExists($deliveries)) {
            $this->addSql(
                "CREATE TABLE {$deliveries} (\n"
                . "  seq bigint(20) unsigned NOT NULL DEFAULT 0,\n"
                . "  event_id char(32) NOT NULL DEFAULT '',\n"
                . "  channel varchar(64) NOT NULL DEFAULT '',\n"
                . "  type varchar(32) NOT NULL DEFAULT '',\n"
                . "  payload text NOT NULL,\n"
                . "  created datetime NOT NULL DEFAULT '1970-01-01 00:00:00',\n"
                . "  PRIMARY KEY (seq),\n"
                . "  KEY channel_seq (channel, seq),\n"
                . "  KEY created (created)\n"
                . ")" . $charset
            );
        }

        $sequence = Database::prefix('relay_sequence');
        if (!Database::tableExists($sequence)) {
            $this->addSql(
                "CREATE TABLE {$sequence} (\n"
                . "  id tinyint(3) unsigned NOT NULL DEFAULT 1,\n"
                . "  next_seq bigint(20) unsigned NOT NULL DEFAULT 1,\n"
                . "  PRIMARY KEY (id)\n"
                . ")" . $charset
            );
            $this->addSql("INSERT INTO {$sequence} (id, next_seq) VALUES (1, 1)");
        }
    }

    public function down(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        foreach (['relay_sequence', 'event_deliveries', 'events'] as $table) {
            $name = Database::prefix($table);
            if (Database::tableExists($name)) {
                $this->addSql("DROP TABLE {$name}");
            }
        }
    }
}
