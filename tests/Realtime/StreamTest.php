<?php

declare(strict_types=1);

namespace Lotgd\Tests\Realtime;

use Lotgd\Commentary;
use Lotgd\Realtime;
use Lotgd\Realtime\Stream;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;

/**
 * The realtime stream (docs/ReactiveCore.md, Phase 7), on a fake clock: what
 * it sends, when it reads the deliveries again, and what ends it.
 */
#[PreserveGlobalState(false)]
final class StreamTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const EVENTS = '/FROM event_deliveries WHERE channel IN/';
    private const TOKEN_READ = '/SELECT token FROM realtime_streams/';
    private const ACCOUNT_READ = '/SELECT loggedin, laston, superuser, clanid, clanrank FROM accounts/';
    private const RELAYED = '/SELECT next_seq FROM relay_sequence/';

    private DoctrineConnection $connection;
    private float $now = 1000.0;
    /** @var list<string> */
    private array $sent = [];
    /** @var array<int,callable():void> Run when the clock reaches the key, in seconds after the start. */
    private array $at = [];
    private bool $aborted = false;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/bootstrap.php';
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        $this->connection = new DoctrineConnection();
        Database::setDoctrineConnection($this->connection);
        Settings::setInstance(new DummySettings([
            'realtime_enabled' => 1, 'partial_render' => 1, 'realtime_transport' => 'sse', 'LOGINTIMEOUT' => 900,
        ]));
        Realtime::resetState();
        Commentary::resetState();

        $GLOBALS['session'] = ['commentary_sections' => ['village', 'clan-5']];
        $this->connection->queryResultsByPattern = [
            self::TOKEN_READ => [['token' => self::TOKEN]],
            self::ACCOUNT_READ => [$this->account()],
            self::RELAYED => [['next_seq' => 4]],
            '/SELECT COALESCE\(MAX\(seq\), 0\)/' => [['m' => 3]],
            '/SELECT COALESCE\(MIN\(seq\), 0\)/' => [['m' => 1]],
            self::EVENTS => [],
        ];
    }

    protected function tearDown(): void
    {
        Database::resetDoctrineConnection();
        unset($GLOBALS['session']);
    }

    public function testAnnouncementsGoOutWithTheCursorAsTheirId(): void
    {
        $this->connection->queryResultsByPattern[self::EVENTS] = [
            ['seq' => 3, 'event_id' => 'e3', 'type' => 'mail', 'payload' => '{"region":"mail","version":12}'],
        ];

        $ending = $this->stream(2, 'village', 5)->run();

        self::assertSame('lifetime', $ending);
        self::assertSame("retry: 2000\n\n", $this->sent[0]);
        self::assertSame(
            "id: 3\ndata: " . json_encode(['cursor' => 3, 'events' => [['seq' => 3, 'id' => 'e3', 'type' => 'mail', 'region' => 'mail', 'version' => 12]]]) . "\n\n",
            $this->sent[1]
        );
    }

    public function testTheDeliveriesAreReadAgainOnlyWhenTheRelayMoved(): void
    {
        $this->at[3] = function (): void {
            $this->connection->queryResultsByPattern[self::RELAYED] = [['next_seq' => 5]];
        };

        $this->stream(0, '', 10)->run();

        self::assertCount(2, $this->queries(self::EVENTS), 'Once at the start, once after the relay moved.');
        self::assertGreaterThanOrEqual(10, count($this->queries(self::RELAYED)), 'The counter is looked at every second.');
    }

    public function testANewerStreamOfThePlayerEndsThisOne(): void
    {
        $this->at[4] = function (): void {
            $this->connection->queryResultsByPattern[self::TOKEN_READ] = [['token' => str_repeat('b', 32)]];
        };

        $ending = $this->stream(0, '', 30)->run();

        self::assertSame('replaced', $ending);
        self::assertSame("event: replaced\ndata: {}\n\n", end($this->sent));
        self::assertLessThanOrEqual(1005.0, $this->now, 'Within a second of the takeover.');
    }

    public function testALogoutEndsTheStreamAtTheNextCheck(): void
    {
        $this->at[5] = function (): void {
            $this->connection->queryResultsByPattern[self::ACCOUNT_READ] = [['loggedin' => 0] + $this->account()];
        };

        $ending = $this->stream(0, '', 40)->run();

        self::assertSame('end', $ending);
        self::assertSame(1000.0 + Stream::CHECK_SECONDS, $this->now);
    }

    public function testATimedOutAccountEndsTheStream(): void
    {
        $this->connection->queryResultsByPattern[self::ACCOUNT_READ] = [['laston' => date('Y-m-d H:i:s', time() - 1000)] + $this->account()];

        self::assertSame('end', $this->stream(0, '', 40)->run());
    }

    public function testTheSectionIsReadWhileThePlayerMayReadIt(): void
    {
        $this->at[5] = function (): void {
            $this->connection->queryResultsByPattern[self::ACCOUNT_READ] = [['clanid' => 6] + $this->account()];
        };

        $this->stream(0, 'clan-5', 20)->run();

        $reads = $this->queries(self::EVENTS);
        self::assertSame(['user-7', 'section-clan-5', 'presence'], $reads[0]['params']['channels']);
        self::assertSame(['user-7', 'presence'], end($reads)['params']['channels'], 'Left the clan: its hall drops out at the next check.');
    }

    public function testASectionNoPageShowedIsNeverRead(): void
    {
        $this->stream(0, 'superuser', 3)->run();

        self::assertSame(['user-7', 'presence'], $this->queries(self::EVENTS)[0]['params']['channels']);
    }

    public function testACursorThePollCannotServeAsksForAResync(): void
    {
        $this->stream(9, '', 2)->run();

        self::assertStringContainsString('"resync":true', $this->sent[1]);
        self::assertStringStartsWith('id: 3', $this->sent[1]);
    }

    public function testABrowserThatLeftEndsTheStream(): void
    {
        $this->at[2] = function (): void {
            $this->aborted = true;
        };

        self::assertSame('aborted', $this->stream(0, '', 30)->run());
    }

    public function testThePingKeepsTheStreamAlive(): void
    {
        $this->stream(0, '', 31)->run();

        self::assertCount(2, array_keys($this->sent, ": ping\n\n", true));
    }

    public function testTheStreamRunsOnlyWithTheSetting(): void
    {
        self::assertTrue(Stream::enabled());

        Settings::setInstance(new DummySettings(['realtime_enabled' => 1, 'realtime_transport' => 'poll']));
        self::assertFalse(Stream::enabled());

        Settings::setInstance(new DummySettings(['realtime_enabled' => 0, 'realtime_transport' => 'sse']));
        self::assertFalse(Stream::enabled());
    }

    public function testAClaimReplacesThePlayersRow(): void
    {
        $token = Stream::claim(7);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        $statement = $this->connection->executeStatements[0];
        self::assertStringStartsWith('REPLACE INTO realtime_streams', (string) $statement['sql']);
        self::assertSame(['acctid' => 7, 'token' => $token], array_intersect_key($statement['params'], ['acctid' => 1, 'token' => 1]));
    }

    /**
     * @return array<string,mixed>
     */
    private function account(): array
    {
        return ['loggedin' => 1, 'laston' => date('Y-m-d H:i:s'), 'superuser' => 0, 'clanid' => 5, 'clanrank' => CLAN_MEMBER];
    }

    private function stream(int $cursor, string $section, int $lifetime): Stream
    {
        $start = $this->now;

        return new Stream(
            7,
            $section,
            $cursor,
            self::TOKEN,
            (float) $lifetime,
            function (string $chunk): void {
                $this->sent[] = $chunk;
            },
            function (float $seconds) use ($start): void {
                $this->now += $seconds;
                foreach ($this->at as $second => $change) {
                    if ($this->now - $start >= $second) {
                        unset($this->at[$second]);
                        $change();
                    }
                }
            },
            fn (): float => $this->now,
            fn (): bool => $this->aborted
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function queries(string $pattern): array
    {
        return array_values(array_filter(
            $this->connection->fetchAllLog,
            static fn (array $query): bool => preg_match($pattern, (string) $query['sql']) === 1
        ));
    }
}
