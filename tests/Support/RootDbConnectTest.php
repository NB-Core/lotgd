<?php

declare(strict_types=1);

namespace Lotgd\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The helper's own promises: it names the file the production code reads, and
 * what it borrows it gives back -- as the same file, with the same mode, or as
 * the same absence.
 *
 * The suite-wide property this class was written for cannot be tested here.
 * "No test deletes the developer's config" is a statement about which code the
 * other ten files call, and a test of this helper passes against a tree where
 * none of them use it; that one is measured by running the suite with a real
 * config present. What belongs here is the part of the helper a reader cannot
 * check by eye.
 *
 * These tests stage the repository root themselves rather than borrowing it
 * through the class under test, and rather than skipping when a real config is
 * present -- which would have meant skipping in exactly the checkout where any
 * of this matters.
 */
final class RootDbConnectTest extends TestCase
{
    private const STASH_SUFFIX = '.held-by-rootdbconnecttest';

    private string $path;
    private string $sidecar;
    private string $displaced;
    private string $stash;

    /**
     * Whether setUp() got as far as taking the root over.
     *
     * tearDown() runs even when setUp() threw, so without this a run that
     * skipped before staging anything would still clear the root -- deleting a
     * config this class never moved. Which is the defect this pull request is
     * about, in the tests that demonstrate the fix.
     */
    private bool $staged = false;

    protected function setUp(): void
    {
        $this->path = RootDbConnect::path();
        $this->sidecar = RootDbConnect::sidecarPath();
        $this->displaced = $this->path . '.left-by-killed-test-run';
        $this->stash = $this->path . self::STASH_SUFFIX;

        // A previous run of this class that died does not get the shutdown
        // handler below. Measured, and it is not what I assumed: under PHPUnit
        // it never runs at all, because PHPUnit registers its own shutdown
        // handler first, and that one reports the premature end and exits --
        // which abandons every handler registered after it.
        //
        // So the way back in is here, on the next run, exactly as
        // RootDbConnect recovers its own sidecar. Only when the root is free,
        // because anything standing there might be a config the developer has
        // since put back, and this class does not decide that by guessing.
        if (file_exists($this->stash) && !file_exists($this->path)) {
            if (!rename($this->stash, $this->path)) {
                self::fail("A previous run left dbconnect.php at $this->stash and it could not be put back");
            }
        }

        // Deliberately not done with RootDbConnect: a test of a borrow that
        // borrows to set itself up cannot tell the two apart.
        if (file_exists($this->stash)) {
            self::markTestSkipped(
                "a previous run left dbconnect.php at $this->stash, and something else is at "
                    . "$this->path -- keep whichever is wanted and remove the other"
            );
        }

        if (file_exists($this->sidecar) || glob($this->displaced . '*')) {
            self::markTestSkipped('the repository root already holds files these tests use');
        }

        if (file_exists($this->path) && !rename($this->path, $this->stash)) {
            self::fail("Could not move $this->path aside for the duration of this test");
        }

        $this->staged = true;

        // Kept even so: outside PHPUnit it does run, and it costs nothing.
        // What it must not be is the thing this class relies on.
        $stash = $this->stash;
        $path = $this->path;
        $displaced = $this->displaced;
        register_shutdown_function(static function () use ($stash, $path, $displaced): void {
            if (!file_exists($stash)) {
                return;
            }

            // And the same rule as well: whatever is standing at the root gets
            // moved out of the way, not deleted, and a directory counts --
            // tests in this very class put one there. Reported by Copilot: a
            // rename onto a directory fails, and the config that this handler
            // exists to rescue would have stayed under the stash name.
            if (file_exists($path)) {
                $aside = $displaced;

                for ($n = 2; file_exists($aside); $n++) {
                    $aside = $displaced . '-' . $n;
                }

                if (!rename($path, $aside)) {
                    // Nothing better is available at shutdown: say where the
                    // config is, since it is about to stay there.
                    fwrite(STDERR, "RootDbConnectTest: dbconnect.php is still at $stash.\n");

                    return;
                }
            }

            rename($stash, $path);
        });
    }

