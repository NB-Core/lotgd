<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;
use Lotgd\MySQL\TableDescriptor;

use function dirname;

final class Version20250724000018 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert core tables to utf8mb4';
    }

    public function up(Schema $schema): void
    {
        require_once dirname(__DIR__) . '/src/Lotgd/Config/constants.php';

        Database::setDoctrineConnection($this->connection);
        require_once dirname(__DIR__) . '/install/data/tables.php';
        require_once dirname(__DIR__) . '/lib/tabledescriptor.php';

        $tables = [
            'accounts',
            'accounts_output',
            'companions',
            'debug',
            'deathmessages',
            'paylog',
            'armor',
            'bans',
            'clans',
            'commentary',
            'creatures',
            'debuglog',
            'debuglog_archive',
            'faillog',
            'gamelog',
            'logdnetbans',
            'logdnet',
            'mail',
            'masters',
            'moderatedcomments',
            'module_event_hooks',
            'module_hooks',
            'module_objprefs',
            'module_settings',
            'module_userprefs',
            'modules',
            'motd',
            'mounts',
            'nastywords',
            'news',
            'petitions',
            'pollresults',
            'referers',
            'settings',
            'settings_extended',
            'taunts',
            'untranslated',
            'translations',
            'weapons',
            'titles',
        ];

        $descriptors = get_all_tables();
        foreach ($tables as $table) {
            if (! isset($descriptors[$table])) {
                continue;
            }

            $name       = Database::prefix($table);
            $descriptor = $descriptors[$table];
            TableDescriptor::synctable($name, $descriptor);
        }
    }

    public function down(Schema $schema): void
    {
        // Irreversible migration.
    }
}
