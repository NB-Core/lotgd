<?php

declare(strict_types=1);

namespace Lotgd\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The helper's own two promises: it names the file the production code reads,
 * and what it borrows it gives back.
 *
 * The suite-wide property this class was written for cannot be tested here --
 * "no test deletes the developer's config" is a statement about which code the
 * other suites call, and a test of this helper passes against a tree where none
 * of them use it. That one is measured by running the suite with a real config
 * present. What *is* worth pinning is the part a reader cannot check by eye: a
 * path assembled from a directory count, which would silently point at
 * `tests/dbconnect.php` if the count were off by one, leaving every borrower
 * operating on a file nothing reads.
 */
final class RootDbConnectTest extends TestCase
{
    public function testPathIsTheRepositoryRootAndNotTheTestsDirectory(): void
    {
        $directory = dirname(RootDbConnect::path());

        // Derived, not restated: this file lives in tests/Support, so the root
        // is the directory that *contains* that, which is the one assertion the
        // off-by-one gets wrong.
        self::assertSame(
            realpath($directory . '/tests/Support'),
            realpath(__DIR__),
            'RootDbConnect::path() must sit above tests/, not inside it'
        );

        self::assertFileExists($directory . '/composer.json');
        self::assertFileExists($directory . '/common.php');
        self::assertSame('dbconnect.php', basename(RootDbConnect::path()));
    }

    public function testWhatItBorrowsItGivesBack(): void
    {
        $path = RootDbConnect::path();
        $existedBefore = is_file($path);
        $before = $existedBefore ? file_get_contents($path) : null;

        $borrowed = RootDbConnect::takeOver();
        self::assertFileDoesNotExist($path, 'takeOver() leaves the root empty');

        $borrowed->write("<?php return ['fixture' => true];\n");
        self::assertSame("<?php return ['fixture' => true];\n", file_get_contents($path));

        $borrowed->restore();

        self::assertSame($existedBefore, is_file($path), 'restore() puts back the absence too');
        if ($existedBefore) {
            self::assertSame($before, file_get_contents($path), 'restore() puts back the exact bytes');
        }

        // Idempotent: the shutdown handler runs restore() again on the ordinary
        // path, and must not resurrect the fixture or delete the original.
        $borrowed->restore();
        self::assertSame($existedBefore, is_file($path));
    }
}