    protected function tearDown(): void
    {
        if (!$this->staged) {
            // setUp() never took the root over, so nothing standing there is
            // this class's to clear.
            return;
        }

        // Reported failures, not ignored ones: a leftover this cannot remove
        // is what the next test would silently run against, and it is what
        // stops the stash going back.
        $leftovers = array_merge(
            [$this->path, $this->sidecar],
            glob($this->displaced . '*') ?: []
        );

        // Collected rather than raised as they happen: self::fail() throws, and
        // raising one here would skip the stash restore below -- stranding the
        // developer's real config on the one path that exists to notice that
        // something went wrong. Reported by Copilot. Put the file back first,
        // then say everything that failed.
        $problems = [];

        foreach ($leftovers as $leftover) {
            if (is_dir($leftover)) {
                if (!rmdir($leftover)) {
                    $problems[] = "could not clear the directory $leftover";
                }

                continue;
            }

            if (is_file($leftover) && !unlink($leftover)) {
                $problems[] = "could not clear $leftover";
            }
        }

        if (file_exists($this->stash) && !rename($this->stash, $this->path)) {
            $problems[] = "could not put $this->path back";
        }

        if ($problems !== []) {
            self::fail('Cleaning up after this test: ' . implode('; ', $problems));
        }
    }

    public function testPathIsTheRepositoryRootAndNotTheTestsDirectory(): void
    {
        $directory = dirname(RootDbConnect::path());

        // Derived, not restated: this file lives in tests/Support, so the root
        // is the directory that *contains* that, which is the one thing an
        // off-by-one in the level count gets wrong -- silently, since it would
        // leave all ten borrowers operating on a file nothing reads while every
        // test still passed.
        self::assertSame(
            realpath($directory . '/tests/Support'),
            realpath(__DIR__),
            'RootDbConnect::path() must sit above tests/, not inside it'
        );

        self::assertFileExists($directory . '/composer.json');
        self::assertFileExists($directory . '/common.php');
        self::assertSame('dbconnect.php', basename(RootDbConnect::path()));
    }

    public function testTheOriginalIsMovedAsideRatherThanCopied(): void
    {
        // A mode a developer would deliberately choose for a file holding
        // database credentials, and the one the first version of this class
        // widened to 0644 by rewriting the file instead of moving it. Reported
        // by Codex.
        file_put_contents($this->path, "<?php return ['DB_NAME' => 'secret'];\n");
        chmod($this->path, 0600);

        $mode = fileperms($this->path) & 0777;
        $inode = fileinode($this->path);
        $contents = file_get_contents($this->path);

        $borrowed = RootDbConnect::takeOver();
        self::assertFileDoesNotExist($this->path, 'takeOver() leaves the root empty');
        self::assertFileExists($this->sidecar, 'and the original waits on disk, not in memory');

        $borrowed->write("<?php return ['fixture' => true];\n");
        $borrowed->restore();

        clearstatcache();
        self::assertSame($contents, file_get_contents($this->path), 'the exact bytes come back');
        self::assertSame($mode, fileperms($this->path) & 0777, 'and the mode with them');
        self::assertSame($inode, fileinode($this->path), 'as the same file, not a copy of it');
        self::assertFileDoesNotExist($this->sidecar);
    }

    public function testAnAbsentConfigIsRestoredAsAnAbsence(): void
    {
        self::assertFileDoesNotExist($this->path);

        $borrowed = RootDbConnect::takeOver();
        $borrowed->write("<?php return ['fixture' => true];\n");
        self::assertFileExists($this->path);

        $borrowed->restore();
        self::assertFileDoesNotExist($this->path, 'restore() puts back the absence too');

        // The shutdown handler runs restore() again on the ordinary path, and
        // must not resurrect the fixture.
        $borrowed->restore();
        self::assertFileDoesNotExist($this->path);
    }

