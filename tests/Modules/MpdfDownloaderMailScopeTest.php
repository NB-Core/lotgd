<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/mpdf_downloader.php';

/**
 * Which messages the mail PDF export reads.
 *
 * The export takes its message ids from the posted mail form, so a player can
 * post any id. It used to select by id alone and put another player's subject
 * and body into the PDF; the mail pages themselves only ever show messages
 * addressed to the player.
 *
 * @group modules
 */
final class MpdfDownloaderMailScopeTest extends TestCase
{
    private const PLAYER = 1;
    private const SOMEONE_ELSE = 2;

    private Connection $connection;

    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$tablePrefix = '';
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE accounts (acctid INTEGER PRIMARY KEY, name TEXT)');
        $this->connection->executeStatement('INSERT INTO accounts VALUES (1, \'Player\'), (2, \'Someone Else\'), (3, \'Sender\')');
        foreach (['mail', 'mailarchive'] as $table) {
            $this->connection->executeStatement(
                "CREATE TABLE $table (messageid INTEGER PRIMARY KEY, msgfrom INTEGER, msgto INTEGER, subject TEXT, body TEXT, sent TEXT, seen INTEGER)"
            );
            $this->connection->executeStatement(
                "INSERT INTO $table VALUES (10, 3, 1, 'for the player', 'mine', '2026-10-13 10:00:00', 0),"
                . " (11, 3, 2, 'for someone else', 'not mine', '2026-10-13 10:01:00', 0)"
            );
        }
        Database::$doctrineConnection = $this->connection;
    }

    protected function tearDown(): void
    {
        Database::resetDoctrineConnection();
    }

    public function testThePlayersOwnMessageIsExported(): void
    {
        $rows = mpdf_downloader_mails('mail', [10], self::PLAYER);

        $this->assertSame(['for the player'], array_column($rows, 'subject'));
        $this->assertSame('Sender', $rows[0]['sender']);
    }

    public function testAnotherPlayersMessageIsNotExported(): void
    {
        $this->assertSame([], mpdf_downloader_mails('mail', [11], self::PLAYER));
        $this->assertSame(['for the player'], array_column(mpdf_downloader_mails('mail', [10, 11], self::PLAYER), 'subject'));
    }

    public function testTheArchiveIsScopedTheSameWay(): void
    {
        $this->assertSame(['for someone else'], array_column(mpdf_downloader_mails('mailarchive', [10, 11], self::SOMEONE_ELSE), 'subject'));
    }
}
