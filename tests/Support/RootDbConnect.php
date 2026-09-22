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
 * Restoration also happens on shutdown, because the failure this guards against
 * is the one where tearDown() never runs.
 */
final class RootDbConnect
{
    private bool $restored = false;

    private function __construct(
        private readonly string $path,
        private readonly ?string $original,
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
     * Take the file over: remember what was there and leave the root empty.
     *
     * The caller is then free to write whatever fixture it needs, or to rely on
     * there being none.
     */
    public static function takeOver(): self
    {
        $path = self::path();
        $original = null;

        if (is_file($path)) {
            $contents = file_get_contents($path);
            $original = $contents === false ? null : $contents;

            if ($original === null) {
                throw new \RuntimeException(
                    "A dbconnect.php exists at $path but could not be read, and this would have to "
                        . 'delete it to run. Refusing, because it may be a real configuration.'
                );
            }

            if (!unlink($path)) {
                throw new \RuntimeException(
                    "A dbconnect.php exists at $path and could not be removed. Refusing to go on: "
                        . 'the fixture would be written over a real configuration instead of beside it.'
                );
            }
        }

        $borrowed = new self($path, $original);
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
     * Put back exactly what was there -- including nothing, if there was nothing.
     */
    public function restore(): void
    {
        if ($this->restored) {
            return;
        }

        if ($this->original === null) {
            if (is_file($this->path) && !unlink($this->path)) {
                throw new \RuntimeException(
                    "Could not remove the dbconnect.php fixture at $this->path. The root started "
                        . 'out with no such file and now has one.'
                );
            }
        } elseif (file_put_contents($this->path, $this->original) === false) {
            // The one failure this class exists to prevent, so it is never
            // silent: the borrowed contents live only in this process, and the
            // process is on its way out.
            throw new \RuntimeException(
                "Could not restore the original dbconnect.php at $this->path. It has been borrowed "
                    . 'and not given back.'
            );
        }

        // Only now: a restore that threw is one the shutdown handler should try
        // again, not one it skips because a flag was set before the attempt.
        $this->restored = true;
        $this->forget();
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
