<?php

declare(strict_types=1);

namespace Lotgd\Tests\Support;

/**
 * Borrow the repository's `dbconnect.php` for the length of a test, and give it
 * back.
 *
 * Several suites need a `dbconnect.php` at the project root, or need there to be
 * none: Bootstrap, Settings, Database and common.php all resolve it from their
 * own position on disk (`dirname(__DIR__, 3) . '/dbconnect.php'` and friends),
 * so there is no path to point somewhere else. The tests therefore write and
 * delete the real file.
 *
 * Four of the five did that by deleting it outright. In a checkout that is also
 * an installed game -- which a developer's working copy usually is -- that is
 * the database configuration, it is gitignored so git will not bring it back,
 * and it went on an ordinary `composer test` rather than on a crash. Measured
 * before this class existed: a stand-in config placed at the root did not
 * survive a single run of BootstrapCharsetTest.
 *
 * The fifth, Stage5Test, already saved the contents and put them back. This is
 * that, extracted, so there is one copy of it and four fewer places to forget.
 *
 * It borrows by **moving the file aside**, not by copying its contents into
 * memory. A file holding database credentials is then never read, never
 * rewritten, and never recreated -- it is the same inode when it comes back,
 * with its mode, its owner and its timestamps intact. Reported by Codex against
 * the first version, which read and rewrote it: a config a developer had
 * deliberately set to `0600` came back `0644`, because the rewrite got whatever
 * the umask gave it. Measured before the change and after:
 *
 *     vorher:  600 dbconnect.php  ->  nach borrow/restore:  644
 *     jetzt:   600 dbconnect.php  ->  nach borrow/restore:  600
 *
 * Moving it aside also means the file still exists on disk while it is
 * borrowed, so a run killed outright -- Ctrl-C, SIGKILL, anything that skips
 * the shutdown handler below -- leaves it recoverable rather than gone. The
 * next takeOver() puts it back.
 *
 * Restoration otherwise happens on shutdown, because the failure this guards
 * against is the one where tearDown() never runs.
 */
final class RootDbConnect
{
    /**
     * Where the original waits while it is borrowed.
     *
     * Beside the file itself, because a rename is only atomic within one
     * filesystem and the root is the one directory guaranteed to be on the same
     * one. Listed in .gitignore next to `dbconnect.php`, for the crash case
     * where it is still there afterwards.
     */
    private const SIDECAR_SUFFIX = '.borrowed-by-tests';

    /**
     * Where a file found in the borrowed one's place is put, rather than
     * deleted.
     */
    private const DISPLACED_SUFFIX = '.left-by-killed-test-run';

    /**
     * The borrow currently in progress, if any.
     *
     * There is exactly one sidecar name, so two live borrows would both believe
     * they own it and the second restore would find nothing to put back. No
     * caller nests them; this makes the attempt say so instead of corrupting
     * the backup.
     */
    private static ?self $active = null;

    private bool $restored = false;

    private function __construct(
        private readonly string $path,
        private readonly bool $holdsOriginal,
    ) {
    }

