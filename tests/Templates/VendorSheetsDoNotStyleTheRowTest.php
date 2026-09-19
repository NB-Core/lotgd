<?php

declare(strict_types=1);

namespace Lotgd\Tests\Templates;

use Lotgd\Tests\Support\StyleSheets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one sheet the theme model leaves out, and the reason it may.
 *
 * Every Twig head opens with `{{ 'bootstrap'|asset('css') }}`, resolved through
 * assets/vendor/manifest.json, so a browser loads it before the theme's own
 * sheets. StyleSheets::themeSheets() does not, and its docblock said a theme is
 * "the set of sheets its template links" -- which promised the full cascade and
 * delivered less. Reported by Copilot.
 *
 * Including it would be the wrong fix: it is not a sheet a theme author edits,
 * and 232KB of minified vendor CSS would be parsed on every run to add nothing.
 * Adding nothing is the part that has to stay true, and a sentence in a
 * docblock cannot stay true by itself -- the last blanket claim about which
 * sheets could be ignored is what let `sidebar.css` disappear from every
 * assertion in this suite. So the exclusion is checked rather than merely
 * explained: the day a vendor sheet starts styling one of these classes, this
 * goes red and names it.
 */
final class VendorSheetsDoNotStyleTheRowTest extends TestCase
{
    /**
     * The classes the styling assertions in this suite are about.
     */
    private const OURS = ['button', 'action-bar', 'mail-nav'];

    /**
     * Every stylesheet the vendor manifest offers, not only the one in use.
     *
     * `datatables` is not linked by a bundled head today; a theme that linked
     * it tomorrow would be making the same bet, so it is held to the same
     * claim now rather than when someone notices.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function vendorStylesheets(): iterable
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode((string) file_get_contents($root . '/assets/vendor/manifest.json'), true);

        if (!is_array($manifest)) {
            throw new \RuntimeException('assets/vendor/manifest.json is what the asset filter reads, and it did not parse');
        }

        foreach ($manifest as $library => $entry) {
            if (!is_array($entry) || !isset($entry['css'])) {
                continue;
            }

            $path = $root . '/' . ltrim((string) $entry['css'], '/');
            if (!is_file($path)) {
                continue;
            }

            yield (string) $library => [(string) $library, $path];
        }
    }

    #[DataProvider('vendorStylesheets')]
    public function testAVendorSheetStylesNoneOfOurClasses(string $library, string $path): void
    {
        $selectors = StyleSheets::selectorsIn((string) file_get_contents($path));

        self::assertNotEmpty($selectors, "precondition: $library parsed into selectors at all");

        foreach (self::OURS as $class) {
            $hits = array_values(array_filter(
                $selectors,
                static fn (string $selector): bool => StyleSheets::mentionsClass($selector, $class)
            ));

            self::assertSame(
                [],
                $hits,
                "$library now styles .$class, so leaving it out of StyleSheets::themeSheets() "
                    . 'no longer costs nothing -- either include it there or narrow this claim'
            );
        }
    }
}
