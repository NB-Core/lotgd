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

        public static function fromEntityManager(mixed $config, mixed $em): self
        {
            return self::$instance = new self();
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

        public function testStage9RunsOnlyNewerInstallerStatementsOnUpgrade(): void
        {
            global $session;

            $session['dbinfo']['upgrade'] = true;
            $session['fromversion']       = '0.9.6';

            $conn = new \Lotgd\Tests\Stubs\DoctrineConnection();
            DoctrineBootstrap::$conn = $conn;
            \Lotgd\MySQL\Database::$doctrineConnection = $conn;

            $installer = new Installer();
            $installer->runStage(9);

            $queries = DoctrineBootstrap::$conn->queries;
            require __DIR__ . '/../../install/data/installer_sqlstatements.php';
            $expected   = [];
            $notExpected = [];
            foreach ($sql_upgrade_statements as $version => $statements) {
                foreach ($statements as $sql) {
                    if (version_compare($version, '0.9.6', '>')) {
                        $expected[] = $sql;
                    } else {
                        $notExpected[] = $sql;
                    }
                }
            }
            $expectedCount = array_count_values($expected);
            $executedCount = array_count_values($queries);
            foreach ($expectedCount as $sql => $count) {
                $this->assertGreaterThanOrEqual(
                    $count,
                    $executedCount[$sql] ?? 0,
                    'Upgrade SQL statement was not executed: ' . $sql
                );
            }
            foreach ($notExpected as $sql) {
                $this->assertArrayNotHasKey(
                    $sql,
                    $executedCount,
                    'Unexpected SQL statement executed during upgrade: ' . $sql
                );
            }
        }
    }

}
