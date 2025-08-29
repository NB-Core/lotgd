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
        $table = Database::prefix('module_hooks');
        $this->addSql("ALTER TABLE $table DROP PRIMARY KEY");
        $this->addSql("ALTER TABLE $table CHANGE COLUMN `function` hook_callback VARCHAR(50) NOT NULL");
        $this->addSql("ALTER TABLE $table ADD PRIMARY KEY (modulename, location, hook_callback)");
    }

    public function down(Schema $schema): void
    {
        $table = Database::prefix('module_hooks');
        $this->addSql("ALTER TABLE $table DROP PRIMARY KEY");
        $this->addSql("ALTER TABLE $table CHANGE COLUMN hook_callback `function` VARCHAR(50) NOT NULL");
        $this->addSql("ALTER TABLE $table ADD PRIMARY KEY (modulename, location, `function`)");
    }
}
