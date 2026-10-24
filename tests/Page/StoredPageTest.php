<?php

declare(strict_types=1);

namespace Lotgd\Tests\Page;

use Lotgd\Page\StoredPage;
use Lotgd\Security\Csrf;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;

/**
 * A stored page shown again by badnav.php carries this session's async
 * token, not that of the session that stored it.
 */
#[PreserveGlobalState(false)]
final class StoredPageTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['session'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['session']);
    }

    public function testTheStoredTokenIsReplacedByThisSessions(): void
    {
        $config = ['url' => '/async/process.php', 'csrf' => 'stale', 'section' => 'village'];
        $html = '<head><meta name="lotgd-async" content="'
            . htmlspecialchars((string) json_encode($config), ENT_QUOTES, 'UTF-8') . '"></head><body>csrf stale</body>';

        $shown = StoredPage::withSessionToken($html);

        preg_match('/<meta name="lotgd-async" content="([^"]*)">/', $shown, $match);
        $now = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true);
        self::assertSame(Csrf::peek(Csrf::SCOPE_ASYNC), $now['csrf']);
        self::assertSame(['url' => '/async/process.php', 'section' => 'village'], array_diff_key($now, ['csrf' => 1]));
        self::assertStringEndsWith('<body>csrf stale</body>', $shown, 'Only the meta changes.');
    }

    public function testTheSessionPanelsTokenIsReplacedToo(): void
    {
        $config = ['timeout' => 900, 'csrf' => 'stale'];
        $html = '<head><meta name="lotgd-session" content="'
            . htmlspecialchars((string) json_encode($config), ENT_QUOTES, 'UTF-8') . '"></head><body></body>';

        preg_match('/<meta name="lotgd-session" content="([^"]*)">/', StoredPage::withSessionToken($html), $match);
        $now = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true);
        self::assertSame(Csrf::peek(Csrf::SCOPE_ASYNC), $now['csrf']);
        self::assertSame(900, $now['timeout']);
    }

    public function testAPageWithoutTheAsyncClientIsUnchanged(): void
    {
        self::assertSame('<head></head><body>x</body>', StoredPage::withSessionToken('<head></head><body>x</body>'));
        self::assertNull(Csrf::peek(Csrf::SCOPE_ASYNC), 'No token minted for a page that sends none.');
    }

    public function testAPreviewIsASealedFrameWithThePageInline(): void
    {
        $page = '<!DOCTYPE html><html><head><title>Forest</title><meta name="lotgd-async" content="{&quot;csrf&quot;:&quot;theirs&quot;}">'
            . '<script>alert("x")</script></head><body><header>Top</header><a href="forest.php?op=search">Search</a></body></html>';

        $frame = StoredPage::preview($page, "Last page's view");

        self::assertMatchesRegularExpression("/^<iframe sandbox loading='lazy' referrerpolicy='no-referrer' title='Last page&#039;s view' width='100%' height='400' srcdoc='[^']*'><\\/iframe>$/", $frame);
        self::assertStringNotContainsString('<script', $frame, 'Inline, escaped: no markup of the page reaches the editor page.');
        self::assertStringNotContainsString(' src=', $frame, 'No request of its own.');
        preg_match("/srcdoc='([^']*)'/", $frame, $match);
        $inner = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        self::assertStringNotContainsString('lotgd-async', $inner, "The other player's async token is left out.");
        self::assertStringContainsString('<head><style>a,button,input,select,textarea,label,summary{pointer-events:none!important}</style><title>Forest</title>', $inner);
        self::assertStringContainsString('<header>Top</header><a href="forest.php?op=search">Search</a>', $inner);
    }
}
