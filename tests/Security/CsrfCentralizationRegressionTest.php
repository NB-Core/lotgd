<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keeps CSRF handling in one place.
 *
 * The point of the shared helper is not that the seven old copies were wrong —
 * they were not — but that a copy is where the next divergence starts. These
 * assertions are negative on purpose: a positive-only test ("the file mentions
 * Csrf::") passes on a half-migration that adds the new call and leaves the old
 * recipe next to it.
 */
final class CsrfCentralizationRegressionTest extends TestCase
{
    /**
     * Every file that had its own token recipe.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideMigratedSites(): array
    {
        return [
            'armor editor' => ['armoreditor.php', 'SCOPE_ARMOR_EDITOR'],
            'weapon editor' => ['weaponeditor.php', 'SCOPE_WEAPON_EDITOR'],
            'mount editor' => ['mounts.php', 'SCOPE_MOUNT_EDITOR'],
            'companion editor' => ['companions.php', 'SCOPE_COMPANION_EDITOR'],
            'motd poll form' => ['src/Lotgd/Motd.php', 'SCOPE_MOTD_VOTE'],
            'motd vote handler' => ['motd.php', 'SCOPE_MOTD_VOTE'],
            'charrestore module' => ['modules/charrestore/lib/restore.php', 'SCOPE_CHARRESTORE'],
            'twofactorauth module' => ['modules/twofactorauth.php', 'SCOPE_TWOFACTORAUTH'],
            'passkey async handler' => ['src/Lotgd/Async/Handler/TwoFactorAuthPasskey.php', 'SCOPE_TWOFACTORAUTH'],
        ];
    }

    #[DataProvider('provideMigratedSites')]
    public function testSiteUsesTheSharedHelper(string $path, string $scope): void
    {
        $source = $this->source($path);

        self::assertStringContainsString("Csrf::$scope", $source, "$path must name its scope");
    }

    /**
     * The half-migration guard. Adding a `Csrf::` call while leaving the old
     * block in place must not pass.
     */
    #[DataProvider('provideMigratedSites')]
    public function testSiteNoLongerCarriesItsOwnRecipe(string $path, string $scope): void
    {
        $source = $this->source($path);

        // Deliberately per line and tied to the word "csrf": a blanket ban on
        // random_bytes() would also forbid the HMAC signing key that
        // twofactorauth.php legitimately generates for its disable tokens.
        foreach (explode("\n", $source) as $number => $line) {
            if (stripos($line, 'csrf') === false) {
                continue;
            }
            self::assertStringNotContainsString(
                'random_bytes(',
                $line,
                "$path:" . ($number + 1) . " must not mint its own CSRF token"
            );
        }

        self::assertStringNotContainsString(
            "_csrf']",
            $source,
            "$path must not reach into the session for a CSRF token"
        );
    }

    /**
     * One comparison, in one place. hash_equals() elsewhere in these files is
     * how the seven copies drifted apart.
     */
    #[DataProvider('provideMigratedSites')]
    public function testSiteDoesNotCompareTokensItself(string $path, string $scope): void
    {
        $source = $this->source($path);

        foreach (['hash_equals(twofactorauth_csrf_token()', 'hash_equals($csrfToken', 'hash_equals($expectedCsrf'] as $pattern) {
            self::assertStringNotContainsString($pattern, $source, "$path must not compare CSRF tokens itself");
        }
    }

    /**
     * The reason the passkey handler was worth migrating: it had a second
     * validator that branched on whether module functions happened to be
     * loaded, because async/process.php does not load them.
     */
    public function testPasskeyHandlerNoLongerForksOnModuleAvailability(): void
    {
        $source = $this->source('src/Lotgd/Async/Handler/TwoFactorAuthPasskey.php');

        self::assertStringNotContainsString("function_exists('twofactorauth_csrf_token')", $source);
        self::assertStringNotContainsString("\$GLOBALS['session']['twofactorauth_csrf']", $source);
        self::assertStringContainsString('Csrf::matches(Csrf::SCOPE_TWOFACTORAUTH', $source);
    }

    /**
     * The module comment that asked for exactly this helper.
     */
    public function testCharrestoreNoLongerAsksForACoreApi(): void
    {
        self::assertStringNotContainsString(
            'Core verification needed',
            $this->source('modules/charrestore/lib/restore.php')
        );
    }

    /**
     * A page that hands its whole POST body to a settings or preference writer
     * must drop the token first, or it gets persisted as data.
     */
    public function testCompanionSaveStripsTheTokenBeforePersisting(): void
    {
        $source = $this->source('companions.php');

        self::assertStringContainsString('Csrf::stripFrom(Http::allPost())', $source);
    }

    /**
     * Async *request handling* must never call the generating methods.
     * async/process.php releases the session lock before dispatch for
     * read-only callables, so a token created there is returned once and then
     * lost — which shows up as an intermittent mismatch rather than an obvious
     * failure.
     *
     * The rule is about the request-handling path, not about the directory.
     * async/setup.php also lives under async/ but runs during a normal page
     * render, included from the page footer with the session open, and issuing
     * the token is exactly its job. Scanning by directory would have made that
     * legitimate call look like a violation.
     */
    public function testAsyncRequestHandlingNeverGeneratesAToken(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/async/process.php'];
        foreach (['/async/common', '/src/Lotgd/Async'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        self::assertNotSame([], $files, 'expected async sources to scan');
        self::assertContains($root . '/async/process.php', $files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach (['Csrf::token(', 'Csrf::escapedToken(', 'Csrf::hiddenField('] as $generator) {
                self::assertStringNotContainsString(
                    $generator,
                    $source,
                    basename($file) . " must not generate a CSRF token; use Csrf::matches() or a validate*() call"
                );
            }
        }
    }

    /**
     * The other half of that rule: the page-render side must issue the token,
     * or the endpoint check has nothing to compare against.
     */
    public function testAsyncSetupIssuesTheToken(): void
    {
        self::assertStringContainsString(
            'Csrf::token(\\Lotgd\\Security\\Csrf::SCOPE_ASYNC)',
            $this->source('async/setup.php')
        );
    }

    private function source(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
    }
}
