<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\DataCache;
use Lotgd\MySQL\Database;

/**
 * The pages each module lets change in place (its info key `partial`,
 * Modules::partialDeclaration()), stored so the partial decision is made
 * before any module code runs.
 */
final class Version20261029000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add modules.partial: the module pages that may change in place';
    }

    public function up(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $modules = Database::prefix('modules');
        if (!Database::tableExists($modules)) {
            return;
        }
        if ($this->connection->fetchAllAssociative("SHOW COLUMNS FROM {$modules} LIKE 'partial'") === []) {
            $this->addSql("ALTER TABLE {$modules} ADD COLUMN partial text NULL DEFAULT NULL AFTER filehash");
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
        if ($this->connection->fetchAllAssociative("SHOW COLUMNS FROM {$modules} LIKE 'partial'") !== []) {
            $this->addSql("ALTER TABLE {$modules} DROP COLUMN partial");
        }
    }
}
