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
        // Legacy SQL handled via bin/legacy-upgrade.
    }

    public function down(Schema $schema): void
    {
    }
}
