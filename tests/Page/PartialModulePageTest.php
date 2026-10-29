<?php

declare(strict_types=1);

namespace Lotgd\Tests\Page;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * Module pages (runmodule.php) change in place where the module declares
 * them under its info key `partial`, read from the module's stored row
 * before any module code runs. A page that is not declared loads in full as
 * before; one that turns out not to be guarded by the page revision falls
 * back to the stored page.
 */
final class PartialModulePageTest extends TestCase
{
    private const PARTIAL_REQUEST = ['HTTP_HX_REQUEST' => 'true'];
    private const REVISION_CLAIM = 'SET page_revision = page_revision + 1';
    private const SETTINGS = ['partial_render' => 1, 'defaultskin' => 'twig:aurora', 'show_error_details' => 1];
    private const UNGUARDED = 'zzpartialunguarded';

    protected function tearDown(): void
    {
        @unlink(dirname(__DIR__, 2) . '/modules/' . self::UNGUARDED . '.php');
    }

    public function testADeclaredModulePageChangesInPlace(): void
    {
        $get = ['module' => 'lovers', 'op' => 'chat'];
        $run = $this->partialGet($get, $this->moduleCache('lovers', '{"GET":["chat","flirt"]}'));

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertNull($run->header('X-Lotgd-Partial-Fallback'));
        self::assertCount(1, $run->statementsContaining(self::REVISION_CLAIM));
        self::assertStringNotContainsString('<script', $run->html);
        self::assertStringContainsString('inn.php', $run->html, 'The module ran and added its navigation.');
    }

    public function testAModulePageNotDeclaredLoadsInFullBeforeItRuns(): void
    {
        $run = PageRunner::withRequest(
            'runmodule.php',
            0,
            'GET',
            ['module' => 'lovers', 'op' => 'chat'],
            [],
            $this->globals($this->moduleCache('lovers', '{"GET":["flirt"]}')),
            self::SETTINGS,
            self::PARTIAL_REQUEST
        );

        self::assertSame('runmodule.php?module=lovers&op=chat', $run->header('HX-Redirect'), self::context($run));
        self::assertSame([], $run->statementsContaining(self::REVISION_CLAIM), 'The page must not have run.');
    }

    public function testAModulePageThatSkipsForcedNavigationFallsBackToTheStoredPage(): void
    {
        $file = dirname(__DIR__, 2) . '/modules/' . self::UNGUARDED . '.php';
        file_put_contents($file, <<<'PHP'
<?php
function zzpartialunguarded_getmoduleinfo(): array
{
    return ['name' => 'Unguarded', 'version' => '1.0', 'category' => 'Test', 'override_forced_nav' => true,
        'partial' => ['GET' => ['']]];
}
function zzpartialunguarded_install(): bool { return true; }
function zzpartialunguarded_uninstall(): bool { return true; }
function zzpartialunguarded_run(): void
{
    page_header('Unguarded');
    output('Nothing guards this page.');
    addnav('Village', 'village.php');
    page_footer();
}
PHP);
        $get = ['module' => self::UNGUARDED];
        $run = $this->partialGet($get, $this->moduleCache(self::UNGUARDED, '{"GET":[""]}'));

        self::assertSame('claim', $run->header('X-Lotgd-Partial-Fallback'), self::context($run));
        self::assertSame('badnav.php', $run->header('HX-Redirect'));
    }

    /**
     * @param array<string,string> $get
     * @param array<string,mixed>  $cache
     */
    private function partialGet(array $get, array $cache): PageOutcome
    {
        $full = PageRunner::withRequest('runmodule.php', 0, 'GET', $get, [], $this->globals($cache), self::SETTINGS);
        self::assertSame(1, preg_match('/<meta name="lotgd-head" content="([0-9a-f]+)">/', $full->html, $match), self::context($full));

        return PageRunner::withRequest(
            'runmodule.php',
            0,
            'GET',
            $get,
            [],
            $this->globals($cache),
            self::SETTINGS,
            self::PARTIAL_REQUEST + ['HTTP_X_LOTGD_HEAD' => $match[1]]
        );
    }

    /**
     * The module's stored row, as inject() and the partial decision read it.
     *
     * @return array<string,mixed>
     */
    private function moduleCache(string $module, string $partial): array
    {
        $file = dirname(__DIR__, 2) . '/modules/' . $module . '.php';
        $row = [
            'modulename' => $module, 'active' => 1, 'infokeys' => '|name|version|partial|', 'version' => '1.0',
            'filemoddate' => is_file($file) ? date('Y-m-d H:i:s', (int) filemtime($file)) : '2020-01-01 00:00:00',
            'filehash' => is_file($file) ? sha1_file($file) : str_repeat('0', 40), 'partial' => $partial,
        ];

        // Twice: the stub's fetchAssoc() takes the row out of the result.
        return ['inject-' . $module => [$row, $row]];
    }

    /**
     * @param array<string,mixed> $cache
     * @return array<string,mixed>
     */
    private function globals(array $cache): array
    {
        return [
            'accounts_table' => [1 => [
                'alive' => 1, 'location' => 'Degolburg', 'level' => 5, 'gold' => 500, 'gems' => 10,
                'hitpoints' => 50, 'maxhitpoints' => 50, 'lasthit' => date('Y-m-d H:i:s'),
                'prefs' => serialize(['partialrender' => 1, 'sexuality' => 0]), 'page_revision' => 0,
            ]],
            '__query_cache' => $cache,
        ];
    }

    private static function context(PageOutcome $run): string
    {
        $error = preg_match('~<h1>Application Error</h1>\s*<p>(.*?)</p>~s', $run->html, $m) === 1 ? $m[1] : '';

        return 'headers: ' . implode(' | ', $run->headers) . "\nerror: " . $error . "\nbody: " . substr($run->html, 0, 1500);
    }
}
