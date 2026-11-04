<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

/**
 * The open realtime stream of each player (async/stream.php): one row per
 * account, holding the token of the stream that may run. A new stream takes
 * the row over, and the one before ends on its next check, so a player
 * holds at most one connection. Nothing reads the table while the setting
 * realtime_transport is not "sse".
 */
final class Version20261024000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the realtime_streams table for one realtime stream per player';
    }

    public function up(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $streams = Database::prefix('realtime_streams');
        if (!Database::tableExists($streams)) {
            $this->addSql(
                "CREATE TABLE {$streams} (\n"
                . "  acctid int(11) unsigned NOT NULL DEFAULT 0,\n"
                . "  token char(32) NOT NULL DEFAULT '',\n"
                . "  started datetime NOT NULL DEFAULT '1970-01-01 00:00:00',\n"
                . "  PRIMARY KEY (acctid)\n"
                . ") DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`"
            );
        }
    }

    public function down(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $streams = Database::prefix('realtime_streams');
        if (Database::tableExists($streams)) {
            $this->addSql("DROP TABLE {$streams}");
        }
    }
}
