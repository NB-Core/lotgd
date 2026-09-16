<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security\PageCsrf;

use PHPUnit\Framework\Assert;

/**
 * Runs a root page with and without its form token, and reports what SQL it
 * issued and what it rendered.
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

    /**
     * What the child process wraps its result in.
     *
     * Named rather than `@@@`, and matched non-greedily below. A page under
     * test writes arbitrary content to the same stream when PHP reports a
     * warning, so a short marker is a sequence the payload can plausibly
     * contain -- and with a greedy match one occurrence anywhere ahead of the
     * real result swallows everything between. Reported by Copilot.
     */
    private const RESULT_MARKER = '@@@LOTGD-PAGE-RESULT@@@';

    private static ?string $farm = null;

    /**
     * @param array<string,string> $get
     * @param array<string,string> $post
     * @param array<string,mixed>  $globals Tables the page reads, by global name.
     */
    public static function withToken(
        string $page,
        int $superuser,
        array $get = [],
        array $post = [],
        array $globals = []
    ): PageOutcome {
        return self::run($page, $superuser, $get, $post, self::TOKEN, $globals);
    }

    /**
     * @param array<string,string> $get
     * @param array<string,string> $post
     * @param array<string,mixed>  $globals Tables the page reads, by global name.
     */
    public static function withoutToken(
        string $page,
        int $superuser,
        array $get = [],
        array $post = [],
        array $globals = []
    ): PageOutcome {
        return self::run($page, $superuser, $get, $post, null, $globals);
    }

    /**
     * @param array<string,string> $get
     * @param array<string,string> $post
     * @param array<string,mixed>  $globals Tables the page reads, by global name.
     */
    private static function run(
        string $page,
        int $superuser,
        array $get,
        array $post,
        ?string $token,
        array $globals = []
    ): PageOutcome {
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
            'globals' => $globals,
        ];

        // The page's own markup goes to a file of this run's own rather than to
        // /dev/null, so an assertion can be about what the page rendered. The
        // two streams stay separate: `2>&1` points stderr at the pipe
        // shell_exec reads, and `1>$htmlFile` then sends stdout to the file, so
        // the result payload cannot be diluted by the page's output. A file
        // rather than a second buffer inside the child because the child is
        // free to flush, end or clean its own output buffers, and one that
        // vanished mid-run would take the evidence with it.
        $htmlFile = tempnam(sys_get_temp_dir(), 'lotgd-page-html-');
        if ($htmlFile === false) {
            Assert::fail('The page harness could not create a file to capture the output of ' . $page);
        }

        $command = sprintf(
            '%s %s %s 2>&1 1>%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/tests/Security/PageCsrf/run_page.php'),
            escapeshellarg(json_encode($spec, JSON_THROW_ON_ERROR)),
            escapeshellarg($htmlFile)
        );

        try {
            $output = (string) shell_exec($command);
            $html = file_get_contents($htmlFile);
        } finally {
            // Registered against the whole block, so a failed assertion below
            // does not leave the file behind: tempnam() creates it, so there is
            // always something to remove even when the child wrote nothing.
            unlink($htmlFile);
        }

        // Asserted rather than cast. `false` here means the capture file could
        // not be read, and casting it to '' would arrive downstream as "the
        // page rendered nothing" -- a page that failed to run looking exactly
        // like a page that rendered an empty bar.
        Assert::assertIsString($html, "The page harness could not read back what $page rendered");

        // The LAST complete marker pair, not the first: the result is written
        // from the shutdown handler, so it is always last, while anything the
        // page printed to this stream before it is not the result. A leading
        // greedy `.*` pushes the opening marker as late as it can, and the
        // non-greedy body then closes at the next one.
        //
        // Matching the first pair -- which is what a plain non-greedy match
        // does -- picks up a decoy instead. Measured rather than argued: with
        // a stray pair ahead of the real one, the first-pair match captures
        // the text between the decoys and the decode fails, which is loud but
        // for the wrong reason and on a run that was fine.
        $marker = preg_quote(self::RESULT_MARKER, '/');
        if (preg_match('/.*' . $marker . '(.*?)' . $marker . '/s', $output, $matches) !== 1) {
            Assert::fail(
                "The page harness produced no result for $page.\n"
                . "That means the process died before its shutdown handler ran, so nothing can be "
                . "concluded from it -- in particular not that the page did nothing.\n\n"
                . substr($output, 0, 2000)
            );
        }

        try {
            /** @var array{statements: list<string>, queries: list<string>, status: int|false, fatal: string|null} $decoded */
            $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            Assert::fail(
                "The page harness produced an unreadable result for $page, so nothing can be concluded "
                . "from this run: {$exception->getMessage()}\n\n" . substr($matches[1], 0, 2000)
            );
        }

        // A fatal produces a result like any other run, because PHP runs
        // shutdown handlers after one -- so the payload decodes, the statement
        // list is empty, and the refusal assertions pass. Refusing it here is
        // what keeps "the page changed nothing" separate from "the page never
        // ran", which is the one distinction this whole harness rests on.
        if (($decoded['fatal'] ?? null) !== null) {
            Assert::fail(
                "The page harness died while running $page, so nothing can be concluded from this run:\n"
                . $decoded['fatal']
            );
        }

        return new PageOutcome($decoded['statements'], $decoded['queries'], $decoded['status'], $html);
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

        // Every setup step here is checked, and for one reason: each of them
        // failing quietly produces a *clean-looking* run. Without the directory
        // there are no symlinks; without a symlink the page's relative lookups
        // miss; without dbconnect.php common.php decides the game is not
        // installed and exits at its footer -- and all three arrive at the same
        // place, a result with an empty statement list, which reads as "the
        // page did nothing" and passes every refusal assertion in this suite.
        // That is the exact failure this harness exists to rule out, and three
        // instances of it were still here. Reported by Copilot.
        //
        // The trailing is_dir() is not redundant: two processes racing on the
        // same farm both see it missing and one mkdir() loses.
        if (!is_dir($farm) && !mkdir($farm, 0777, true) && !is_dir($farm)) {
            Assert::fail("The page harness could not create its working directory at $farm");
        }

        foreach ((array) scandir($root) as $entry) {
            if (in_array($entry, ['.', '..', 'installer.php', 'dbconnect.php'], true)) {
                continue;
            }
            $link = $farm . '/' . $entry;
            // The trailing is_link() closes the same race the mkdir above
            // does, and it is here because the mkdir had it and this did not:
            // between the check and the call another process can create the
            // link, symlink() then fails with EEXIST, and a run that was
            // perfectly fine fails. Guarding one of a pair and not the other
            // is the shape of mistake this audit has hit repeatedly.
            // Reported by Copilot.
            if (!file_exists($link) && !is_link($link) && !symlink($root . '/' . $entry, $link) && !is_link($link)) {
                Assert::fail("The page harness could not link $entry into its working directory");
            }
        }

        // The farm is reused between runs, so a stray installer.php in it --
        // left by an older exclusion list, or by somebody debugging by hand --
        // would make common.php render "Major Security Risk" and exit, and
        // every positive control in the suite would fail at once with no hint
        // as to why. The directory belongs to this harness, so clean it rather
        // than merely complain about it; complain only if it will not go.
        $stray = $farm . '/installer.php';
        if ((file_exists($stray) || is_link($stray)) && (!unlink($stray) || file_exists($stray))) {
            Assert::fail(
                "The page harness found an installer.php in its working directory and could not remove it: "
                . "$stray. common.php exits while that file is present, so no page would run. Delete $farm."
            );
        }

        $written = file_put_contents(
            $farm . '/dbconnect.php',
            "<?php\n\nreturn ['DB_HOST' => 'localhost', 'DB_USER' => 'harness', 'DB_PASS' => '',"
            . " 'DB_NAME' => 'harness', 'DB_PREFIX' => '', 'DB_USEDATACACHE' => 0, 'DB_DATACACHEPATH' => ''];\n"
        );
        if ($written === false) {
            Assert::fail("The page harness could not write $farm/dbconnect.php, without which no page runs");
        }

        self::$farm = $farm;

        return $farm;
    }
}
