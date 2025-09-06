<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

final class Version20250724000019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename module_hooks.function to hook_callback';
    }

    public function up(Schema $schema): void
    {
        $sm    = $this->connection->createSchemaManager();
        $table = Database::prefix('module_hooks');
        $cols  = $sm->listTableColumns($table);
        if (isset($cols['function'])) {
            $this->addSql("ALTER TABLE $table DROP PRIMARY KEY");
            $this->addSql("ALTER TABLE $table CHANGE COLUMN `function` hook_callback VARCHAR(50) NOT NULL");
            $this->addSql("ALTER TABLE $table ADD PRIMARY KEY (modulename, location, hook_callback)");
        }
    }

    public function down(Schema $schema): void
    {
        $sm    = $this->connection->createSchemaManager();
        $table = Database::prefix('module_hooks');
        $cols  = $sm->listTableColumns($table);
        if (isset($cols['hook_callback'])) {
            $this->addSql("ALTER TABLE $table DROP PRIMARY KEY");
            $this->addSql("ALTER TABLE $table CHANGE COLUMN hook_callback `function` VARCHAR(50) NOT NULL");
            $this->addSql("ALTER TABLE $table ADD PRIMARY KEY (modulename, location, `function`)");
        }
    }
}
