<?php

declare(strict_types=1);

namespace Lotgd\Tests\Commentary;

use Lotgd\Async\Handler\Commentary as CommentaryHandler;
use Lotgd\Commentary;
use Lotgd\ForcedNavigation;
use Lotgd\Realtime;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A line the async chat appends -- the player's own post, a line another
 * player wrote -- links its author's bio. The link was registered in the
 * async request only, which saves no navigation, so clicking the name ended
 * on badnav.php and showed the page again. It returned to process.php, too:
 * the async check compared REQUEST_URI with "/async/process.php", which
 * PhpGenericEnvironment had already reduced to "process.php".
 *
 * Separate processes: each case defines AJAX_MODE, as async/process.php does.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AsyncLineLinksTest extends TestCase
{
    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/bootstrap.php';
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        $this->connection = new DoctrineConnection();
        Database::setDoctrineConnection($this->connection);
        Settings::setInstance(new DummySettings(['realtime_enabled' => 0]));
        Realtime::resetState();
        Commentary::resetState();

        $GLOBALS['session'] = [
            'loggedin' => true,
            'user' => ['acctid' => 7, 'loggedin' => 1, 'superuser' => 0, 'clanid' => 0, 'clanrank' => 0, 'name' => 'Seven',
                'laston' => date('Y-m-d H:i:s'), 'prefs' => []],
            'commentary_sections' => ['village'],
            // What the page that showed the chat left in the session.
            'last_comment_request_uri' => 'village.php',
            'last_comment_scriptname' => 'village',
            'allowednavs' => ['village.php' => true],
            // The village page, as it saved (Commentary::rememberPageNavs()).
            'commentary_navs' => Commentary::navsFingerprint(['village.php' => true, 'forest.php' => true]),
        ];
        // As PhpGenericEnvironment::sanitizeUri() leaves it in the async request.
        $_SERVER['REQUEST_URI'] = 'process.php';
    }

    protected function tearDown(): void
    {
        Database::resetDoctrineConnection();
        unset($GLOBALS['session']);
    }

    /**
     * @param array<string, bool> $stored
     */
    private function storedPage(array $stored): void
    {
        $this->connection->queryResultsByPattern = [
            '/commentid > :lastId/' => [['commentid' => 12, 'section' => 'village', 'author' => 3, 'comment' => 'Hello', 'postdate' => date('Y-m-d H:i:s'),
                'name' => 'Three', 'acctid' => 3, 'superuser' => 0, 'clanrank' => 0, 'clanshort' => '']],
            '/^SELECT allowednavs FROM accounts/' => [['allowednavs' => serialize($stored)]],
        ];
    }

    /** @return list<array{sql: string, params: array<string, mixed>, types: array<string, mixed>}> */
    private function navWrites(): array
    {
        return array_values(array_filter(
            $this->connection->executeStatements,
            static fn (array $statement): bool => str_contains($statement['sql'], 'SET allowednavs = :added')
        ));
    }

    public function testALineFetchedLaterLinksTheBioOnThePageThePlayerIsOn(): void
    {
        define('AJAX_MODE', true);
        $this->storedPage(['village.php' => true, 'forest.php' => true]);
        $this->connection->statementResultsByPattern = ['/SET allowednavs = :added/' => 1];

        $response = (new CommentaryHandler())->commentaryRefresh('village', 11);

        $html = $response->getCommands()[0]['value'] ?? '';
        self::assertStringContainsString("bio.php?char=3&ret=village.php", $html, 'returns to the page, not to process.php');

        $writes = $this->navWrites();
        self::assertCount(1, $writes, 'the link goes to the stored page');
        $navs = unserialize($writes[0]['params']['added']);
        self::assertTrue($navs['bio.php?char=3&ret=village.php'] ?? false);
        self::assertTrue($navs['forest.php'] ?? false, 'the links the page offered stay');
        self::assertSame(serialize(['village.php' => true, 'forest.php' => true]), $writes[0]['params']['stored'], 'only if nothing changed them meanwhile');
        self::assertSame(7, $writes[0]['params']['acctid']);
    }

    public function testALinkAddedBeforeDoesNotHideThePage(): void
    {
        define('AJAX_MODE', true);
        // A second refresh: the first one's link is stored already.
        $this->storedPage(['village.php' => true, 'forest.php' => true, 'bio.php?char=9&ret=village.php' => true]);
        $this->connection->statementResultsByPattern = ['/SET allowednavs = :added/' => 1];

        (new CommentaryHandler())->commentaryRefresh('village', 11);

        self::assertCount(1, $this->navWrites());
    }

    /**
     * The player moved on -- into the forest, into a fight -- while the line
     * was on its way. The stored links are the forest's now: a bio link
     * there would lead out of the fight through bio.php's way back.
     */
    public function testAnotherPageGetsNoLinks(): void
    {
        define('AJAX_MODE', true);
        $this->storedPage(['forest.php?op=fight' => true, 'forest.php?op=run' => true]);

        (new CommentaryHandler())->commentaryRefresh('village', 11);

        self::assertSame([], $this->navWrites());
    }

    public function testWithoutAPageThatShowedTheChatNothingIsAdded(): void
    {
        define('AJAX_MODE', true);
        // The forest saved: no chat there, the note is gone.
        unset($GLOBALS['session']['commentary_navs']);
        $this->storedPage(['village.php' => true, 'forest.php' => true]);

        (new CommentaryHandler())->commentaryRefresh('village', 11);

        self::assertSame([], $this->navWrites());
    }

    public function testAPageInTheMiddleOfANavigationIsLeftAlone(): void
    {
        define('AJAX_MODE', true);
        // A page change claimed the links: the next page draws its own lines.
        $this->storedPage([]);

        (new CommentaryHandler())->commentaryRefresh('village', 11);

        self::assertSame([], $this->navWrites());
    }

    public function testAPageChangeMeanwhileWins(): void
    {
        $this->connection->queryResultsByPattern = [
            '/^SELECT allowednavs FROM accounts/' => [['allowednavs' => serialize(['village.php' => true])]],
        ];
        $this->connection->statementResultsByPattern = ['/SET allowednavs = :added/' => 0];

        self::assertFalse(ForcedNavigation::allowOnStoredPage(7, ['bio.php?char=3&ret=village.php'], static fn (): bool => true));
    }

    /**
     * A post and a refresh for the same page at once: the first adds its link,
     * the second's write then misses. It reads again, finds the same page
     * plus the first link, and adds its own on top.
     */
    public function testAConcurrentAdditionOnTheSamePageIsReadAgain(): void
    {
        Database::$mockResults = [
            [['allowednavs' => serialize(['village.php' => true])]],
            [['allowednavs' => serialize(['village.php' => true, 'bio.php?char=9&ret=village.php' => true])]],
        ];
        $this->connection->executeStatementResults = [0, 1];
        $page = Commentary::navsFingerprint(['village.php' => true]);

        self::assertTrue(ForcedNavigation::allowOnStoredPage(
            7,
            ['bio.php?char=3&ret=village.php'],
            static fn (array $navs): bool => hash_equals($page, Commentary::navsFingerprint($navs))
        ));

        $writes = $this->navWrites();
        self::assertCount(2, $writes);
        $navs = unserialize($writes[1]['params']['added']);
        self::assertTrue($navs['bio.php?char=9&ret=village.php'] ?? false, 'the first one\'s link stays');
        self::assertTrue($navs['bio.php?char=3&ret=village.php'] ?? false);
    }

    public function testALinkAlreadyStoredWritesNothing(): void
    {
        $this->connection->queryResultsByPattern = [
            '/^SELECT allowednavs FROM accounts/' => [['allowednavs' => serialize(['bio.php?char=3&ret=village.php' => true])]],
        ];

        self::assertTrue(ForcedNavigation::allowOnStoredPage(7, ['bio.php?char=3&ret=village.php'], static fn (): bool => true));
        self::assertSame([], $this->navWrites());
    }

    /**
     * The page notes its fingerprint as it saves; a page without a chat
     * clears it; the async request leaves it as it is.
     */
    public function testAPageNotesItsNavigationOnlyWhenItShowsAChat(): void
    {
        $session = ['allowednavs' => ['forest.php?op=fight' => true], 'commentary_navs' => 'old'];
        Commentary::rememberPageNavs($session);
        self::assertArrayNotHasKey('commentary_navs', $session, 'the forest has no chat');

        (new \ReflectionProperty(Commentary::class, 'shownSection'))->setValue(null, 'village');
        $session = ['allowednavs' => ['village.php' => true, 'bio.php?char=3&ret=village.php' => true]];
        Commentary::rememberPageNavs($session);
        self::assertSame(Commentary::navsFingerprint(['village.php' => true]), $session['commentary_navs'], 'its own line links do not count');

        define('AJAX_MODE', true);
        $session = ['allowednavs' => [], 'commentary_navs' => 'kept'];
        Commentary::rememberPageNavs($session);
        self::assertSame('kept', $session['commentary_navs']);
    }

    /**
     * A late partial fallback, a reload: badnav.php shows the stored page and
     * saves its links unchanged. It is still the page with the chat.
     */
    public function testThePageShownAgainByBadnavKeepsItsNote(): void
    {
        $navs = ['runmodule.php?module=dwellings&op=enter&dwid=5' => true, 'bio.php?char=3&ret=x' => true];
        $session = ['allowednavs' => $navs, 'commentary_navs' => Commentary::navsFingerprint($navs)];

        Commentary::rememberPageNavs($session);

        self::assertSame(Commentary::navsFingerprint($navs), $session['commentary_navs'] ?? null);
    }

    public function testNoPlayerOrNoLinksAsksNothing(): void
    {
        self::assertFalse(ForcedNavigation::allowOnStoredPage(0, ['bio.php?char=3'], static fn (): bool => true));
        self::assertFalse(ForcedNavigation::allowOnStoredPage(7, [], static fn (): bool => true));
        self::assertSame([], $this->connection->queries);
    }
}
