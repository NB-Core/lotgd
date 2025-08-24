<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

final class Version20250724000015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove index "clanname" from clans table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ' . Database::prefix('clans') . ' DROP INDEX IF EXISTS clanname');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ' . Database::prefix('clans') . ' ADD INDEX clanname (clanname)');
    }
}
