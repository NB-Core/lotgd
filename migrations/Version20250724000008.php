<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250724000008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '0.9.8-prerelease.14a migration';
    }

    public function up(Schema $schema): void
    {
        $base = $_ENV['LOTGD_BASE_VERSION'] ?? null;

        if ('0.9.8-prerelease.14a' !== $base) {
            return;
        }

        $m = require dirname(__DIR__) . '/install/data/legacy_sql.php';
        foreach ($m['0.9.8-prerelease.14a'] as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
    }
}
