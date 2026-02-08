<?php

declare(strict_types=1);

namespace Lotgd\Doctrine;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Lotgd\Entity\Account;
use Lotgd\MySQL\Database;
use Lotgd\Doctrine\TablePrefixSubscriber;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Bootstrap
{
    /**
     * Create and return an EntityManager using settings from dbconnect.php.
     */
    public static function getEntityManager(): EntityManager
    {
        // The project root is three directories up from this file
        // src/Lotgd/Doctrine/Bootstrap.php -> src/Lotgd -> src -> project root
        $rootDir = dirname(__DIR__, 3);
        global $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PREFIX, $DB_USEDATACACHE, $DB_DATACACHEPATH;

        $dbConfig = realpath($rootDir . '/dbconnect.php');
        if ($dbConfig && strpos($dbConfig, $rootDir) === 0) {
            $settings = require $dbConfig;
        } else {
            throw new \RuntimeException('dbconnect.php not found');
        }

        if (! is_array($settings)) {
            $settings = [
                'DB_HOST'         => $DB_HOST ?? '',
                'DB_USER'         => $DB_USER ?? '',
                'DB_PASS'         => $DB_PASS ?? '',
                'DB_NAME'         => $DB_NAME ?? '',
                'DB_PREFIX'       => $DB_PREFIX ?? '',
                'DB_USEDATACACHE' => $DB_USEDATACACHE ?? 0,
                'DB_DATACACHEPATH' => $DB_DATACACHEPATH ?? '',
            ];
        }

        $DB_HOST = $settings['DB_HOST'] ?? '';
        $DB_USER = $settings['DB_USER'] ?? '';
        $DB_PASS = $settings['DB_PASS'] ?? '';
        $DB_NAME = $settings['DB_NAME'] ?? '';
        $DB_PREFIX = $settings['DB_PREFIX'] ?? '';
        $DB_USEDATACACHE = $settings['DB_USEDATACACHE'] ?? 0;
        $DB_DATACACHEPATH = $settings['DB_DATACACHEPATH'] ?? '';

        Database::setPrefix($DB_PREFIX);

        $connection = [
            'driver'       => 'pdo_mysql',
            'host'         => $settings['DB_HOST'] ?? 'localhost',
            'dbname'       => $settings['DB_NAME'] ?? '',
            'user'         => $settings['DB_USER'] ?? '',
            'password'     => $settings['DB_PASS'] ?? '',
            'charset'      => 'utf8mb4',
            // Use buffered queries to avoid "Cannot execute queries while other
            // unbuffered queries are active" errors when using PDO.
            'driverOptions' => [
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ],
        ];

        $path = !empty($settings['DB_DATACACHEPATH']) ? $settings['DB_DATACACHEPATH'] : sys_get_temp_dir();
        $cacheDir = $path . '/doctrine';

        if (! empty($settings['DB_DATACACHEPATH'])) {
            if (! is_dir($cacheDir) && ! mkdir($cacheDir, 0775, true) && ! is_dir($cacheDir)) {
                throw new \RuntimeException('Doctrine metadata cache directory could not be created: ' . $cacheDir);
            }

            if (! is_writable($cacheDir)) {
                throw new \RuntimeException('Doctrine metadata cache directory is not writable: ' . $cacheDir);
            }

            self::clearDoctrineMetadataCacheIfNeeded($cacheDir);
        }

        // Disable metadata caching only when datacache path is not configured
        $isDevMode = empty($settings['DB_USEDATACACHE']) || empty($settings['DB_DATACACHEPATH']);

        // Include the table prefix in the cache namespace so metadata isn't reused across different prefixes.
        if (class_exists(FilesystemAdapter::class)) {
            $cache = new FilesystemAdapter($DB_PREFIX, 0, $cacheDir);
        } else {
            // Fallback to an in-memory cache when Symfony cache is missing.
            $cache = (new ArrayAdapter())->withSubNamespace($DB_PREFIX);
        }

        $paths = [$rootDir . '/src/Lotgd/Entity'];
        $config = ORMSetup::createAttributeMetadataConfiguration($paths, $isDevMode, null, $cache);

        $eventManager = new EventManager();
        $eventManager->addEventSubscriber(new TablePrefixSubscriber($DB_PREFIX));

        $connection['event_manager'] = $eventManager;
        $doctrineConnection = DriverManager::getConnection($connection);

        $entityManager = new EntityManager($doctrineConnection, $config, $eventManager);

        try {
            $accountMetadata = $entityManager->getClassMetadata(Account::class);
            if ($accountMetadata->getIdentifierFieldNames() === []) {
                throw new \RuntimeException(
                    'Doctrine metadata for Account has no identifiers. Clear the doctrine metadata cache at ' . $cacheDir
                );
            }
        } catch (ConnectionException $exception) {
            // Skip metadata sanity checks when the connection cannot be established (e.g., legacy config tests).
        }

        return $entityManager;
    }

    /**
     * Clear Doctrine metadata cache (exposed for tests).
     */
    public static function clearDoctrineMetadataCacheForTests(string $cacheDir): void
    {
        self::clearDoctrineMetadataCache($cacheDir);
    }

    /**
     * Clear directory contents using the fallback logic (exposed for tests).
     */
    public static function clearDirectoryContentsFallbackForTests(string $cacheDir): void
    {
        self::clearDirectoryContentsFallback($cacheDir);
    }

    private static function clearDoctrineMetadataCache(string $cacheDir): void
    {
        if (! is_dir($cacheDir)) {
            return;
        }

        try {
            $cache = new FilesystemAdapter('', 0, $cacheDir);

            if (! $cache->clear()) {
                self::logCacheClearWarning(
                    $cacheDir,
                    null,
                    new \RuntimeException('FilesystemAdapter::clear returned false')
                );
            }
        } catch (\Throwable $exception) {
            self::logCacheClearWarning($cacheDir, null, $exception);
        }

        try {
            self::clearDirectoryContentsFallback($cacheDir);
        } catch (\Throwable $exception) {
            self::logCacheClearWarning($cacheDir, null, $exception);
        }
    }

    private static function clearDoctrineMetadataCacheIfNeeded(string $cacheDir): void
    {
        $markerPath = $cacheDir . DIRECTORY_SEPARATOR . '.doctrine_cache_version';
        $cacheVersion = '1';

        if (is_file($markerPath)) {
            $markerContents = file_get_contents($markerPath);

            if ($markerContents !== false && trim($markerContents) === $cacheVersion) {
                return;
            }
        }

        self::clearDoctrineMetadataCache($cacheDir);

        if (file_put_contents($markerPath, $cacheVersion) === false) {
            self::logCacheClearWarning(
                $cacheDir,
                $markerPath,
                new \RuntimeException('Unable to write Doctrine cache version marker.')
            );
        }
    }

    /**
     * @throws \RuntimeException
     */
    private static function clearDirectoryContentsFallback(string $cacheDir): void
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
        } catch (\UnexpectedValueException) {
            // Directory vanished between checks; treat as already cleared.
            return;
        }

        $normalizedCacheDir = rtrim($cacheDir, DIRECTORY_SEPARATOR);
        $traversalStart = microtime(true);

        foreach ($iterator as $item) {
            $path = $item->getPathname();

            try {
                if (! self::isPathWithinCacheDir($path, $normalizedCacheDir)) {
                    continue;
                }

                if (self::isPathInActiveNamespace($path, $normalizedCacheDir)) {
                    continue;
                }

                if ($item->isDir()) {
                    if (! is_dir($path)) {
                        continue;
                    }

                    if (self::isPathActive($path, $traversalStart)) {
                        continue;
                    }

                    if (! rmdir($path) && is_dir($path)) {
                        if (! self::isDirectoryEmpty($path)) {
                            continue;
                        }

                        if (self::isPathActive($path, $traversalStart)) {
                            continue;
                        }

                        self::logCacheClearWarning(
                            $cacheDir,
                            $path,
                            new \RuntimeException('Unable to remove directory.')
                        );
                    }

                    continue;
                }

                if (! is_file($path) && ! is_link($path)) {
                    continue;
                }

                if (self::isPathActive($path, $traversalStart)) {
                    continue;
                }

                if (! unlink($path) && (is_file($path) || is_link($path))) {
                    if (self::isPathActive($path, $traversalStart)) {
                        continue;
                    }

                    self::logCacheClearWarning(
                        $cacheDir,
                        $path,
                        new \RuntimeException('Unable to remove file.')
                    );
                }
            } catch (\UnexpectedValueException) {
                // Entry disappeared while traversing; continue with remaining nodes.
                continue;
            }
        }
    }

    private static function isPathWithinCacheDir(string $path, string $cacheDir): bool
    {
        if ($path === $cacheDir) {
            return true;
        }

        $prefix = $cacheDir . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix);
    }

    private static function isPathActive(string $path, float $traversalStart): bool
    {
        if (! file_exists($path)) {
            return false;
        }

        $changedAt = filectime($path);

        if ($changedAt !== false && $changedAt > $traversalStart) {
            return true;
        }

        $modifiedAt = filemtime($path);

        if ($modifiedAt === false) {
            return false;
        }

        return $modifiedAt > $traversalStart && $modifiedAt <= time() + 2;
    }

    private static function isPathInActiveNamespace(string $path, string $cacheDir): bool
    {
        $relativePath = ltrim(substr($path, strlen($cacheDir)), DIRECTORY_SEPARATOR);

        if ($relativePath === '') {
            return false;
        }

        $segments = explode(DIRECTORY_SEPARATOR, $relativePath);
        $namespace = $segments[0] ?? '';

        if ($namespace === '' || $namespace[0] !== '@') {
            return false;
        }

        $namespacePath = $cacheDir . DIRECTORY_SEPARATOR . $namespace;

        if (! is_dir($namespacePath)) {
            return false;
        }

        return ! self::isDirectoryEmpty($namespacePath);
    }

    private static function isDirectoryEmpty(string $path): bool
    {
        try {
            $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
        } catch (\UnexpectedValueException) {
            return true;
        }

        foreach ($iterator as $item) {
            return false;
        }

        return true;
    }

    private static function logCacheClearWarning(string $cacheDir, ?string $path, \Throwable $exception): void
    {
        error_log(sprintf(
            'Doctrine metadata cache clear warning: cacheDir="%s" path="%s" error="%s"',
            $cacheDir,
            $path ?? 'n/a',
            $exception->getMessage()
        ));
    }
}
