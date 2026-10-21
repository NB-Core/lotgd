<?php

declare(strict_types=1);

namespace Lotgd\Tests\Commentary;

use Lotgd\Async\Handler\Commentary as CommentaryHandler;
use Lotgd\Async\Handler\Realtime as RealtimeHandler;
use Lotgd\Commentary;
use Lotgd\Realtime;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;

/**
 * Live commentary (docs/ReactiveCore.md): who reads a section outside its
 * page, how a new comment is announced, and which channels a poll reads.
 *
 * What is pinned is that a section is never read because the request names
 * it: a page must have shown it, and the account read for the request must
 * still pass the page's own check.
 */
#[PreserveGlobalState(false)]
final class LiveCommentaryTest extends TestCase
{
    private const EVENTS = '/FROM event_deliveries WHERE channel IN/';

    private DoctrineConnection $connection;
    private string $cacheDir = '';

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/bootstrap.php';
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        $this->connection = new DoctrineConnection();
        Database::setDoctrineConnection($this->connection);
        Settings::setInstance(new DummySettings(['realtime_enabled' => 1, 'partial_render' => 1]));
        Realtime::resetState();
        Commentary::resetState();
        // Realtime runs for players of a skin with regions (PartialMode::clientActive()).
        $this->cacheDir = sys_get_temp_dir() . '/lotgd-live-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0700, true);
        \Lotgd\TwigTemplate::init('aurora', $this->cacheDir);

