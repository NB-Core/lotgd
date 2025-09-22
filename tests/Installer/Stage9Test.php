<?php

declare(strict_types=1);

namespace Doctrine\Migrations\Configuration\EntityManager {
    class ExistingEntityManager
    {
        public function __construct(private readonly mixed $em)
        {
        }
    }
}

namespace Doctrine\Migrations {
    use Doctrine\Migrations\Version\ExecutionResult;
    use Doctrine\Migrations\Version\Version;
    use Symfony\Component\Console\Input\ArrayInput;

    class DependencyFactory
    {
        public static ?self $instance = null;
        /** @var Version[] */
        public array $migrated = [];
        public mixed $configuration = null;
        /** @var array<string,mixed> */
        public array $configurationData = [];
        public ?string $metadataTable = null;

        public static function fromEntityManager(mixed $config, mixed $em): self
        {
            $instance = new self();
            $instance->configuration = $config;

            if (is_array($config)) {
                $instance->configurationData = $config;
                $instance->metadataTable    = $config['table_storage']['table_name'] ?? null;
            } elseif (is_object($config)) {
                $ref = new \ReflectionClass($config);
                if ($ref->hasProperty('configurations')) {
                    $prop = $ref->getProperty('configurations');
                    $prop->setAccessible(true);
                    $data = $prop->getValue($config);
                    if (is_array($data)) {
                        $instance->configurationData = $data;
                        $instance->metadataTable    = $data['table_storage']['table_name'] ?? null;
                    }
                }
            }

            return self::$instance = $instance;
        }

        public function getMetadataStorage(): object
        {
            return new class {
                public function ensureInitialized(): void
                {
                }
                public function getExecutedMigrations(): object
                {
                    return new class {
                        public function hasMigration(Version $v): bool
                        {
                            return false;
                        }
                    };
                }
                public function complete(ExecutionResult $result): void
                {
                }
            };
        }

        public function getVersionAliasResolver(): object
        {
            return new class {
                public function resolveVersionAlias(string $alias): Version
                {
                    $files = glob(dirname(__DIR__, 2) . '/migrations/Version*.php');
                    $ids   = array_map(fn($f) => substr(basename($f, '.php'), 7), $files);
                    rsort($ids);
                    return new Version($ids[0]);
                }
            };
        }

        public function getMigrationPlanCalculator(): object
        {
            return new class {
                public function getPlanUntilVersion(Version $v): array
                {
                    return [$v];
                }
            };
        }

        public function getConsoleInputMigratorConfigurationFactory(): object
        {
            return new class {
                public function getMigratorConfiguration(ArrayInput $input): array
                {
                    return [];
                }
            };
        }

        public function getMigrator(): object
        {
            return new class ($this) {
                public function __construct(private DependencyFactory $factory)
                {
                }
                public function migrate(array $plan, array $config): void
                {
                    $this->factory->migrated = $plan;
                }
            };
        }
    }
}

namespace Lotgd\Tests\Installer {

    use Lotgd\Installer\Installer;
    use Lotgd\MySQL\Database;
    use Lotgd\Output;
    use Lotgd\Tests\Stubs\DummySettings;
    use Lotgd\Tests\Stubs\DoctrineBootstrap;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../Stubs/DoctrineBootstrap.php';

    class Stage9Test extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $logd_version, $recommended_modules, $noinstallnavs,
            $DB_USEDATACACHE, $settings;

            \Lotgd\PhpGenericEnvironment::setRequestUri('/installer.php');
            \Doctrine\Migrations\DependencyFactory::$instance = null;
            $session            = [
            'dbinfo'            => [
                'DB_HOST'         => 'localhost',
                'DB_USER'         => 'user',
                'DB_PASS'         => 'pass',
                'DB_NAME'         => 'lotgd',
                'DB_PREFIX'       => '',
                'DB_USEDATACACHE' => 0,
                'DB_DATACACHEPATH' => '',
            ],
            'moduleoperations' => [],
            'skipmodules'      => true,
            'fromversion'      => '-1',
            ];
            $logd_version       = '2.0.0-rc +nb Edition';
            $recommended_modules = [];
            $noinstallnavs      = false;
            $DB_USEDATACACHE    = false;
            $settings           = new DummySettings();
            $ref = new \ReflectionClass(Output::class);
            $prop = $ref->getProperty('instance');
            $prop->setAccessible(true);
            $prop->setValue(null, new Output());

