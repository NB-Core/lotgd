<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\BootstrapErrorHandler;
use PHPUnit\Framework\TestCase;

/**
 * cron.php logs a failure in common.php rather than dying quietly.
 *
 * This used to read the real logs/bootstrap.log: delete it, run the
 * subprocess, assert the file exists with the marker in it, delete it again.
 * That made a process-global file part of the test, and the file is one *any*
 * subprocess in this suite can write -- the bootstrap error handler appends
 * every warning to it. So an unrelated test that shells out could put a line
 * in the file this one had just emptied, and this one could delete lines
 * somebody else was appending; and two PHPUnit processes running at once would
 * delete the file out from under each other. It failed exactly that way during
 * the executing-CSRF work (#1537), which is what brought it to attention.
 *
 * The subprocess now writes to a file of this test's own, handed to it through
 * LOTGD_BOOTSTRAP_LOG. Nothing here touches the shared log, so nothing else in
 * the suite can disturb this test and this test can disturb nothing else.
 */
final class CronCommonExceptionTest extends TestCase
{
    private string $logFile = '';

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lotgd-bootstrap-log-');
        self::assertIsString($path, 'the test needs a log file of its own');
        $this->logFile = $path;
    }

    protected function tearDown(): void
    {
        if ($this->logFile !== '' && file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /**
     * Put LOTGD_BOOTSTRAP_LOG back exactly as it was.
     *
     * getenv() distinguishes *unset* (false) from *set but empty* (''), and the
     * first version of this did not: it unset the variable in both cases, so a
     * suite run with `LOTGD_BOOTSTRAP_LOG=` exported would come out of these
     * tests with it gone. A test quietly changing process-wide state for every
     * test after it -- which is the failure this whole pull request is about,
     * reproduced in miniature inside the fix for it. Reported by Copilot.
     *
     * One helper rather than the two copies it replaces, so a third cannot
     * drift from the others.
     */
    private static function restoreLogEnv(string|false $previous): void
    {
        if ($previous === false) {
            putenv(BootstrapErrorHandler::LOG_FILE_ENV);

            return;
        }

        putenv(BootstrapErrorHandler::LOG_FILE_ENV . '=' . $previous);
    }

    public function testExceptionInCommonIsLogged(): void
    {
        // Passed on the command line rather than through putenv(), so it
        // reaches this one child and no other test in this process.
        $command = sprintf(
            '%s=%s %s %s',
            BootstrapErrorHandler::LOG_FILE_ENV,
            escapeshellarg($this->logFile),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/cron_common_exception.php')
        );

        // exec() rather than shell_exec(), because the exit status is evidence
        // and shell_exec() throws it away. stderr is folded in so a failure
        // arrives with the reason attached rather than as "the file has the
        // wrong contents".
        $transcript = [];
        $status = 0;
        exec($command . ' 2>&1', $transcript, $status);

        // 1, not 0: cron.php exits 1 by design when common.php throws, which is
        // exactly the situation this run constructs -- a cron that failed
        // should tell its scheduler so. Checking the status at all is the
        // point; the old shell_exec() discarded it, so this test could not have
        // told a reported failure from a subprocess that died on its way there.
        // The harness itself bails out with 3 for that reason.
        self::assertSame(
            1,
            $status,
            "the cron subprocess did not report the failure the way cron.php should:\n"
            . implode(PHP_EOL, $transcript)
        );

        // A clean run says nothing at all, so anything on the transcript is a
        // finding -- above all the harness's own cleanup diagnostics, which are
        // written to stderr precisely so somebody notices them.
        //
        // Without this the capture was theatre: the cleanup reported a failure,
        // exec() caught it, the status stayed at the 1 cron.php is supposed to
        // exit with, and the transcript was used only to decorate assertion
        // messages that never fired. Green CI while farms pile up -- which is
        // what the previous round of this was supposed to prevent, and my own
        // note claiming the diagnostic "will name the next occurrence" was
        // wrong, because nothing read it. Reported by Codex.
        //
        // It also means a stray PHP notice from the subprocess fails this test.
        // That is the intent rather than a side effect: a run that printed
        // something unexpected is not a run this test should call clean.
        self::assertSame(
            [],
            $transcript,
            'the cron subprocess printed something it should not have'
        );

        // Deliberately not assertFileExists(): tempnam() creates the file, so
        // that assertion passes whether or not the subprocess ever wrote a
        // line. It was in the first version of this test and proved nothing --
        // the same shape of tautology this audit has been removing elsewhere,
        // introduced by the very change that gave the test its own file.
        // Asserted rather than cast: a read failure would otherwise arrive as
        // "the marker is missing", sending the next reader after the
        // subprocess when the problem is the file. Same shape of masking this
        // audit removed from AccountRestorepageTest on #1535.
        $log = file_get_contents($this->logFile);
        self::assertIsString($log, "the log file could not be read:\n" . implode(PHP_EOL, $transcript));

        // Both halves, because they come from different places and only
        // together say the failure was reported rather than merely thrown.
        // The first is cron.php's own wording; the second is the exception it
        // caught. They used to be the same words -- the stand-in threw "Cron
        // common.php failure" too -- so the line carried the phrase twice and
        // rewording cron.php's half left this test green.
        self::assertStringContainsString(
            'Cron common.php failure',
            $log,
            "cron.php did not log its own failure line:\n" . implode(PHP_EOL, $transcript)
        );
        self::assertStringContainsString(
            'the stand-in common.php refused to load',
            $log,
            "the exception's own message was not carried into the log:\n" . implode(PHP_EOL, $transcript)
        );
    }

    /**
     * The override is honoured, and only when it says something.
     *
     * Asserted on the resolved path rather than by writing, because writing is
     * what the default case cannot do: its target is the shared file, and
     * proving "the default is still logs/bootstrap.log" by appending to
     * logs/bootstrap.log would reintroduce exactly the coupling this change
     * removes.
     */
    public function testTheLogPathFollowsTheEnvironmentAndOtherwiseDoesNotMove(): void
    {
        $previous = getenv(BootstrapErrorHandler::LOG_FILE_ENV);

        try {
            putenv(BootstrapErrorHandler::LOG_FILE_ENV);
            $default = BootstrapErrorHandler::logFile();
            self::assertStringEndsWith('/logs/bootstrap.log', $default);
            self::assertSame(
                realpath(dirname(__DIR__)),
                realpath(dirname($default, 2)),
                'an installation that sets nothing must log where it always has'
            );

            putenv(BootstrapErrorHandler::LOG_FILE_ENV . '=' . $this->logFile);
            self::assertSame($this->logFile, BootstrapErrorHandler::logFile());

            // An empty value is not a path, and treating it as one would send
            // every entry to a file named "" -- silently losing the log for
            // anyone who exports the variable without setting it.
            putenv(BootstrapErrorHandler::LOG_FILE_ENV . '=');
            self::assertSame($default, BootstrapErrorHandler::logFile());
        } finally {
            self::restoreLogEnv($previous);
        }
    }

    /**
     * And an entry really lands in the overridden file.
     *
     * The pair to the case above: that one pins the path, this one pins that
     * the path is the one written to. Without it the resolver could be correct
     * and log() could still ignore it.
     */
    public function testAnEntryIsWrittenToTheOverriddenFile(): void
    {
        $previous = getenv(BootstrapErrorHandler::LOG_FILE_ENV);

        try {
            putenv(BootstrapErrorHandler::LOG_FILE_ENV . '=' . $this->logFile);
            BootstrapErrorHandler::log('a marker only this test writes');
        } finally {
            self::restoreLogEnv($previous);
        }

        $log = file_get_contents($this->logFile);
        self::assertIsString($log, 'the log file could not be read');
        self::assertStringContainsString('a marker only this test writes', $log);
    }
}
