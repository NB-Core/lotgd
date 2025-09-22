<?php

declare(strict_types=1);

namespace Lotgd\Doctrine;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\Common\EventManager;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Lotgd\MySQL\Database;
use Lotgd\Doctrine\TablePrefixSubscriber;

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

        $paths = [$rootDir . '/src/Lotgd/Entity'];

        $path = !empty($settings['DB_DATACACHEPATH']) ? $settings['DB_DATACACHEPATH'] : sys_get_temp_dir();
        $cacheDir = $path . '/doctrine';

        // Disable metadata caching only when datacache path is not configured
        $isDevMode = empty($settings['DB_USEDATACACHE']) || empty($settings['DB_DATACACHEPATH']);

        // Include the table prefix in the cache namespace so metadata isn't reused across different prefixes.
        if (class_exists(FilesystemAdapter::class)) {
            $cache = new FilesystemAdapter($DB_PREFIX, 0, $cacheDir);
        } else {
            // Fallback to an in-memory cache when Symfony cache is missing.
            $cache = (new ArrayAdapter())->withSubNamespace($DB_PREFIX);
        }

        $config = ORMSetup::createAnnotationMetadataConfiguration(
            $paths,
            $isDevMode,
            null,
            $cache
        );

        $eventManager = new EventManager();
        $eventManager->addEventSubscriber(new TablePrefixSubscriber($DB_PREFIX));

        return EntityManager::create($connection, $config, $eventManager);
    }
}
