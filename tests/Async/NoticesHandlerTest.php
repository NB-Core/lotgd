<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async;

use Lotgd\Async\Handler\Notices;
use Lotgd\Async\Handler\Timeout;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;

/**
 * Notices.status, the one call the notices in the upper right make: mail,
 * an unread Message of the Day and the session's time left, read only.
 */
#[PreserveGlobalState(false)]
final class NoticesHandlerTest extends TestCase
{
    private const COUNTS = '/SELECT MAX\(messageid\) AS lastid, SUM\(seen=0\) AS unread/';
    private const MOTD = '/SELECT motddate FROM motd/';

    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        $this->connection = new DoctrineConnection();
        Database::setDoctrineConnection($this->connection);
        Database::$queryCacheResults = ['mail-7' => [['seencount' => 1, 'notseen' => 2]]];
        Settings::setInstance(new DummySettings(['LOGINTIMEOUT' => 900]));
        Timeout::getInstance()->setNeverTimeoutIfBrowserOpen(false);
        $GLOBALS['session'] = ['user' => ['acctid' => 7, 'loggedin' => 1, 'laston' => date('Y-m-d H:i:s'), 'lastmotd' => '2026-10-01 10:00:00', 'prefs' => []]];
    }

    protected function tearDown(): void
    {
        Database::resetDoctrineConnection();
        unset($GLOBALS['session']);
    }

    public function testItReportsMailAndAnUnreadMessageOfTheDay(): void
    {
        $this->connection->queryResultsByPattern = [
            self::COUNTS => [['lastid' => 41, 'unread' => 2]],
            self::MOTD => [['motddate' => '2026-10-20 08:00:00']],
        ];

        $data = (new Notices())->status()->getData();

        self::assertSame(['unread' => 2, 'lastId' => 41], $data['notices']['mail']);
        self::assertSame('2026-10-20 08:00:00', $data['notices']['motd']);
        self::assertStringContainsString("href='mail.php'", $data['notices']['mailLink']);
        self::assertEqualsWithDelta(900, $data['session']['remaining'], 2);
        self::assertSame([], $this->connection->executeStatements, 'Asking writes nothing.');
    }

    public function testAMessageOfTheDayAlreadySeenIsNoNotice(): void
    {
        $this->connection->queryResultsByPattern = [
            self::COUNTS => [['lastid' => 0, 'unread' => 0]],
            self::MOTD => [['motddate' => '2026-09-01 08:00:00']],
        ];

        self::assertSame('', (new Notices())->status()->getData()['notices']['motd']);
    }

    public function testALoggedOutPlayerGetsNoNoticesOnlyTheTimeout(): void
    {
        $GLOBALS['session'] = ['loggedin' => false];

        $data = (new Notices())->status()->getData();

        self::assertArrayNotHasKey('notices', $data);
        self::assertSame(['remaining' => 0], $data['session']);
        self::assertSame([], $this->connection->queries);
    }
}
