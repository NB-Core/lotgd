<?php

declare(strict_types=1);

namespace Lotgd\Tests\Realtime;

use Lotgd\Realtime\Poll;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\TwigTemplate;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;

/**
 * What async/poll.php answers (Lotgd\Realtime\Poll::handle()).
 *
 * What is pinned is who may read what: the channels come from the account
 * the request read, never from the request; a cross-site request without the
 * custom header gets nothing; a player for whom realtime is off gets nothing.
 */
#[PreserveGlobalState(false)]
final class PollEndpointTest extends TestCase
{
    private const POLL = ['REQUEST_METHOD' => 'GET', 'HTTP_X_LOTGD_POLL' => '1'];
    private const LATEST = '/SELECT COALESCE\(MAX\(seq\), 0\) FROM event_deliveries/';
    private const OLDEST = '/SELECT COALESCE\(MIN\(seq\), 0\) FROM event_deliveries/';
    private const EVENTS = '/FROM event_deliveries WHERE channel IN/';

    private DoctrineConnection $connection;
    private string $cacheDir = '';

    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        $this->connection = new DoctrineConnection();
        Database::setDoctrineConnection($this->connection);
        Settings::setInstance(new DummySettings(['realtime_enabled' => 1, 'partial_render' => 1]));
        \Lotgd\Realtime::resetState();
        $this->cacheDir = sys_get_temp_dir() . '/lotgd-poll-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0700, true);
        TwigTemplate::init('aurora', $this->cacheDir);

