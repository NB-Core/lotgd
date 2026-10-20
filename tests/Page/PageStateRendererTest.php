<?php

declare(strict_types=1);

namespace Lotgd\Tests\Page;

use Lotgd\Page\PageRenderer;
use Lotgd\Page\PageState;
use Lotgd\TwigTemplate;
use PHPUnit\Framework\TestCase;

/**
 * The page state and the renderer that turns it into a document.
 *
 * The renderer is the half a partial renderer will sit beside, so what is
 * pinned here is that it reads nothing but the state: the same state renders
 * to the same markup every time, and rendering leaves the state alone.
 */
final class PageStateRendererTest extends TestCase
{
    private ?string $cacheDir = null;

    protected function tearDown(): void
    {
        TwigTemplate::deactivate();
        if ($this->cacheDir !== null) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->cacheDir);
            $this->cacheDir = null;
        }
    }

    public function testALegacyPageIsHeaderContentFooter(): void
    {
        $state = new PageState(PageState::TEMPLATE_PAGE, false, '<html><body>', '</body></html>', '<p>Body</p>');

        self::assertSame('<html><body><p>Body</p></body></html>', PageRenderer::render($state));
    }

    public function testATwigPageRendersItsViewWithTheWholeContext(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/lotgd-pagestate-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0700, true);
        TwigTemplate::init('aurora', $this->cacheDir);

        $vars = [
            'lang' => 'en',
            'title' => 'Ye Olde Bank',
            'meta_description' => '',
            'template_path' => TwigTemplate::getPath(),
            'content' => '<p>Your balance is 10 gold.</p>',
            'copyright' => '',
        ];
        $state = new PageState(PageState::TEMPLATE_POPUP, true, '', '', $vars['content'], templateVars: $vars);

        $html = PageRenderer::render($state);

        self::assertSame(TwigTemplate::render('popup.twig', $vars), $html);
        self::assertStringContainsString('<title>Ye Olde Bank</title>', $html);
        self::assertStringContainsString('<p>Your balance is 10 gold.</p>', $html);
        self::assertSame($html, PageRenderer::render($state), 'Rendering twice must not change the result.');
    }

    public function testRegionsFollowTheDocumentedContract(): void
    {
        $state = new PageState(
            PageState::TEMPLATE_PAGE,
            true,
            '',
            '',
            '<p>Body</p>',
            '<a href="village.php">Village</a>',
            '<table>Stats</table>',
            ['V' => "window.location='village.php'"],
            ['title' => 'Bank', 'motd' => 'M', 'mail' => 'Ma', 'petition' => 'P', 'petitiondisplay' => 'D']
        );

        self::assertSame('<p>Body</p>', $state->region('content'));
        self::assertSame('<a href="village.php">Village</a>', $state->region('nav'));
        self::assertSame('<table>Stats</table>', $state->region('stats'));
        self::assertSame('MMaPD', $state->region('header'));
        self::assertSame('Bank', $state->title());
        self::assertFalse($state->isPopup());
        self::assertSame(array_keys(PageState::REGIONS), ['content', 'nav', 'stats', 'header']);
    }

    public function testAnUnknownRegionIsRefused(): void
    {
        $state = new PageState(PageState::TEMPLATE_POPUP, false, '', '', '');

        $this->expectException(\InvalidArgumentException::class);
        $state->region('sidebar');
    }
}
