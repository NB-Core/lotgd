<?php

declare(strict_types=1);

namespace Lotgd\Tests\Templates;

use Lotgd\Page\PageState;
use Lotgd\Page\PartialMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every bundled legacy `.htm` template keeps the region contract, as the
 * Twig skins do (RegionContractTest; templates/README.md).
 *
 * A legacy page is its header section, the content, then its footer section
 * (PageRenderer). So the template is put together that way with a marker in
 * every placeholder, and each region must hold its marker and nothing else.
 * The content region opens at the end of the header section and closes at
 * the start of the footer section.
 */
final class LegacyRegionContractTest extends TestCase
{
    /** Placeholders printed next to the regions; none may end up inside one. */
    private const OUTSIDE = ['navad', 'verticalad', 'bodyad', 'headerad', 'paypal', 'copyright', 'source', 'pagegen'];

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function templates(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/templates/*.htm') ?: [] as $file) {
            $name = basename($file);
            yield $name => [$name];
        }
    }

    #[DataProvider('templates')]
    public function testTheTemplateDeclaresRegionsAndTheOverlay(string $template): void
    {
        $config = json_decode(trim(self::sections($template)['config'] ?? ''), true);

        self::assertTrue(($config['regions'] ?? null) === true, "{$template}: <!--!config--> {\"regions\": true}");
        self::assertTrue(($config['overlay'] ?? null) === true, "{$template}: <!--!config--> {\"overlay\": true}");
        self::assertTrue(PartialMode::skinDeclaresFor($template, 'regions'), "{$template}: found by its setting name");
        self::assertTrue(PartialMode::skinDeclaresFor('legacy:' . $template, 'overlay'));
    }

    #[DataProvider('templates')]
    public function testEveryRegionHoldsItsPlaceholderAndNothingElse(string $template): void
    {
        $xpath = $this->render($template);

        $content = $xpath->query('//*[@data-lotgd-region="content"]');
        self::assertSame(1, $content->length, "{$template}: exactly one content region, the swap target");
        self::assertSame(self::marker('content'), self::inner($content->item(0)), "{$template}: content");

        foreach (['nav', 'stats'] as $region) {
            $elements = $xpath->query("//*[@data-lotgd-region=\"{$region}\"]");
            self::assertGreaterThan(0, $elements->length, "{$template}: a {$region} region");
            foreach ($elements as $element) {
                self::assertSame(self::marker($region), self::inner($element), "{$template}: every {$region} region");
            }
        }

        foreach (PageState::REGIONS['header'] as $slot) {
            $elements = $xpath->query("//*[@data-lotgd-region=\"header\"][@data-lotgd-slot=\"{$slot}\"]");
            self::assertSame(1, $elements->length, "{$template}: one header slot {$slot}");
            self::assertSame(self::marker($slot), self::inner($elements->item(0)), "{$template}: header slot {$slot}");
        }

        foreach ($xpath->query('//*[@data-lotgd-title]') as $element) {
            self::assertSame(self::marker('title'), trim($element->textContent), "{$template}: a title the client refreshes holds the title only");
        }
    }

    #[DataProvider('templates')]
    public function testNothingPrintedBesideARegionIsInsideOne(string $template): void
    {
        $xpath = $this->render($template);

        foreach ($xpath->query('//*[@data-lotgd-region]') as $element) {
            $html = $element->ownerDocument->saveHTML($element);
            foreach (self::OUTSIDE as $name) {
                self::assertStringNotContainsString(self::marker($name), $html, "{$template}: {$name} inside a region would be swapped away");
            }
        }
    }

    #[DataProvider('templates')]
    public function testTheStylesheetGivesTheDialogItsColours(string $template): void
    {
        $css = '';
        foreach (self::stylesheets($template) as $file) {
            $css .= (string) file_get_contents($file);
        }

        foreach (['bg', 'fg', 'border', 'scheme'] as $part) {
            self::assertMatchesRegularExpression("/--lotgd-overlay-{$part}\\s*:/", $css, "{$template}: --lotgd-overlay-{$part}");
        }
    }

    private function render(string $template): \DOMXPath
    {
        $sections = self::sections($template);
        $html = ($sections['header'] ?? '') . self::marker('content') . ($sections['footer'] ?? '');
        $names = ['nav', 'stats', 'title', ...PageState::REGIONS['header'], ...self::OUTSIDE];
        foreach ($names as $name) {
            $html = str_replace('{' . $name . '}', self::marker($name), $html);
        }
        $html = (string) preg_replace('/{[a-z_]+}/', '', $html);

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    /**
     * The sections as Template::loadTemplate() cuts them.
     *
     * @return array<string,string>
     */
    private static function sections(string $template): array
    {
        $sections = [];
        foreach (explode('<!--!', (string) file_get_contents(dirname(__DIR__, 2) . '/templates/' . $template)) as $part) {
            $end = strpos($part, '-->');
            if ($end !== false && $end > 0) {
                $sections[substr($part, 0, $end)] = substr($part, $end + 3);
            }
        }

        return $sections;
    }

    /**
     * The template's own stylesheets, from its header.
     *
     * @return list<string>
     */
    private static function stylesheets(string $template): array
    {
        preg_match_all("~href=['\"](templates/[^'\"]+\\.css)['\"]~", self::sections($template)['header'] ?? '', $matches);

        return array_values(array_filter(
            array_map(static fn (string $path): string => dirname(__DIR__, 2) . '/' . $path, $matches[1]),
            static fn (string $file): bool => is_file($file) && !str_contains($file, '/common/')
        ));
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
