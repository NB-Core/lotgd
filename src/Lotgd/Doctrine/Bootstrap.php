<?php

declare(strict_types=1);

namespace Lotgd\Doctrine;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\Setup;

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
            include_once $dbConfig;
        } else {
            throw new \RuntimeException('dbconnect.php not found');
        }

        $connection = [
            'driver' => 'pdo_mysql',
            'host' => $DB_HOST ?? 'localhost',
            'dbname' => $DB_NAME ?? '',
            'user' => $DB_USER ?? '',
            'password' => $DB_PASS ?? '',
            'charset' => 'utf8mb4',
        ];

        $paths = [$rootDir . '/src/Lotgd/Entity'];
        $config = Setup::createAnnotationMetadataConfiguration($paths, true);

        return EntityManager::create($connection, $config);
    }
}