        $GLOBALS['session'] = [
            'loggedin' => true,
            'user' => ['acctid' => 7, 'loggedin' => 1, 'superuser' => 0, 'clanid' => 5, 'clanrank' => CLAN_MEMBER,
                'laston' => date('Y-m-d H:i:s'), 'prefs' => ['partialrender' => 1]],
            'commentary_sections' => ['village'],
        ];
    }

    protected function tearDown(): void
    {
        \Lotgd\TwigTemplate::deactivate();
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

    public function testASectionNoPageShowedIsNotReadable(): void
    {
        self::assertTrue(Commentary::mayRead('village', $GLOBALS['session']['user']));
        self::assertFalse(Commentary::mayRead('inn', $GLOBALS['session']['user']));
        self::assertFalse(Commentary::mayRead('', $GLOBALS['session']['user']));
        self::assertFalse(Commentary::mayRead('village', ['loggedin' => 0]));
    }

    /**
     * @return iterable<string,array{0:string,1:array<string,int>,2:bool}>
     */
    public static function ownedSections(): iterable
    {
        yield 'a member in their clan hall' => ['clan-5', ['clanid' => 5, 'clanrank' => CLAN_MEMBER], true];
        yield 'a member after moving to another clan' => ['clan-5', ['clanid' => 6, 'clanrank' => CLAN_MEMBER], false];
        yield 'an applicant' => ['clan-5', ['clanid' => 5, 'clanrank' => CLAN_APPLICANT], false];
        yield 'a renamed clan section' => ['clan-5-ooc', ['clanid' => 5, 'clanrank' => CLAN_OFFICER], true];
        yield 'the grotto for a player' => ['superuser', ['superuser' => 0], false];
        yield 'the grotto for a translator alone' => ['superuser', ['superuser' => SU_IS_TRANSLATOR], false];
        yield 'the grotto for an editor' => ['superuser', ['superuser' => SU_EDIT_COMMENTS], true];
        yield 'a petition for a player' => ['pet-12', ['superuser' => 0], false];
        yield 'a petition for a petition editor' => ['pet-12', ['superuser' => SU_EDIT_PETITIONS], true];
    }

    /**
     * @param array<string,int> $account
     */
    #[DataProvider('ownedSections')]
    public function testASectionThatBelongsToSomeoneIsCheckedAgainstTheAccountReadNow(string $section, array $account, bool $readable): void
    {
        $GLOBALS['session']['commentary_sections'] = [$section];

        self::assertSame($readable, Commentary::mayRead($section, $account + $GLOBALS['session']['user']));
    }

    public function testANewCommentIsAnnouncedOnItsSectionWithItsId(): void
    {
        Commentary::injectRawComment('village', 7, 'Hello');

        $events = array_values(array_filter(
            $this->connection->executeStatements,
            static fn (array $statement): bool => str_starts_with((string) $statement['sql'], 'INSERT INTO events')
        ));
        self::assertCount(1, $events);
        self::assertSame('section-village', $events[0]['params']['channel']);
        self::assertSame('comment', $events[0]['params']['type']);
        self::assertSame(['region' => 'commentary', 'version' => 1], json_decode((string) $events[0]['params']['payload'], true));
    }

    public function testTheCommentIdIsReadBeforeTheOutboxAsksTheDatabaseAnything(): void
    {
        // The first Realtime::enabled() in a process (cron, a script) asks
        // information_schema; after that, the insert's id is gone.
        $connection = new class extends DoctrineConnection {
            public int $readsAtInsert = -1;
            public int $readsAtId = -1;

            public function executeStatement(string $sql, array $params = [], array $types = []): int
            {
                $result = parent::executeStatement($sql, $params, $types);
                if (str_starts_with($sql, 'INSERT INTO commentary')) {
                    $this->readsAtInsert = count($this->fetchAllLog);
                }

                return $result;
            }

            public function lastInsertId(): string
            {
                $this->readsAtId = count($this->fetchAllLog);

                return '1';
            }
        };
        Database::setDoctrineConnection($connection);

        Commentary::injectRawComment('village', 7, 'Hello');

        self::assertNotSame([], preg_grep('/information_schema/', array_map(static fn (array $q): string => (string) $q['sql'], $connection->fetchAllLog)));
        self::assertSame($connection->readsAtInsert, $connection->readsAtId, 'Nothing ran between the insert and reading its id.');
    }

    public function testWithRealtimeOffACommentIsOnlyWritten(): void
    {
        Settings::setInstance(new DummySettings(['realtime_enabled' => 0]));

        Commentary::injectRawComment('village', 7, 'Hello');

        self::assertSame([], preg_grep('/^INSERT INTO events/', array_map('strval', $this->connection->queries)));
        self::assertCount(1, preg_grep('/^INSERT INTO commentary/', array_map('strval', $this->connection->queries)));
    }

    public function testThePollReadsTheSectionOnlyWhenThePlayerMayReadIt(): void
    {
        $this->connection->queryResultsByPattern = [
            '/SELECT COALESCE\(MAX\(seq\), 0\)/' => [['m' => 3]],
            '/SELECT COALESCE\(MIN\(seq\), 0\)/' => [['m' => 1]],
            self::EVENTS => [],
        ];

        (new RealtimeHandler())->poll(2, 'village');
        (new RealtimeHandler())->poll(2, 'clan-9');

        $read = array_values(array_filter(
            $this->connection->fetchAllLog,
            static fn (array $query): bool => preg_match(self::EVENTS, $query['sql']) === 1
        ));
        self::assertSame(['user-7', 'section-village'], $read[0]['params']['channels']);
        self::assertSame(['user-7'], $read[1]['params']['channels'], 'A section the request names but no page showed is left out.');
    }

    public function testAPollForAPageWithoutCommentaryReadsNoComments(): void
    {
        Settings::setInstance(new DummySettings(['realtime_enabled' => 0, 'LOGINTIMEOUT' => 360]));

        (new CommentaryHandler())->pollUpdates('', 0);

        self::assertSame([], preg_grep('/FROM commentary/', array_map(static fn (array $q): string => (string) $q['sql'], $this->connection->fetchAllLog)));
    }

    public function testARealtimePollLeavesTheCommentsToTheAnnouncement(): void
    {
        $this->connection->queryResultsByPattern = [
            '/SELECT COALESCE\(MAX\(seq\), 0\)/' => [['m' => 3]],
            '/SELECT COALESCE\(MIN\(seq\), 0\)/' => [['m' => 1]],
            self::EVENTS => [],
        ];
        Settings::setInstance(new DummySettings(['realtime_enabled' => 1, 'partial_render' => 1, 'LOGINTIMEOUT' => 360]));

        $response = (new CommentaryHandler())->pollUpdates('village', 0, 2);

        self::assertArrayHasKey('realtime', $response->getData());
        self::assertSame([], preg_grep('/FROM commentary/', array_map(static fn (array $q): string => (string) $q['sql'], $this->connection->fetchAllLog)));
    }
}
