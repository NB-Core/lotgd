<?php

declare(strict_types=1);

namespace Lotgd\Tests\Templates;

use Lotgd\Page\PageState;
use Lotgd\TwigTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every bundled Twig skin keeps the region contract (docs/ReactiveCore.md,
 * "Region contract for Twig skins").
 *
 * A partial answer replaces the inner HTML of each marked element with the
 * page's new value. An element that holds anything besides its variable --
 * an ad, a label, another variable -- loses it on the first swap, and a
 * variable left outside every element keeps its old value. So the skin is
 * rendered with a marker in every variable, and each region must hold its
 * marker and nothing else.
 */
final class RegionContractTest extends TestCase
{
    /** Variables printed next to the regions; none may end up inside one. */
    private const OUTSIDE = ['navad', 'verticalad', 'bodyad', 'headerad', 'paypal', 'copyright', 'source', 'pagegen'];

    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/lotgd-regions-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0700, true);
        $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
    }

    protected function tearDown(): void
    {
        TwigTemplate::deactivate();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->cacheDir);
    }

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function skins(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/templates_twig/*/page.twig') ?: [] as $page) {
            $skin = basename(dirname($page));
            yield $skin => [$skin];
        }
    }

    #[DataProvider('skins')]
    public function testTheSkinDeclaresRegionsAndTheOverlay(string $skin): void
    {
        $config = json_decode((string) file_get_contents(dirname(__DIR__, 2) . "/templates_twig/{$skin}/config.json"), true);

        self::assertTrue(($config['regions'] ?? null) === true, "{$skin}: \"regions\": true");
        self::assertTrue(($config['overlay'] ?? null) === true, "{$skin}: \"overlay\": true");
    }

    #[DataProvider('skins')]
    public function testEveryRegionHoldsItsVariableAndNothingElse(string $skin): void
    {
        $xpath = $this->render($skin);

        $content = $xpath->query('//*[@data-lotgd-region="content"]');
        self::assertSame(1, $content->length, "{$skin}: exactly one content region, the swap target");
        self::assertSame(self::marker('content'), self::inner($content->item(0)), "{$skin}: content");

        foreach (['nav', 'stats'] as $region) {
            $elements = $xpath->query("//*[@data-lotgd-region=\"{$region}\"]");
            self::assertGreaterThan(0, $elements->length, "{$skin}: a {$region} region");
            foreach ($elements as $element) {
                self::assertSame(self::marker($region), self::inner($element), "{$skin}: every {$region} region");
            }
        }

        foreach (PageState::REGIONS['header'] as $slot) {
            $elements = $xpath->query("//*[@data-lotgd-region=\"header\"][@data-lotgd-slot=\"{$slot}\"]");
            self::assertSame(1, $elements->length, "{$skin}: one header slot {$slot}");
            self::assertSame(self::marker($slot), self::inner($elements->item(0)), "{$skin}: header slot {$slot}");
        }

        foreach ($xpath->query('//*[@data-lotgd-title]') as $element) {
            self::assertSame(self::marker('title'), trim($element->textContent), "{$skin}: a title the client refreshes holds the title only");
        }
    }

    #[DataProvider('skins')]
    public function testNothingPrintedBesideARegionIsInsideOne(string $skin): void
    {
        $xpath = $this->render($skin);

        foreach ($xpath->query('//*[@data-lotgd-region]') as $element) {
            $html = $element->ownerDocument->saveHTML($element);
            foreach (self::OUTSIDE as $name) {
                self::assertStringNotContainsString(self::marker($name), $html, "{$skin}: {$name} inside a region would be swapped away");
            }
        }
    }

    private function render(string $skin): \DOMXPath
    {
        TwigTemplate::init($skin, $this->cacheDir);
        $names = ['content', 'nav', 'stats', 'title', ...PageState::REGIONS['header'], ...self::OUTSIDE];
        $context = ['template_path' => "templates_twig/{$skin}", 'lang' => 'en', 'meta_description' => '', 'version' => '',
            'gamename' => self::marker('title'), 'headscript_pre' => '', 'headscript_mid' => '', 'headscript' => '', 'script' => ''];
        foreach ($names as $name) {
            $context[$name] = self::marker($name);
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(TwigTemplate::render('page.twig', $context));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private static function marker(string $name): string
    {
        return "@@{$name}@@";
    }

    private static function inner(\DOMNode $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument->saveHTML($child);
        }

        return trim($html);
    }
}
