<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250724000010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '1.1.1 Dragonprime Edition migration';
    }

    public function up(Schema $schema): void
    {
        $m = require dirname(__DIR__) . '/install/data/legacy_sql.php';
        foreach ($m['1.1.1 Dragonprime Edition'] as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
    }
}