    /**
     * Where the production code looks for it.
     *
     * This file is `tests/Support/RootDbConnect.php`, so two levels up from its
     * own directory is the repository root -- the same place `Bootstrap`,
     * `Settings`, `Database` and `common.php` each arrive at from their own
     * positions. A test asserts that equality rather than restating the count,
     * because getting it wrong would make every suite here operate on a file
     * nothing reads.
     */
    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/dbconnect.php';
    }

    /**
     * Where a borrowed original waits.
     */
    public static function sidecarPath(): string
    {
        return self::path() . self::SIDECAR_SUFFIX;
    }

    /**
     * Take the file over: move anything that is there aside, leaving the root
     * empty.
     *
     * The caller is then free to write whatever fixture it needs, or to rely on
     * there being none.
     */
    public static function takeOver(): self
    {
        if (self::$active !== null) {
            throw new \RuntimeException(
                'A dbconnect.php borrow is already in progress. Nesting them is not supported: '
                    . 'there is one place the original is kept, and the second restore would find '
                    . 'it already given back.'
            );
        }

        $path = self::path();
        $sidecar = self::sidecarPath();

        self::recoverAbandonedSidecar($path, $sidecar);

        $holdsOriginal = false;

        if (is_file($path)) {
            if (!rename($path, $sidecar)) {
                throw new \RuntimeException(
                    "A dbconnect.php exists at $path and could not be moved aside. Refusing to go "
                        . 'on: the fixture would be written over a real configuration instead of '
                        . 'beside it.'
                );
            }

            $holdsOriginal = true;
        }

        $borrowed = new self($path, $holdsOriginal);
        self::$active = $borrowed;
        $borrowed->forget();

        // tearDown() is exactly what does not run when a test dies, and that is
        // the case this guards. Idempotent, so the ordinary path still restores
        // at the ordinary time.
        register_shutdown_function(static function () use ($borrowed): void {
            try {
                $borrowed->restore();
            } catch (\RuntimeException $e) {
                // Last chance, and an exception thrown here is an uncatchable
                // fatal on top of whatever already went wrong -- which would
                // bury the test output that says why. Say it where it can still
                // be read instead.
                fwrite(STDERR, $e->getMessage() . "\n");
            }
        });

        return $borrowed;
    }

    /**
     * Write a fixture in place of the borrowed file.
     */
    public function write(string $contents): void
    {
        if (file_put_contents($this->path, $contents) === false) {
            throw new \RuntimeException("Could not write the dbconnect.php fixture to $this->path");
        }

        $this->forget();
    }

    /**
     * Put back exactly what was there -- including nothing, if there was
     * nothing.
     */
    public function restore(): void
    {
        if ($this->restored) {
            return;
        }

        if ($this->holdsOriginal) {
            $sidecar = self::sidecarPath();

            if (!is_file($sidecar)) {
                throw new \RuntimeException(
                    "The borrowed dbconnect.php is no longer at $sidecar, so there is nothing to "
                        . 'put back. Something outside these tests moved or deleted it.'
                );
            }

            // Replaces the fixture in one step, so the root is never briefly
            // without a config.
            if (!rename($sidecar, $this->path)) {
                throw new \RuntimeException(
                    "Could not move the borrowed dbconnect.php back from $sidecar to $this->path. "
                        . 'It has been borrowed and not given back.'
                );
            }
        } elseif (is_file($this->path) && !unlink($this->path)) {
            throw new \RuntimeException(
                "Could not remove the dbconnect.php fixture at $this->path. The root started out "
                    . 'with no such file and now has one.'
            );
        }

        // Only now: a restore that threw is one the shutdown handler should try
        // again, not one it skips because a flag was set before the attempt.
        $this->restored = true;

        if (self::$active === $this) {
            self::$active = null;
        }

        $this->forget();
    }

    /**
     * Put back a config left behind by a run that never got to restore it.
     *
     * Only takeOver() ever creates the sidecar, and only from a file that was
     * at the root before any fixture was written -- so the sidecar is the real
     * configuration, and it wins.
     *
     * Whatever is at the root in that situation is almost always the fixture
     * the killed run had just written, which is worth nothing. Almost: a
     * developer could have re-run the installer since. So it is moved aside
     * rather than deleted, and said out loud. Nothing this class touches is
     * ever destroyed to make room for something else -- that is the whole
     * point of it.
     */
    private static function recoverAbandonedSidecar(string $path, string $sidecar): void
    {
        if (!is_file($sidecar)) {
            return;
        }

        if (is_file($path)) {
            $displaced = $path . self::DISPLACED_SUFFIX;

            if (!rename($path, $displaced)) {
                throw new \RuntimeException(
                    "A previous run left a borrowed dbconnect.php at $sidecar, and the file now at "
                        . "$path could not be moved out of the way to put it back."
                );
            }

            fwrite(
                STDERR,
                "RootDbConnect: a previous run was killed before it could give dbconnect.php back. "
                    . "Restoring it. What was in its place -- almost certainly that run's fixture "
                    . "-- is at $displaced.\n"
            );
        }

        if (!rename($sidecar, $path)) {
            throw new \RuntimeException(
                "A previous run left a borrowed dbconnect.php at $sidecar and it could not be put "
                    . "back at $path."
            );
        }
    }

    /**
     * Drop anything remembered about the file at that path.
     *
     * The production code `require`s it, and a suite that rewrites it within
     * the same second would otherwise be served the previous contents wherever
     * opcache is on for the CLI. It is off by default and off in CI -- but that
     * is a setting, not a property of the code.
     */
    private function forget(): void
    {
        clearstatcache(true, $this->path);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->path, true);
        }
    }
}
