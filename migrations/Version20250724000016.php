<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250724000016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Legacy migration – conversions handled in Version20250724000018';
    }

    public function up(Schema $schema): void
    {
        // Table conversions are managed by Version20250724000018
        // through TableDescriptor::synctable().
    }

    public function down(Schema $schema): void
    {
        // Irreversible migration.
    }
}