    public function testAConfigLeftByAKilledRunIsPutBackAndNothingIsDeleted(): void
    {
        // Exactly what a Ctrl-C mid-borrow leaves behind: the real config at
        // the sidecar, that run's fixture in its place.
        file_put_contents($this->sidecar, "<?php return ['DB_NAME' => 'real'];\n");
        file_put_contents($this->path, "<?php return ['fixture' => true];\n");

        $borrowed = RootDbConnect::takeOver();
        $borrowed->restore();

        self::assertStringContainsString('real', (string) file_get_contents($this->path));
        self::assertStringContainsString(
            'fixture',
            (string) file_get_contents($this->displaced),
            'what stood in its place is moved aside, never deleted'
        );
    }

    public function testASecondKilledRunDoesNotOverwriteWhatTheFirstMovedAside(): void
    {
        file_put_contents($this->displaced, "<?php return ['from' => 'the first killed run'];\n");
        file_put_contents($this->sidecar, "<?php return ['DB_NAME' => 'real'];\n");
        file_put_contents($this->path, "<?php return ['from' => 'the second killed run'];\n");

        $borrowed = RootDbConnect::takeOver();
        $borrowed->restore();

        self::assertStringContainsString('real', (string) file_get_contents($this->path));
        self::assertStringContainsString(
            'the first killed run',
            (string) file_get_contents($this->displaced),
            'a fixed name would have renamed over this one'
        );
        self::assertStringContainsString(
            'the second killed run',
            (string) file_get_contents($this->displaced . '-2')
        );
    }

    public function testTakeOverEmptiesTheRootEvenWhenWhatIsThereIsNotAFile(): void
    {
        // "Leaves the root empty" has to mean empty. Installer::stage3() asks
        // file_exists(), so a directory left standing reads as a config being
        // present and sends a borrower that needs the root bare down the wrong
        // branch -- while this class reported success.
        mkdir($this->path);

        $borrowed = RootDbConnect::takeOver();

        self::assertFalse(file_exists($this->path), 'nothing is left at the root, of any shape');
        self::assertDirectoryExists($this->sidecar);

        $borrowed->restore();

        self::assertDirectoryExists($this->path, 'and what was borrowed comes back');
    }

    public function testWhatIsBorrowedComesBackEvenWhenOneRenameCannotDoIt(): void
    {
        // A rename replaces a regular file in one step, which is the whole
        // reason restore() reaches for it -- but it will not replace a
        // directory with one, and takeOver() borrows whatever shape is there.
        // Without a second step the borrow is stranded and the suite stays
        // unrunnable until the root is cleared by hand.
        mkdir($this->path);

        $borrowed = RootDbConnect::takeOver();
        $borrowed->write("<?php return ['fixture' => true];\n");
        $borrowed->restore();

        self::assertDirectoryExists($this->path, 'what was borrowed is back');
        self::assertStringContainsString(
            'fixture',
            (string) file_get_contents($this->displaced),
            'and the fixture it could not replace in one step is moved aside, not deleted'
        );
    }

    public function testADirectoryLeftByAKilledRunDoesNotStrandTheConfig(): void
    {
        // A killed run can leave a directory at that path -- nothing stops one
        // taking the name, and Stage6Test's teardown has cleaned one up since
        // long before this class existed. This test makes one to stand in for
        // that wreckage. A recovery that looks only for a regular file walks
        // past it and then cannot put the config back, which blocked every
        // later run, not just the one that died.
        file_put_contents($this->sidecar, "<?php return ['DB_NAME' => 'real'];\n");
        mkdir($this->path);

        $borrowed = RootDbConnect::takeOver();
        $borrowed->restore();

        self::assertFileExists($this->path);
        self::assertStringContainsString('real', (string) file_get_contents($this->path));
        self::assertDirectoryExists($this->displaced, 'the directory is moved aside, not deleted');
    }

    public function testTwoBorrowsAtOnceAreRefusedRatherThanSharingOneBackup(): void
    {
        $borrowed = RootDbConnect::takeOver();

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/already in progress/');
            RootDbConnect::takeOver();
        } finally {
            $borrowed->restore();
        }
    }
}
