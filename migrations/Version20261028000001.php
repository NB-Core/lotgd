<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\DataCache;
use Lotgd\MySQL\Database;

/**
 * The hash of the content each module was last upgraded with
 * (Modules::inject()): a module file that only got a new modification time
 * -- every file after a new image or checkout -- is no longer upgraded on
 * its first page.
 */
final class Version20261028000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add modules.filehash so an unchanged module is not reinstalled after a deploy';
    }

    public function up(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $modules = Database::prefix('modules');
        if (!Database::tableExists($modules)) {
            return;
        }
        if ($this->connection->fetchAllAssociative("SHOW COLUMNS FROM {$modules} LIKE 'filehash'") === []) {
            $this->addSql("ALTER TABLE {$modules} ADD COLUMN filehash char(40) NULL DEFAULT NULL AFTER filemoddate");
        }
    }

    public function postUp(Schema $schema): void
    {
        // The module rows Modules::inject() keeps in the data cache were read
        // without the column; read them again.
        DataCache::getInstance()->massinvalidate('inject-');
    }

    public function down(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $modules = Database::prefix('modules');
        if (!Database::tableExists($modules)) {
            return;
        }
        if ($this->connection->fetchAllAssociative("SHOW COLUMNS FROM {$modules} LIKE 'filehash'") !== []) {
            $this->addSql("ALTER TABLE {$modules} DROP COLUMN filehash");
        }
    }
}
