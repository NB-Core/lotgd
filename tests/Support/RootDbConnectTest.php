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

    protected function setUp(): void
    {
        $this->path = RootDbConnect::path();
        $this->sidecar = RootDbConnect::sidecarPath();
        $this->displaced = $this->path . '.left-by-killed-test-run';
        $this->stash = $this->path . self::STASH_SUFFIX;

        // Deliberately not done with RootDbConnect: a test of a borrow that
        // borrows to set itself up cannot tell the two apart.
        if (file_exists($this->sidecar) || file_exists($this->stash) || glob($this->displaced . '*')) {
            self::markTestSkipped('the repository root already holds files these tests use');
        }

        if (file_exists($this->path) && !rename($this->path, $this->stash)) {
            self::fail("Could not move $this->path aside for the duration of this test");
        }

        // The same promise the class under test makes, and for the same reason:
        // tearDown() is exactly what does not run when the process is killed,
        // and this test moves the developer's real config aside under a name
        // nothing else knows. Reported by Copilot -- these tests had the very
        // defect the pull request exists to fix.
        $stash = $this->stash;
        $path = $this->path;
        register_shutdown_function(static function () use ($stash, $path): void {
            if (is_file($stash) && !is_file($path)) {
                rename($stash, $path);
            }
        });
    }

    protected function tearDown(): void
    {
        // Reported failures, not ignored ones: a leftover this cannot remove
        // is what the next test would silently run against, and it is what
        // stops the stash going back.
        $leftovers = array_merge(
            [$this->path, $this->sidecar],
            glob($this->displaced . '*') ?: []
        );

        foreach ($leftovers as $leftover) {
            if (is_dir($leftover)) {
                if (!rmdir($leftover)) {
                    self::fail("Could not clear the directory $leftover after this test");
                }

                continue;
            }

            if (is_file($leftover) && !unlink($leftover)) {
                self::fail("Could not clear $leftover after this test");
            }
        }

        if (is_file($this->stash) && !rename($this->stash, $this->path)) {
            self::fail("Could not put $this->path back after this test");
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
