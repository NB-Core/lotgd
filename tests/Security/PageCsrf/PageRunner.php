<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security\PageCsrf;

use PHPUnit\Framework\Assert;

/**
 * Runs a root page with and without its form token, and reports what SQL it
 * issued.
 *
 * The point of this class is the pair. Asserting that a page issues no DELETE
 * without a token is worthless on its own -- a page that never ran issues no
 * DELETE either, and every early version of this harness was exactly that:
 * common.php ended the request at one of its five exit paths and the
 * "nothing happened" assertion passed for the wrong reason. So every case here
 * runs the same request twice, and the test asserts the state change *does*
 * happen with a valid token before asserting it does not without one.
 *
 * @see run_page.php for the process on the other side, and for why each
 *      fixture element is needed.
 */
final class PageRunner
{
    public const TOKEN = 'harness-token-harness-token-harness-token-harness-token-hars';

    private static ?string $farm = null;

    /**
     * @param array<string,string> $get
     * @param array<string,string> $post
     */
    public static function withToken(string $page, int $superuser, array $get = [], array $post = []): PageOutcome
    {
        return self::run($page, $superuser, $get, $post, self::TOKEN);
    }

    /**
     * @param array<string,string> $get
     * @param array<string,string> $post
     */
    public static function withoutToken(string $page, int $superuser, array $get = [], array $post = []): PageOutcome
    {
        return self::run($page, $superuser, $get, $post, null);
    }

    /**
     * @param array<string,string> $get
     * @param array<string,string> $post
     */
    private static function run(string $page, int $superuser, array $get, array $post, ?string $token): PageOutcome
    {
        $root = dirname(__DIR__, 3);

        $spec = [
            'root' => $root,
            'farm' => self::farm($root),
            'page' => $page,
            'superuser' => $superuser,
            'get' => $get,
            'post' => $post,
            'token' => $token,
            'version' => self::version($root),
        ];

        $command = sprintf(
            '%s %s %s 2>&1 1>/dev/null',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/tests/Security/PageCsrf/run_page.php'),
            escapeshellarg(json_encode($spec, JSON_THROW_ON_ERROR))
        );

        $output = (string) shell_exec($command);

        if (preg_match('/@@@(.*)@@@/s', $output, $matches) !== 1) {
            Assert::fail(
                "The page harness produced no result for $page.\n"
                . "That means the process died before its shutdown handler ran, so nothing can be "
                . "concluded from it -- in particular not that the page did nothing.\n\n"
                . substr($output, 0, 2000)
            );
        }

        /** @var array{statements: list<string>, queries: list<string>, status: int|false} $decoded */
        $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return new PageOutcome($decoded['statements'], $decoded['queries'], $decoded['status']);
    }

    /**
     * The game's own version string, read from where the game reads it.
     *
     * Hard-coding it here would make this harness silently stop working at the
     * next release: the settings row it seeds has to match, or common.php
     * renders "Upgrade Needed" and exits before the page runs.
     */
    private static function version(string $root): string
    {
        $common = (string) file_get_contents($root . '/common.php');
        Assert::assertSame(
            1,
            preg_match('/\$logd_version\s*=\s*"([^"]+)"/', $common, $matches),
            'common.php must still declare $logd_version for the harness to match it'
        );

        return $matches[1];
    }

    /**
     * A directory that looks like an installed game, for the page to run from.
     *
     * Two things differ from the repository checkout, and both are the normal
     * state of a deployed game rather than a convenience:
     *
     *   - installer.php is absent. An installed game deletes it, and common.php
     *     renders a "Major Security Risk" page that exits while it is present.
     *   - dbconnect.php exists. Without it common.php decides the game has not
     *     been installed and exits. Its contents do not matter, because the
     *     Database stub answers every query, but the file must be there.
     *
     * Symlinks rather than copies so the page under test is the real file.
     */
    private static function farm(string $root): string
    {
        if (self::$farm !== null) {
            return self::$farm;
        }

        $farm = sys_get_temp_dir() . '/lotgd-page-csrf-' . substr(sha1($root), 0, 12);
        if (!is_dir($farm)) {
            mkdir($farm, 0777, true);
        }

        foreach ((array) scandir($root) as $entry) {
            if (in_array($entry, ['.', '..', 'installer.php', 'dbconnect.php'], true)) {
                continue;
            }
            $link = $farm . '/' . $entry;
            if (!file_exists($link) && !is_link($link)) {
                symlink($root . '/' . $entry, $link);
            }
        }

        file_put_contents(
            $farm . '/dbconnect.php',
            "<?php\n\nreturn ['DB_HOST' => 'localhost', 'DB_USER' => 'harness', 'DB_PASS' => '',"
            . " 'DB_NAME' => 'harness', 'DB_PREFIX' => '', 'DB_USEDATACACHE' => 0, 'DB_DATACACHEPATH' => ''];\n"
        );

        self::$farm = $farm;

        return $farm;
    }
}