            file_put_contents(
                __DIR__ . '/../../dbconnect.php',
                "<?php return ['DB_HOST'=>'localhost','DB_USER'=>'user','DB_PASS'=>'pass','DB_NAME'=>'lotgd','DB_PREFIX'=>''];"
            );
        }

        protected function tearDown(): void
        {
            $config = __DIR__ . '/../../dbconnect.php';

            if (file_exists($config)) {
                unlink($config);
            }

            Database::setPrefix('');
            Database::$doctrineConnection = null;
            DoctrineBootstrap::$conn = null;

            $ref = new \ReflectionClass(Database::class);
            if ($ref->hasProperty('doctrine')) {
                $prop = $ref->getProperty('doctrine');
                $prop->setAccessible(true);
                $prop->setValue(null, null);
            }
        }

        public function testStage9RunsMigrationsAndChecksForAdmin(): void
        {
            $installer = new Installer();

            $installer->runStage(9);
            $installer->runStage(10);
            $outputText = Output::getInstance()->getRawOutput();

            $files    = glob(__DIR__ . '/../../migrations/Version*.php');
            $versions = array_map(fn($f) => substr(basename($f, '.php'), 7), $files);
            rsort($versions);
            $latest = $versions[0];

            $migrated = array_map(
                fn($v) => (string) $v,
                \Doctrine\Migrations\DependencyFactory::$instance->migrated
            );
            $this->assertContains($latest, $migrated, 'Latest migration was not applied');

            $queries = DoctrineBootstrap::$conn->queries;
            require __DIR__ . '/../../install/data/installer_sqlstatements.php';
            $expected = [];
            foreach ($sql_upgrade_statements as $statements) {
                foreach ($statements as $sql) {
                    $expected[] = $sql;
                }
            }
            $expectedCount = array_count_values($expected);
            $executedCount = array_count_values($queries);
            foreach ($expectedCount as $sql => $count) {
                $this->assertGreaterThanOrEqual(
                    $count,
                    $executedCount[$sql] ?? 0,
                    'Installer SQL statement was not executed: ' . $sql
                );
            }

            $this->assertStringContainsString('superuser account', $outputText);
        }

        public function testStage9AppliesConfiguredPrefix(): void
        {
            global $session;

            file_put_contents(
                __DIR__ . '/../../dbconnect.php',
                "<?php return ['DB_HOST'=>'localhost','DB_USER'=>'user','DB_PASS'=>'pass','DB_NAME'=>'lotgd','DB_PREFIX'=>'test_'];"
            );
            clearstatcache();

            $session['dbinfo']['DB_PREFIX'] = 'test_';

            $installer = new Installer();
            $installer->runStage(9);

            self::assertSame('test_', $GLOBALS['DB_PREFIX'] ?? null);
            $this->assertSame('test_creatures', Database::prefix('creatures'));
        }

        public function testStage9HonorsPrefixedMetadataTable(): void
        {
            global $session;

            file_put_contents(
                __DIR__ . '/../../dbconnect.php',
                "<?php return ['DB_HOST'=>'localhost','DB_USER'=>'user','DB_PASS'=>'pass','DB_NAME'=>'lotgd','DB_PREFIX'=>'lotgd_'];"
            );
            clearstatcache();
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate(__DIR__ . '/../../dbconnect.php', true);
            }

            $session['dbinfo']['DB_PREFIX'] = 'lotgd_';

            $config = require __DIR__ . '/../../src/Lotgd/Config/migrations.php';
            $this->assertSame('lotgd_doctrine_migration_versions', $config['table_storage']['table_name']);

            $installer = new Installer();

            $installer->runStage(9);

            $configData = \Doctrine\Migrations\DependencyFactory::$instance->configurationData ?? [];

            $this->assertSame(
                'lotgd_doctrine_migration_versions',
                $configData['table_storage']['table_name'] ?? null,
                'Installer did not request prefixed metadata table'
            );
        }


        public function testStage9SkipsLegacyInstallerStatementsWhenDataExists(): void
        {
            global $session;

            $session['dbinfo']['upgrade'] = true;
            $session['fromversion']       = '0.9.6';

            $conn = new \Lotgd\Tests\Stubs\DoctrineConnection();
            $conn->countResults = [1];
            DoctrineBootstrap::$conn = $conn;
            Database::$doctrineConnection = $conn;

            $installer = new Installer();
            $installer->runStage(9);

            $queries = DoctrineBootstrap::$conn->queries;
            require __DIR__ . '/../../install/data/installer_sqlstatements.php';
            $seedStatements = [];
            foreach ($sql_upgrade_statements as $statements) {
                foreach ($statements as $sql) {
                    $seedStatements[$sql] = true;
                }
            }

            foreach ($queries as $sql) {
                $this->assertArrayNotHasKey(
                    $sql,
                    $seedStatements,
                    'Legacy installer SQL should not run when data already exists: ' . $sql
                );
            }
        }

        public function testStage9CanBeRerunWithoutReapplyingLegacySeeds(): void
        {
            $conn = new \Lotgd\Tests\Stubs\DoctrineConnection();
            $conn->countResults = [0, 1];
            DoctrineBootstrap::$conn = $conn;
            Database::$doctrineConnection = $conn;

            $installer = new Installer();
            $installer->runStage(9);

            $firstRunCount = count(DoctrineBootstrap::$conn->queries);

            $installer->runStage(9);

            $newQueries = array_slice(DoctrineBootstrap::$conn->queries, $firstRunCount);

            require __DIR__ . '/../../install/data/installer_sqlstatements.php';
            $seedStatements = [];
            foreach ($sql_upgrade_statements as $statements) {
                foreach ($statements as $sql) {
                    $seedStatements[$sql] = true;
                }
            }

            foreach ($newQueries as $sql) {
                $this->assertArrayNotHasKey(
                    $sql,
                    $seedStatements,
                    'Legacy installer SQL should not run on subsequent stage9 invocation: ' . $sql
                );
            }
        }
    }

}
