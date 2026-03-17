<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

/**
 * Adds the WebAuthn passkeys storage table for existing installations.
 */
final class Version20250724000022 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create twofactorauth_passkeys table for passkey credential storage';
    }

    public function up(Schema $schema): void
    {
        // Ensure legacy database helpers resolve table operations through the
        // Doctrine connection used by this migration execution.
        Database::setDoctrineConnection($this->connection);

        $table = Database::prefix('twofactorauth_passkeys');

        // Keep this migration idempotent for environments where the table was
        // provisioned manually or by prior deployment scripts.
        if (Database::tableExists($table)) {
            return;
        }

        $this->addSql(
            "CREATE TABLE {$table} (\n"
            . "  acctid int(11) unsigned NOT NULL DEFAULT 0,\n"
            . "  credential_id text NOT NULL,\n"
            . "  credential_id_hash char(64) NOT NULL DEFAULT '',\n"
            . "  public_key text NOT NULL,\n"
            . "  sign_count bigint unsigned NOT NULL DEFAULT 0,\n"
            . "  label varchar(255) NOT NULL DEFAULT '',\n"
            . "  transports varchar(255) NOT NULL DEFAULT '',\n"
            . "  created_at int(11) unsigned NOT NULL DEFAULT 0,\n"
            . "  last_used_at int(11) unsigned NOT NULL DEFAULT 0,\n"
            . "  PRIMARY KEY (credential_id_hash),\n"
            . "  KEY acctid (acctid)\n"
            . ") DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`"
        );
    }

    public function down(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $table = Database::prefix('twofactorauth_passkeys');

        if (!Database::tableExists($table)) {
            return;
        }

        $this->addSql("DROP TABLE {$table}");
    }
}
