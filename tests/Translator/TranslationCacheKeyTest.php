<?php

declare(strict_types=1);

namespace Lotgd\Tests\Translator;

use Lotgd\Sanitize;
use Lotgd\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Loading and invalidating a namespace's translations use the same key.
 *
 * If they disagree, editing a translation leaves the stale table in the data
 * cache for its full ten minutes and the editor sees no change -- a bug that
 * looks like the save failing.
 *
 * They used to agree by being written twice: once in Translator, once as
 * untranslated_translation_cache_key() in untranslated.php, whose own comment
 * asked the reader to keep them aligned. The test covering it read both files
 * and asserted their spellings matched, which freezes a duplication instead of
 * removing one. There is one function now, and these cases pin what it
 * returns rather than how it is written.
 */
final class TranslationCacheKeyTest extends TestCase
{
    #[DataProvider('provideNamespaces')]
    public function testTheKeyIsBuiltFromTheNamespaceAndLanguage(
        string $namespace,
        string $language,
        string $expected
    ): void {
        self::assertSame($expected, Translator::translationCacheKey($namespace, $language));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function provideNamespaces(): array
    {
        return [
            'an ordinary page namespace' => ['village.php', 'en', 'translations-village.php-en'],
            'a namespace with a path' => ['pages/clan/detail.php', 'de', 'translations-pages/clan/detail.php-de'],
            'an empty namespace' => ['', 'en', 'translations--en'],
        ];
    }

    /**
     * A namespace longer than a URI may be is hashed, not truncated.
     *
     * Truncating would collide: two long namespaces sharing a prefix would
     * land on one key and serve each other's translations.
     */
    public function testAnOverlongNamespaceIsHashed(): void
    {
        $long = str_repeat('a', Sanitize::URI_MAX_LENGTH + 1);

        $key = Translator::translationCacheKey($long, 'en');

        self::assertSame('translations-' . sha1($long) . '-en', $key);
        self::assertStringNotContainsString($long, $key);
    }

    /**
     * The boundary, from both sides.
     */
    public function testTheLengthBoundaryIsExclusive(): void
    {
        $atLimit = str_repeat('b', Sanitize::URI_MAX_LENGTH);
        $overLimit = $atLimit . 'b';

        self::assertSame(
            'translations-' . $atLimit . '-en',
            Translator::translationCacheKey($atLimit, 'en'),
            'exactly at the limit is still used verbatim'
        );
        self::assertSame(
            'translations-' . sha1($overLimit) . '-en',
            Translator::translationCacheKey($overLimit, 'en')
        );
    }

    /**
     * Two long namespaces that share a prefix get different keys.
     *
     * This is what hashing buys over truncating, and it is the case a test
     * comparing two source spellings could never have made.
     */
    public function testTwoOverlongNamespacesDoNotShareAKey(): void
    {
        $prefix = str_repeat('c', Sanitize::URI_MAX_LENGTH);

        self::assertNotSame(
            Translator::translationCacheKey($prefix . 'one', 'en'),
            Translator::translationCacheKey($prefix . 'two', 'en')
        );
    }

    /**
     * One implementation, not two that must be kept in step.
     */
    public function testTheKeyIsBuiltInExactlyOnePlace(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertSame(
            0,
            substr_count((string) file_get_contents($root . '/untranslated.php'), "'translations-'"),
            'untranslated.php must not build the key itself again'
        );
        self::assertSame(
            1,
            substr_count((string) file_get_contents($root . '/src/Lotgd/Translator.php'), "'translations-' . "),
            'and Translator must build it once'
        );
    }
}
