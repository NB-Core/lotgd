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

    public function testAPageWithoutTheAsyncClientIsUnchanged(): void
    {
        self::assertSame('<head></head><body>x</body>', StoredPage::withSessionToken('<head></head><body>x</body>'));
        self::assertNull(Csrf::peek(Csrf::SCOPE_ASYNC), 'No token minted for a page that sends none.');
    }
}
