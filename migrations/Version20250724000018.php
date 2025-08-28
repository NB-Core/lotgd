<?php

declare(strict_types=1);

namespace Lotgd\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Lotgd\MySQL\Database;

final class Version20250724000018 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert core tables to utf8mb4';
    }

    public function up(Schema $schema): void
    {
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

        foreach ($tables as $table) {
            $name = Database::prefix($table);
            $this->addSql("ALTER TABLE $name CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        }
    }

    public function down(Schema $schema): void
    {
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

        foreach ($tables as $table) {
            $name = Database::prefix($table);
            $this->addSql("ALTER TABLE $name CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci;");
        }
    }
}
