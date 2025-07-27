<?php

declare(strict_types=1);

namespace Lotgd\Doctrine;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

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
        $dbConfig = realpath($rootDir . '/dbconnect.php');
        if ($dbConfig && strpos($dbConfig, $rootDir) === 0) {
            $settings = require $dbConfig;
        } else {
            throw new \RuntimeException('dbconnect.php not found');
        }

        $connection = [
            'driver'  => 'pdo_mysql',
            'host'    => $settings['DB_HOST'] ?? 'localhost',
            'dbname'  => $settings['DB_NAME'] ?? '',
            'user'    => $settings['DB_USER'] ?? '',
            'password'=> $settings['DB_PASS'] ?? '',
            'charset' => 'utf8mb4',
            'options' => [
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ],
        ];

        $paths = [$rootDir . '/src/Lotgd/Entity'];

        $cacheDir = ($settings['DB_DATACACHEPATH'] ?? sys_get_temp_dir()) . '/doctrine';

        // Disable metadata caching only when datacache path is not configured
        $isDevMode = empty($settings['DB_USEDATACACHE']) || empty($settings['DB_DATACACHEPATH']);

        if (class_exists(FilesystemAdapter::class)) {
            $cache = new FilesystemAdapter('', 0, $cacheDir);
        } else {
            // Fallback to an in-memory cache when Symfony cache is missing.
            $cache = new ArrayAdapter();
        }

        $config = ORMSetup::createAnnotationMetadataConfiguration(
            $paths,
            $isDevMode,
            null,
            $cache
        );

        return EntityManager::create($connection, $config);
    }
}
