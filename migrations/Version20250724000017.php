<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

final class Version20250724000017 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update charset setting to UTF-8';
    }

    public function up(Schema $schema): void
    {
        $table = Database::prefix('settings');
        $this->addSql("UPDATE $table SET value = 'UTF-8' WHERE setting = 'charset'");
    }

    public function down(Schema $schema): void
    {
        $table = Database::prefix('settings');
        $this->addSql("UPDATE $table SET value = 'ISO-8859-1' WHERE setting = 'charset'");
    }
}
