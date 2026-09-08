<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `module` request value reaches two output contexts in the object-preference
 * editors: a single-quoted `action` attribute, and a Nav::add() URL.
 *
 * Unencoded it broke out of the attribute on a `'`, and it widened
 * `$session['allowednavs']` with whatever it contained — SECURITY.md lists that
 * second one as a documented blind spot of the navigation allowlist.
 */
final class ModuleParameterEncodingRegressionTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function provideEditorPages(): array
    {
        return [
            'companion editor' => ['companions.php'],
            'creature editor' => ['creatures.php'],
        ];
    }

    #[DataProvider('provideEditorPages')]
    public function testModuleIsEncodedBeforeItReachesAUrl(string $path): void
    {
        $source = $this->source($path);

        self::assertStringContainsString(
            '$moduleParam = rawurlencode((string) $module);',
            $source,
            "$path must encode the module name before putting it in a URL"
        );

        // The negative half. A positive-only assertion passes while an
        // unencoded interpolation still sits next to the encoded one.
        self::assertStringNotContainsString(
            'module=$module\'',
            $source,
            "$path still interpolates the raw module name into a URL"
        );
        self::assertStringNotContainsString(
            'module=$module"',
            $source,
            "$path still interpolates the raw module name into a URL"
        );
    }

    /**
     * The hook takes the module name, not the URL-encoded form: encoding it
     * there would break the lookup for any name needing an escape.
     */
    public function testTheHookStillReceivesTheRawName(): void
    {
        self::assertStringContainsString(
            'HookHandler::objprefEdit("companions", $module, $id);',
            $this->source('companions.php')
        );
        self::assertStringContainsString(
            'module_objpref_edit("creatures", $module, $id);',
            $this->source('creatures.php')
        );
    }

    /**
     * rawurlencode() leaves the characters a real module name is made of
     * alone, so this is not a behaviour change for any existing module, and
     * removes the two that mattered.
     */
    public function testEncodingIsInertForRealModuleNamesAndNeutralisesQuotes(): void
    {
        foreach (['cities', 'twofactorauth', 'charrestore', 'smallcaptcha_111'] as $name) {
            self::assertSame($name, rawurlencode($name), "$name must survive encoding unchanged");
        }

        self::assertStringNotContainsString("'", rawurlencode("x' onmouseover=alert(1) '"));
        self::assertStringNotContainsString('"', rawurlencode('x" onmouseover=alert(1) "'));
        self::assertStringNotContainsString('<', rawurlencode('<script>'));
    }

    private function source(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
    }
}
