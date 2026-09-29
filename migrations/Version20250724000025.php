<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

/**
 * Clear the submitted form data kept for failed logins.
 *
 * login.php stored a serialize() of the whole POST body of every failed login
 * in faillog.post, and with it the password that was tried: often a near miss
 * of the real one, or one the player uses elsewhere. It now stores only the
 * name that was tried. With expirefaillog = 0 the old rows were never purged,
 * so they are cleared here.
 */
final class Version20250724000025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clear faillog.post, which held the passwords of failed login attempts';
    }

    public function up(Schema $schema): void
    {
        Database::setDoctrineConnection($this->connection);

        $table = Database::prefix('faillog');

        if (! Database::tableExists($table)) {
            return;
        }

        $this->addSql("UPDATE {$table} SET post = '' WHERE post <> ''");
    }

    public function down(Schema $schema): void
    {
        // Nothing to restore: the cleared data is gone, deliberately.
    }
}
