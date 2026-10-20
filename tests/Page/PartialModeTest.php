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
        yield 'the village, until its commentary script is a region' => ['village.php', 'GET', '', false];
        yield 'the inn, likewise' => ['inn.php', 'GET', '', false];
        yield 'lower-case method' => ['forest.php', 'get', '', true];
        yield 'a forest fight' => ['forest.php', 'GET', 'search', false];
        yield 'a post' => ['forest.php', 'POST', '', false];
        yield 'a page off the list' => ['bank.php', 'GET', '', false];
        yield 'a module page' => ['runmodule.php', 'GET', '', false];
    }

    #[DataProvider('allowList')]
    public function testTheAllowListMatchesScriptMethodAndOp(string $script, string $method, string $op, bool $allowed): void
    {
        self::assertSame($allowed, PartialMode::isAllowed($script, $method, $op));
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
        self::assertStringStartsWith('<p>Forest</p>', $answer['body'], 'The content region is the primary swap target.');
        self::assertStringContainsString('<title>The &lt;Forest&gt;</title>', $answer['body']);
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

    public function testThePreferenceAloneDoesNotTurnItOn(): void
    {
        // No Settings instance in a unit test: the game setting reads as off.
        self::assertFalse(PartialMode::isEnabledFor(['prefs' => ['partialrender' => 1]]));
        self::assertFalse(PartialMode::isEnabledFor([]));
    }
}
