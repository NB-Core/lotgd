<?php

declare(strict_types=1);

namespace Lotgd\Tests\Page;

use Lotgd\Page\Overlay;
use Lotgd\Page\PageState;
use Lotgd\TwigTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Popup pages as an in-page overlay (docs/ReactiveCore.md, Phase 6b): when a
 * request is an overlay request, what its answer carries, and which skins
 * mark their popup links.
 */
final class OverlayTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        Overlay::resetState();
        $this->cacheDir = sys_get_temp_dir() . '/lotgd-overlay-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0700, true);
    }

    protected function tearDown(): void
    {
        Overlay::resetState();
        TwigTemplate::deactivate();
        unset($_SERVER[Overlay::REQUEST_HEADER]);
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

    public function testOnlyTheExactHeaderMakesAnOverlayRequest(): void
    {
        self::assertFalse(Overlay::isRequested());
        foreach (['0', 'true', ''] as $value) {
            $_SERVER[Overlay::REQUEST_HEADER] = $value;
            self::assertFalse(Overlay::isRequested(), var_export($value, true));
        }
        $_SERVER[Overlay::REQUEST_HEADER] = '1';
        self::assertTrue(Overlay::isRequested());
    }

    public function testTheAnswerIsTheContentWithItsTitleInAHeader(): void
    {
        $state = new PageState(PageState::TEMPLATE_POPUP, true, '<head>', '<foot>', '<p>Inbox</p>', templateVars: ['title' => "Ye Olde <b>Poste</b>: Ünïcode\r\nX"]);

        $answer = Overlay::render($state);

        self::assertSame('<p>Inbox</p>', $answer['body'], 'No document around it.');
        self::assertSame('1', $answer['headers']['X-Lotgd-Overlay']);
        self::assertMatchesRegularExpression('/^[\x21-\x7e]+$/', $answer['headers']['X-Lotgd-Overlay-Title'], 'A valid header value whatever the title is.');
        self::assertSame("Ye Olde Poste: Ünïcode\r\nX", rawurldecode($answer['headers']['X-Lotgd-Overlay-Title']));
        self::assertArrayNotHasKey('X-Lotgd-Overlay-Close', $answer['headers']);
    }

    public function testAClosedPageTellsTheClientToCloseTheDialog(): void
    {
        Overlay::close();

        $answer = Overlay::render(new PageState(PageState::TEMPLATE_POPUP, true, '', '', '<p>Sent</p>'));

        self::assertSame('1', $answer['headers']['X-Lotgd-Overlay-Close']);
    }

    public function testOnlyASkinThatDeclaresItMarksItsPopupLinks(): void
    {
        self::assertFalse(Overlay::skinSupports(), 'A legacy template.');
        self::assertSame('', Overlay::attribute());

        TwigTemplate::init('aurora', $this->cacheDir);
        self::assertTrue(Overlay::skinSupports());
        self::assertSame(' data-lotgd-overlay="1"', Overlay::attribute());

        TwigTemplate::deactivate();
        TwigTemplate::init('modern', $this->cacheDir);
        self::assertFalse(Overlay::skinSupports(), 'A Twig skin without the key.');
    }

    public function testAStoredPageOpensTheMotdAgainOnlyWhileItIsUnread(): void
    {
        $page = '<head><meta name="lotgd-overlay-open" content="motd.php"></head>';

        self::assertSame($page, Overlay::storedPage($page, true));
        self::assertSame('<head></head>', Overlay::storedPage($page, false));
    }
}
