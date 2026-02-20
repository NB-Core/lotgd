<?php

declare(strict_types=1);

namespace Lotgd\Tests\Doctrine;

use Lotgd\Doctrine\Bootstrap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

final class BootstrapMetadataCacheClearTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir() . '/lotgd_bootstrap_cache_' . bin2hex(random_bytes(8));

        if (! mkdir($concurrentDirectory = $this->workspace, 0775, true) && ! is_dir($concurrentDirectory)) {
            self::fail('Unable to create test workspace directory');
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
        parent::tearDown();
    }

    public function testClearDoctrineMetadataCacheHandlesMissingSubdirectory(): void
    {
        $missing = $this->workspace . '/missing-doctrine-dir';

        $this->invokeClearDoctrineMetadataCache($missing);

        self::assertDirectoryDoesNotExist($missing);
    }

    public function testClearDoctrineMetadataCacheClearsNormalTree(): void
    {
        $cacheDir = $this->workspace . '/doctrine';
        $cache = new FilesystemAdapter('normal', 0, $cacheDir);
        $item = $cache->getItem('entity.account');
        $item->set(['id']);
        $cache->save($item);

        self::assertTrue($cache->getItem('entity.account')->isHit());

        $this->invokeClearDoctrineMetadataCache($cacheDir);

        $reloadedCache = new FilesystemAdapter('normal', 0, $cacheDir);
        self::assertDirectoryExists($cacheDir);
        self::assertFalse($reloadedCache->getItem('entity.account')->isHit());
    }

    public function testClearDoctrineMetadataCacheHandlesPartiallyDeletedTree(): void
    {
        $cacheDir = $this->workspace . '/doctrine';
        $cache = new FilesystemAdapter('partial', 0, $cacheDir);
        $item = $cache->getItem('entity.weapon');
        $item->set(['weapon']);
        $cache->save($item);

        mkdir($cacheDir . '/deleted/branch', 0775, true);
        file_put_contents($cacheDir . '/deleted/branch/stale.cache', 'legacy');
        $this->removeTree($cacheDir . '/deleted');

        $this->invokeClearDoctrineMetadataCache($cacheDir);

        $reloadedCache = new FilesystemAdapter('partial', 0, $cacheDir);
        self::assertDirectoryExists($cacheDir);
        self::assertFalse($reloadedCache->getItem('entity.weapon')->isHit());
    }

    public function testClearDoctrineMetadataCacheHandlesDisappearingDirectoriesDuringTraversal(): void
    {
        $cacheDir = $this->workspace . '/doctrine';
        $namespaceDir = $cacheDir . '/@namespace';
        $staleDir = $namespaceDir . '/stale';
        $staleFile = $staleDir . '/metadata.php';

        mkdir($staleDir, 0775, true);
        file_put_contents($staleFile, 'stale');

        $process = $this->spawnDelayedDeletion($staleFile, $staleDir);

        $this->invokeClearDoctrineMetadataCache($cacheDir);

        if (is_resource($process)) {
            proc_close($process);
        }

        self::assertDirectoryExists($cacheDir);
    }

    public function testClearDoctrineMetadataCacheSkipsNonEmptyNamespaceDirectory(): void
    {
        $cacheDir = $this->workspace . '/doctrine';
        $namespaceDir = $cacheDir . '/@legacy';
        $activeFile = $namespaceDir . '/active.cache';

        mkdir($namespaceDir, 0775, true);
        file_put_contents($activeFile, 'active');

        chmod($namespaceDir, 0555);

        try {
            $this->invokeClearDoctrineMetadataCache($cacheDir);
        } finally {
            chmod($namespaceDir, 0775);
        }

        self::assertDirectoryExists($namespaceDir);
        self::assertFileExists($activeFile);
    }

    public function testClearDoctrineMetadataCacheDoesNotAbortOnRemovalFailure(): void
    {
        $cacheDir = $this->workspace . '/doctrine';
        $blockedFile = $cacheDir . '/blocked.cache';

        mkdir($cacheDir, 0775, true);
        file_put_contents($blockedFile, 'blocked');

        chmod($cacheDir, 0555);

        try {
            $this->invokeClearDoctrineMetadataCache($cacheDir);
        } finally {
            chmod($cacheDir, 0775);
        }

        self::assertDirectoryExists($cacheDir);
    }

    public function testClearDirectoryContentsFallbackSkipsActivePaths(): void
    {
        $cacheDir = $this->workspace . '/doctrine';
        $activeFile = $cacheDir . '/active.cache';

        mkdir($cacheDir, 0775, true);
        file_put_contents($activeFile, 'active');
        touch($activeFile, time() + 1);

        $this->invokeClearDirectoryContentsFallback($cacheDir);

        self::assertFileExists($activeFile);
    }

    private function invokeClearDoctrineMetadataCache(string $cacheDir): void
    {
        $bootstrapClass = $this->resolveBootstrapClass();
        $bootstrapClass::clearDoctrineMetadataCacheForTests($cacheDir);
    }

    private function invokeClearDirectoryContentsFallback(string $cacheDir): void
    {
        $bootstrapClass = $this->resolveBootstrapClass();
        $bootstrapClass::clearDirectoryContentsFallbackForTests($cacheDir);
    }

    /**
     * @return resource|null
     */
    private function spawnDelayedDeletion(string $filePath, string $directoryPath)
    {
        $script = sprintf(
            'usleep(20000); if (is_file(%1$s)) { unlink(%1$s); } if (is_dir(%2$s)) { rmdir(%2$s); }',
            var_export($filePath, true),
            var_export($directoryPath, true)
        );

        return proc_open(
            [PHP_BINARY, '-r', $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
    }


    /**
     * @return class-string
     */
    private function resolveBootstrapClass(): string
    {
        $originalClass = Bootstrap::class;
        $bootstrapFile = (new \ReflectionClass($originalClass))->getFileName();
        $realFile = realpath(__DIR__ . '/../../src/Lotgd/Doctrine/Bootstrap.php');

        if ($realFile === false) {
            throw new \RuntimeException('Unable to locate Doctrine bootstrap source file');
        }

        if ($bootstrapFile === $realFile) {
            return $originalClass;
        }

        $realClass = '\\Lotgd\\Tests\\Doctrine\\RealBootstrapCache\\Bootstrap';

        if (! class_exists($realClass)) {
            $contents = file_get_contents($realFile);
            $contents = str_replace('namespace Lotgd\\Doctrine;', 'namespace Lotgd\\Tests\\Doctrine\\RealBootstrapCache;', (string) $contents);
            $contents = str_replace('dirname(__DIR__, 3)', var_export(dirname(__DIR__, 2), true), (string) $contents);
            $contents = preg_replace('/^<\?php\s*/', '', (string) $contents);

            if ($contents === null) {
                throw new \RuntimeException('Unable to load real Doctrine bootstrap for testing');
            }

            eval($contents);
        }

        return $realClass;
    }

    private function removeTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
