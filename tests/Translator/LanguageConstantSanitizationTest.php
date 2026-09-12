<?php

declare(strict_types=1);

namespace Lotgd\Tests\Translator;

use Lotgd\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The language preference is stripped to letters before it becomes LANGUAGE.
 *
 * It matters because LANGUAGE is concatenated into queries rather than bound:
 * a language cannot be a parameter everywhere it is used, and the defence is
 * that the value cannot carry a quote by the time it is a constant. The value
 * itself is user-controlled -- it comes from the account's language
 * preference, or failing that from the `language` cookie.
 *
 * This was guarded from the other end until now, by
 * tests/Legacy/HighRiskSqlMigrationTest.php asserting that the string
 * ". LANGUAGE ." did not appear in translatortool.php. That assertion could
 * not fail for the right reason: it said nothing about whether the constant
 * was safe, only about where it was spelled -- and the sanitiser everything
 * depends on had no test at all.
 */
final class LanguageConstantSanitizationTest extends TestCase
{
    /**
     * @param string $preference
     */
    #[DataProvider('provideLanguagePreferences')]
    public function testALanguagePreferenceIsReducedToLetters(string $preference, string $expected): void
    {
        $normalized = Translator::normalizeLanguageCode($preference);

        self::assertSame($expected, $normalized);
        foreach (["'", '"', '\\', ';', ' ', '%'] as $dangerous) {
            self::assertStringNotContainsString($dangerous, $normalized);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideLanguagePreferences(): array
    {
        return [
            'an ordinary code is untouched' => ['de', 'de'],
            'a regional code loses its separator' => ['pt-BR', 'ptBR'],
            'a quote cannot survive' => ["en' OR '1'='1", 'enOR'],
            'nor can a backslash and a statement separator' => ['de\\"; DROP TABLE accounts; --', 'deDROPTABLEaccounts'],
            'nor a comment marker' => ['en/*', 'en'],
            'a wildcard is not a letter either' => ['%', ''],
            'an empty preference stays empty' => ['', ''],
        ];
    }

    /**
     * The constant is built by that method rather than by a copy of it.
     *
     * Without this the method could be correct while translatorSetup() kept
     * its own expression, which is the shape of every drifted guard this
     * audit has turned up.
     */
    public function testTranslatorSetupBuildsTheConstantFromThatMethod(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Lotgd/Translator.php');

        self::assertStringContainsString(
            "define('LANGUAGE', self::normalizeLanguageCode(\$language));",
            $source
        );
        self::assertSame(
            1,
            substr_count($source, "preg_replace('/[^a-z]/i'"),
            'the pattern lives in exactly one place'
        );
    }
}
