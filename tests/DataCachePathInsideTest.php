<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\DataCache;
use PHPUnit\Framework\TestCase;

/**
 * DataCache::isPathInside() decides whether the installer and the admin page
 * warn that the data cache sits in the web root. A false negative leaves the
 * warning silent on exactly the installations that need it, so the cases are
 * the ones a real configuration produces: a directory that does not exist
 * yet, a relative path, a symlink, and a sibling whose name merely starts
 * with the root's name.
 */
final class DataCachePathInsideTest extends TestCase
{
    private string $base;
    private string $root;
    private string $previousCwd;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/lotgd_path_inside_' . uniqid();
        $this->root = $this->base . '/game';
        mkdir($this->root . '/data/cache', 0700, true);
        mkdir($this->base . '/cache', 0700, true);
        mkdir($this->base . '/game-cache', 0700, true);
        $this->previousCwd = (string) getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        $this->removeTree($this->base);
    }

    public function testExistingDirectoryBelowTheRootIsInside(): void
    {
        self::assertTrue(DataCache::isPathInside($this->root . '/data/cache', $this->root));
    }

    public function testTheRootItselfIsInside(): void
    {
        self::assertTrue(DataCache::isPathInside($this->root, $this->root));
        self::assertTrue(DataCache::isPathInside($this->root . '/', $this->root));
    }

    public function testDirectoryThatDoesNotExistYetIsResolvedThroughItsParent(): void
    {
        self::assertTrue(DataCache::isPathInside($this->root . '/not/created/yet', $this->root));
        self::assertFalse(DataCache::isPathInside($this->base . '/cache/not/created', $this->root));
    }

    public function testDirectoryOutsideTheRootIsNotInside(): void
    {
        self::assertFalse(DataCache::isPathInside($this->base . '/cache', $this->root));
    }

    public function testSiblingSharingTheRootsNameAsPrefixIsNotInside(): void
    {
        self::assertFalse(DataCache::isPathInside($this->base . '/game-cache', $this->root));
    }

    public function testParentSegmentsAreApplied(): void
    {
        self::assertFalse(DataCache::isPathInside($this->root . '/../cache', $this->root));
        self::assertFalse(DataCache::isPathInside($this->root . '/missing/../../cache', $this->root));
        self::assertTrue(DataCache::isPathInside($this->root . '/missing/../data', $this->root));
    }

    public function testRelativePathResolvesAgainstTheWorkingDirectory(): void
    {
        chdir($this->root);

        self::assertTrue(DataCache::isPathInside('data/cache', $this->root));
        self::assertFalse(DataCache::isPathInside('../cache', $this->root));
    }

    public function testSymlinkIntoTheRootIsInside(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Creating symlinks needs extra privileges on Windows.');
        }
        $link = $this->base . '/cache-link';
        self::assertTrue(symlink($this->root . '/data/cache', $link));

        self::assertTrue(DataCache::isPathInside($link, $this->root));
    }

    public function testEmptyPathIsNeverInside(): void
    {
        self::assertFalse(DataCache::isPathInside('', $this->root));
        self::assertFalse(DataCache::isPathInside($this->root, ''));
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