        $GLOBALS['session'] = [
            'loggedin' => true,
            'user' => ['acctid' => 7, 'name' => 'Tester', 'loggedin' => 1, 'version' => 5, 'prefs' => ['partialrender' => 1]],
        ];
    }

    protected function tearDown(): void
    {
        TwigTemplate::deactivate();
        Database::resetDoctrineConnection();
        unset($GLOBALS['session']);
        if (is_dir($this->cacheDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->cacheDir);
        }
    }

    public function testACursorOfZeroStillGetsEveryRetainedAnnouncement(): void
    {
        // Older announcements do no harm: the page ignores versions it shows.
        $this->connection->queryResultsByPattern = [
            self::LATEST => [['m' => 41]],
            self::OLDEST => [['m' => 1]],
            self::EVENTS => [['seq' => 2, 'event_id' => str_repeat('c', 32), 'type' => 'mail', 'payload' => '{"region":"mail","version":3}']],
        ];

        self::assertSame(
            ['cursor' => 41, 'events' => [['seq' => 2, 'id' => str_repeat('c', 32), 'type' => 'mail', 'region' => 'mail', 'version' => 3]]],
            Poll::handle(['cursor' => '0'], self::POLL, 100.0)['body']
        );
    }

    public function testACursorAheadOfTheLatestNumberResynchronises(): void
    {
        $this->connection->queryResultsByPattern = [self::LATEST => [['m' => 41]]];

        self::assertSame(['cursor' => 41, 'events' => [], 'resync' => true], Poll::handle(['cursor' => '99'], self::POLL, 100.0)['body']);
    }

    public function testEachRegionHasARateLimitOfItsOwn(): void
    {
        $this->connection->queryResultsByPattern = ['/SELECT name,alive,location/' => []];

        self::assertSame(200, Poll::handle(['region' => 'stats'], self::POLL, 100.0, 2.0)['status']);
        self::assertSame(429, Poll::handle(['region' => 'stats'], self::POLL, 101.0, 2.0)['status'], 'A client asking again and again is turned away.');
        self::assertSame(200, Poll::handle(['region' => 'stats'], self::POLL, 102.5, 2.0)['status'], 'The configured limit, not a fixed second.');
    }

    public function testRegionFetchesRightAfterAPollAreNotRateLimited(): void
    {
        $this->connection->queryResultsByPattern = [self::LATEST => [['m' => 1]], self::OLDEST => [['m' => 1]], '/SELECT name,alive,location/' => [], '/AS seencount/' => [['seencount' => 0, 'notseen' => 0]]];

        Database::$queryCacheResults['mail-7'] = [['seencount' => 0, 'notseen' => 0]];

        self::assertSame(200, Poll::handle(['cursor' => '1'], self::POLL, 100.0)['status']);
        self::assertSame(200, Poll::handle(['region' => 'stats'], self::POLL, 100.1)['status']);
        self::assertSame(200, Poll::handle(['region' => 'mail'], self::POLL, 100.2)['status']);
    }

    public function testAStoredPageShownAgainStopsPollingWhenRealtimeIsOffNow(): void
    {
        $stored = '<head><meta name="lotgd-realtime" content="async/poll.php|10"><meta name="lotgd-event-cursor" content="5">'
            . '<meta name="lotgd-region-versions" content="{}"><meta name="lotgd-page-revision" content="3"></head>';

        self::assertSame($stored, Poll::storedPage($stored, $GLOBALS['session']['user']), 'Kept while realtime is on.');

        Settings::setInstance(new DummySettings(['realtime_enabled' => 0, 'partial_render' => 1]));
        self::assertSame('<head><meta name="lotgd-page-revision" content="3"></head>', Poll::storedPage($stored, $GLOBALS['session']['user']));
    }

    public function testThePlayerGetsTheAnnouncementsOfTheirOwnChannelAfterTheCursor(): void
    {
        $this->connection->queryResultsByPattern = [
            self::LATEST => [['m' => 43]],
            self::OLDEST => [['m' => 1]],
            self::EVENTS => [
                ['seq' => 42, 'event_id' => str_repeat('a', 32), 'type' => 'mail', 'payload' => '{"region":"mail","version":9}'],
            ],
        ];

        $answer = Poll::handle(['cursor' => '40', 'channel' => 'user-1'], self::POLL, 100.0);

        self::assertSame(200, $answer['status']);
        self::assertSame(
            ['cursor' => 43, 'events' => [['seq' => 42, 'id' => str_repeat('a', 32), 'type' => 'mail', 'region' => 'mail', 'version' => 9]]],
            $answer['body']
        );
        $read = array_values(array_filter($this->connection->fetchAllLog, static fn (array $q): bool => preg_match(self::EVENTS, $q['sql']) === 1));
        self::assertSame(['user-7'], $read[0]['params']['channels'], 'The channel is the account read from the database, whatever the request asks for.');
        self::assertSame(40, $read[0]['params']['cursor']);
    }

    public function testACursorFromBeforeTheRetentionAsksThePageToReloadItsRegions(): void
    {
        $this->connection->queryResultsByPattern = [self::LATEST => [['m' => 90]], self::OLDEST => [['m' => 50]]];

        self::assertSame(['cursor' => 90, 'events' => [], 'resync' => true], Poll::handle(['cursor' => '3'], self::POLL, 100.0)['body']);
    }

    public function testARegionIsAnsweredWithItsVersion(): void
    {
        $this->connection->queryResultsByPattern = ['/SELECT name,alive,location/' => []];

        $answer = Poll::handle(['region' => 'stats'], self::POLL, 100.0);

        self::assertSame(200, $answer['status']);
        self::assertSame('stats', $answer['body']['region']);
        self::assertSame(5, $answer['body']['version'], 'The account version the region shows.');
        self::assertIsString($answer['body']['html']);
    }

    public function testTheStatsOfAPlayerWithAnExpressionBuffAreRendered(): void
    {
        // A buff like the troll's racial strength stores its modifier as an
        // expression; the footer evaluates it before the stats, so must the poll.
        $this->connection->queryResultsByPattern = ['/SELECT name,alive,location/' => []];
        $GLOBALS['session']['user'] += [
            'race' => 'Troll', 'alive' => 1, 'superuser' => 0, 'level' => 5, 'experience' => 0, 'dragonkills' => 0,
            'attack' => 10, 'defense' => 10, 'strength' => 10, 'dexterity' => 10, 'intelligence' => 10,
            'constitution' => 10, 'wisdom' => 10, 'weapondmg' => 0, 'armordef' => 0, 'hitpoints' => 10,
            'maxhitpoints' => 10, 'turns' => 10, 'playerfights' => 3, 'spirits' => 0, 'gold' => 0, 'goldinbank' => 0,
            'gems' => 0, 'weapon' => 'Fists', 'armor' => 'T-Shirt', 'hashorse' => 0, 'soulpoints' => 0,
            'gravefights' => 0, 'deathpower' => 0,
        ];
        $GLOBALS['session']['bufflist'] = ['racialbenefit' => [
            'name' => 'Trollish Strength', 'atkmod' => '(<attack>?(1+((1+floor(<level>/5))/<attack>)):0)',
            'allowinpvp' => 1, 'allowintrain' => 1, 'rounds' => -1, 'schema' => 'module-racetroll',
        ]];

        $answer = Poll::handle(['region' => 'stats'], self::POLL, 100.0);

        self::assertSame(200, $answer['status']);
        self::assertIsString($answer['body']['html']);
        self::assertSame(
            '(<attack>?(1+((1+floor(<level>/5))/<attack>)):0)',
            $GLOBALS['session']['bufflist']['racialbenefit']['atkmod'],
            'Put back as it was stored.'
        );
    }

    public function testAnUnknownRegionIsRefused(): void
    {
        self::assertSame(400, Poll::handle(['region' => 'nav'], self::POLL, 100.0)['status']);
    }

    public function testWithoutTheCustomHeaderNothingIsRead(): void
    {
        self::assertSame(400, Poll::handle(['cursor' => '0'], ['REQUEST_METHOD' => 'GET'], 100.0)['status']);
        self::assertSame(405, Poll::handle(['cursor' => '0'], ['REQUEST_METHOD' => 'POST'] + self::POLL, 100.0)['status']);
        self::assertSame([], $this->connection->queries);
    }

    public function testWithRealtimeOffForThePlayerNothingIsRead(): void
    {
        $GLOBALS['session']['user']['prefs'] = ['partialrender' => 0];
        self::assertSame(404, Poll::handle(['cursor' => '0'], self::POLL, 100.0)['status']);

        $GLOBALS['session']['user']['prefs'] = ['partialrender' => 1];
        Settings::setInstance(new DummySettings(['realtime_enabled' => 0, 'partial_render' => 1]));
        self::assertSame(404, Poll::handle(['cursor' => '0'], self::POLL, 100.0)['status']);
        self::assertSame([], preg_grep('/event_deliveries/', $this->connection->queries), 'No announcements are read.');
    }

    public function testALoggedOutPlayerIsToldToLogIn(): void
    {
        // ForcedNavigation leaves this behind for a poll whose login expired.
        $GLOBALS['session'] = ['loggedin' => false];

        self::assertSame(401, Poll::handle(['cursor' => '0'], self::POLL, 100.0)['status']);
    }

    public function testPollingFasterThanTheRateLimitIsRefused(): void
    {
        $this->connection->queryResultsByPattern = [self::LATEST => [['m' => 1]], self::OLDEST => [['m' => 1]]];

        self::assertSame(200, Poll::handle(['cursor' => '1'], self::POLL, 100.0)['status']);
        self::assertSame(429, Poll::handle(['cursor' => '1'], self::POLL, 100.2)['status']);
        self::assertSame(200, Poll::handle(['cursor' => '1'], self::POLL, 101.5)['status']);
    }
}
