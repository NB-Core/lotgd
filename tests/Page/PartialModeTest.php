<?php

declare(strict_types=1);

namespace Lotgd\Tests\Page;

use Lotgd\Page\PageState;
use Lotgd\Page\PartialMode;
use Lotgd\Page\PartialRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parts of partial rendering that decide without running a page.
 */
final class PartialModeTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        PartialMode::resetState();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        PartialMode::resetState();
    }

    /**
     * @return iterable<string,array{string,string,string,bool}>
     */
    public static function allowList(): iterable
    {
        yield 'forest' => ['forest.php', 'GET', '', true];
        yield 'village' => ['village.php', 'GET', '', true];
        yield 'inn' => ['inn.php', 'GET', '', true];
        yield 'the bartender' => ['inn.php', 'GET', 'bartender', false];
        yield 'lower-case method' => ['forest.php', 'get', '', true];
        yield 'a forest fight' => ['forest.php', 'GET', 'search', false];
        yield 'a post' => ['forest.php', 'POST', '', false];
        yield 'a page off the list' => ['prefs.php', 'GET', '', false];
        yield 'a module page' => ['runmodule.php', 'GET', '', false];
        yield 'the gardens' => ['gardens.php', 'GET', '', true];
        yield 'a hall of fame list' => ['hof.php', 'GET', 'gems', true];
        yield 'an unknown hall of fame list' => ['hof.php', 'GET', 'secret', false];
        yield 'examining a mount' => ['stables.php', 'GET', 'examine', true];
        yield 'buying a mount' => ['stables.php', 'GET', 'buymount', false];
        yield 'the bank\'s deposit form' => ['bank.php', 'GET', 'deposit', true];
        yield 'a bank booking' => ['bank.php', 'POST', 'depositfinish', false];
        yield 'a weapon purchase' => ['weapons.php', 'GET', 'buy', false];
        yield 'training' => ['train.php', 'GET', 'challenge', false];
        yield 'a clan list' => ['clan.php', 'GET', 'list', true];
        yield 'leaving a clan' => ['clan.php', 'GET', 'withdraw', false];
        yield 'a graveyard fight' => ['graveyard.php', 'GET', 'fight', false];
    }

    #[DataProvider('allowList')]
    public function testTheAllowListMatchesScriptMethodAndOp(string $script, string $method, string $op, bool $allowed): void
    {
        self::assertSame($allowed, PartialMode::isAllowed($script, $method, $op));
    }

    public function testAParameterThatMakesAPageActIsNeverPartial(): void
    {
        self::assertTrue(PartialMode::isAllowed('pvp.php', 'GET', '', []), 'The list of players.');
        self::assertFalse(PartialMode::isAllowed('pvp.php', 'GET', '', ['act' => 'attack', 'name' => 'x']), 'An attack.');
        self::assertTrue(PartialMode::isAllowed('pvp.php', 'GET', '', ['act' => '']), 'An empty value is no act.');
    }

    public function testOnlyAnHtmxRequestThatIsNotAHistoryRestoreIsPartial(): void
    {
        unset($_SERVER[PartialMode::REQUEST_HEADER], $_SERVER[PartialMode::HISTORY_RESTORE_HEADER]);
        self::assertFalse(PartialMode::isRequested());

        $_SERVER[PartialMode::REQUEST_HEADER] = 'true';
        self::assertTrue(PartialMode::isRequested());

        $_SERVER[PartialMode::HISTORY_RESTORE_HEADER] = 'true';
        self::assertFalse(PartialMode::isRequested(), 'A history restore is a full page load.');
    }

    /**
     * @return iterable<string,array{PageState,bool}>
     */
    public static function swappable(): iterable
    {
        $page = static fn (string $content = '<p>Forest</p>', string $nav = '', string $stats = '', array $vars = []): PageState
            => new PageState(PageState::TEMPLATE_PAGE, true, '', '', $content, $nav, $stats, [], $vars, 'head');

        yield 'a plain page' => [$page(), true];
        yield 'a script in the content' => [$page('<SCRIPT>x()</SCRIPT>'), false];
        yield 'a script in the navigation' => [$page('<p>Forest</p>', '<script>nav()</script>'), false];
        yield 'a script in the stats' => [$page('<p>Forest</p>', '', '<script>stats()</script>'), false];
        yield 'a script in a header slot' => [$page('<p>Forest</p>', '', '', ['mail' => '<script>mail()</script>']), false];
        yield 'a popup' => [new PageState(PageState::TEMPLATE_POPUP, true, '', '', '<p>Mail</p>', templateVars: [], headSignature: 'head'), false];
    }

    #[DataProvider('swappable')]
    public function testOnlyAPageWithoutScriptsInAnyRegionIsSwappedIn(PageState $state, bool $swappable): void
    {
        self::assertSame($swappable, PartialRenderer::canRender($state, 'head'));
    }

    public function testAPageWantingAnotherHeadIsNotSwappedIn(): void
    {
        $state = new PageState(PageState::TEMPLATE_PAGE, true, '', '', '<p>Forest</p>', templateVars: [], headSignature: 'head');

        self::assertFalse(PartialRenderer::canRender($state, 'other'));
        self::assertFalse(PartialRenderer::canRender($state, null), 'A client that sent no head signature gets a full page.');
    }

    public function testTheHeadSignatureIgnoresCacheBustersTheCanonicalLinkAndTheClientsOwnMetaTags(): void
    {
        $head = [
            'headscript_pre' => '<link rel="canonical" href="https://example.org/village.php" />',
            'headscript_mid' => '<script src="/a.js?v=1"></script><meta name="lotgd-page-revision" content="1">',
        ];
        $later = [
            'headscript_pre' => '<link rel="canonical" href="https://example.org/forest.php" />',
            'headscript_mid' => '<script src="/a.js?v=2"></script><meta name="lotgd-page-revision" content="9">',
        ];
        $module = ['headscript_mid' => '<script src="/a.js?v=1"></script><script src="/module.js"></script>'];

        self::assertSame(PartialRenderer::headSignature($head), PartialRenderer::headSignature($later));
        self::assertNotSame(PartialRenderer::headSignature($head), PartialRenderer::headSignature($module));
    }

    public function testTheHeadSignatureIgnoresTheValuesTheAsyncClientStartsFrom(): void
    {
        $page = static fn (string $section, int $comment): array => ['headscript_pre' => '<meta name="lotgd-async" content="'
            . htmlspecialchars((string) json_encode(['section' => $section, 'lastCommentId' => $comment]), ENT_QUOTES, 'UTF-8')
            . '"><script src="/assets/js/lotgd.async.js?v=1"></script>'];

        self::assertSame(
            PartialRenderer::headSignature($page('village', 12)),
            PartialRenderer::headSignature($page('inn', 40))
        );
        $otherScript = $page('village', 12);
        $otherScript['headscript_pre'] .= '<script src="/modules/extra.js"></script>';
        self::assertNotSame(PartialRenderer::headSignature($page('village', 12)), PartialRenderer::headSignature($otherScript));
    }

    public function testThePartialAnswerCarriesTheAjaxStateOnlyForAjaxPlayers(): void
    {
        $plain = new PageState(PageState::TEMPLATE_PAGE, true, '', '', '<p>Inn</p>');
        $ajax = new PageState(
            PageState::TEMPLATE_PAGE,
            true,
            '',
            '',
            '<p>Inn</p>',
            asyncState: ['commentSection' => "inn's <room>", 'lastCommentId' => 40, 'lastUnreadMailId' => 7, 'lastUnreadMailCount' => 0]
        );

        self::assertArrayNotHasKey('X-Lotgd-Async-State', PartialRenderer::render($plain, 1)['headers']);
        $header = PartialRenderer::render($ajax, 1)['headers']['X-Lotgd-Async-State'];
        self::assertMatchesRegularExpression('/^[\x20-\x7e]+$/', $header, 'A header value stays printable ASCII.');
        self::assertSame(
            ['commentSection' => "inn's <room>", 'lastCommentId' => 40, 'lastUnreadMailId' => 7, 'lastUnreadMailCount' => 0],
            json_decode($header, true)
        );
    }

    public function testThePartialAnswerCarriesTheVersionsOfTheRealtimeRegionsItSwaps(): void
    {
        $plain = new PageState(PageState::TEMPLATE_PAGE, true, '', '', '<p>Inn</p>');
        $realtime = new PageState(PageState::TEMPLATE_PAGE, true, '', '', '<p>Inn</p>', regionVersions: ['mail' => 12, 'stats' => 4]);

        self::assertArrayNotHasKey('X-Lotgd-Region-Versions', PartialRenderer::render($plain, 1)['headers']);
        self::assertSame(
            ['mail' => 12, 'stats' => 4],
            json_decode(PartialRenderer::render($realtime, 1)['headers']['X-Lotgd-Region-Versions'], true)
        );
    }

    public function testThePartialAnswerCarriesEveryRegionAndTheRevision(): void
    {
        $state = new PageState(
            PageState::TEMPLATE_PAGE,
            true,
            '',
            '',
            '<p>Forest</p>',
            '<a href="village.php">Village</a>',
            '<table>Stats</table>',
            [],
            ['title' => 'The <Forest>', 'motd' => 'MOTD', 'mail' => 'Mail', 'petition' => '', 'petitiondisplay' => 'P']
        );

        $answer = PartialRenderer::render($state, 7);

        self::assertSame(['X-Lotgd-Partial' => '1', 'X-Lotgd-Page-Revision' => '7'], $answer['headers']);
        self::assertStringStartsWith(
            '<title>The &lt;Forest&gt;</title><p>Forest</p>',
            $answer['body'],
            'The title comes first, where content that leaves an element open cannot swallow it; the content follows as the primary swap target.'
        );
        self::assertStringContainsString(
            '<div hx-swap-oob="innerHTML:[data-lotgd-region=&quot;nav&quot;]"><a href="village.php">Village</a></div>',
            $answer['body']
        );
        self::assertStringContainsString('[data-lotgd-region=&quot;stats&quot;]"><table>Stats</table></div>', $answer['body']);
        foreach (['motd', 'mail', 'petition', 'petitiondisplay'] as $slot) {
            self::assertStringContainsString(
                'innerHTML:[data-lotgd-region=&quot;header&quot;][data-lotgd-slot=&quot;' . $slot . '&quot;]',
                $answer['body']
            );
        }
    }

    public function testAStoredPageIsShownAgainWithTheCurrentRevision(): void
    {
        $stored = '<head><meta name="lotgd-page-revision" content="3"></head><body>village</body>';

        self::assertSame(
            '<head><meta name="lotgd-page-revision" content="7"></head><body>village</body>',
            PartialRenderer::withRevision($stored, 7)
        );
        self::assertSame('<p>classic</p>', PartialRenderer::withRevision('<p>classic</p>', 7), 'A page without the meta tag is left alone.');
    }

    public function testThePreferenceAloneDoesNotTurnItOn(): void
    {
        // No Settings instance in a unit test: the game setting reads as off.
        self::assertFalse(PartialMode::isEnabledFor(['prefs' => ['partialrender' => 1]]));
        self::assertFalse(PartialMode::isEnabledFor([]));
    }

    public function testAPlayerWhoNeverChoseGetsTheGamesDefault(): void
    {
        $previous = \Lotgd\Settings::hasInstance() ? \Lotgd\Settings::getInstance() : null;
        try {
            \Lotgd\Settings::setInstance(new \Lotgd\Tests\Stubs\DummySettings(['partial_render' => 1]));
            self::assertTrue(PartialMode::isEnabledFor(['prefs' => []]), 'The default for players is on.');
            self::assertFalse(PartialMode::isEnabledFor(['prefs' => ['partialrender' => 0]]), 'Their own choice stays.');
            self::assertTrue(PartialMode::isEnabledFor(['prefs' => ['partialrender' => 1]]));

            \Lotgd\Settings::setInstance(new \Lotgd\Tests\Stubs\DummySettings(['partial_render' => 1, 'partial_render_default' => 0]));
            self::assertFalse(PartialMode::isEnabledFor(['prefs' => []]));
            self::assertTrue(PartialMode::isEnabledFor(['prefs' => ['partialrender' => 1]]));

            \Lotgd\Settings::setInstance(new \Lotgd\Tests\Stubs\DummySettings(['partial_render' => 0]));
            self::assertFalse(PartialMode::isEnabledFor(['prefs' => []]), 'Off for the game is off for everyone.');
        } finally {
            \Lotgd\Settings::setInstance($previous);
        }
    }

    public function testASkinIsLookedUpByTheNameOfTheSetting(): void
    {
        self::assertTrue(PartialMode::skinDeclaresFor('twig:aurora', 'regions'));
        self::assertTrue(PartialMode::skinDeclaresFor('twig:aurora', 'overlay'));
        self::assertFalse(PartialMode::skinDeclaresFor('jade', 'regions'), 'A legacy skin never declares regions.');
        self::assertFalse(PartialMode::skinDeclaresFor('twig:../aurora', 'regions'));
        self::assertFalse(PartialMode::skinDeclaresFor('twig:nosuchskin', 'regions'));
    }
}
