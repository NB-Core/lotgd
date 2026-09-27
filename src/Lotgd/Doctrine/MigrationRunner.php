<?php

declare(strict_types=1);

namespace Lotgd\Doctrine;

use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Apply the schema migrations in migrations/ to the configured database.
 *
 * The installer and the automatic upgrade in common.php share this class, so
 * both run the same migrations the same way. The installer's own handling of
 * 1.x databases (marking the versions a legacy schema already contains) stays
 * in the installer and uses {@see self::dependencyFactory()} before calling
 * {@see self::migrate()}.
 */
class MigrationRunner
{
    private ?DependencyFactory $dependencyFactory = null;

    /**
     * @param string|null                 $configFile    Migration configuration; defaults to src/Lotgd/Config/migrations.php
     * @param EntityManagerInterface|null $entityManager Defaults to the game's own
     */
    public function __construct(
        private ?string $configFile = null,
        private ?EntityManagerInterface $entityManager = null
    ) {
    }

    /**
     * The Doctrine Migrations services for this database.
     *
     * Built on first use, so constructing a runner does not open a connection.
     * Reading through it never writes: only {@see self::migrate()} creates or
     * updates Doctrine's metadata table.
     */
    public function dependencyFactory(): DependencyFactory
    {
        if ($this->dependencyFactory === null) {
            $configFile = $this->configFile ?? dirname(__DIR__) . '/Config/migrations.php';
            if (function_exists('opcache_invalidate')) {
                // The configuration reads dbconnect.php, which the installer
                // may have rewritten earlier in the same request.
                opcache_invalidate($configFile, true);
            }
            $config = require $configFile;

            $this->dependencyFactory = DependencyFactory::fromEntityManager(
                new ConfigurationArray($config),
                new ExistingEntityManager($this->entityManager ?? Bootstrap::getEntityManager())
            );
        }

        return $this->dependencyFactory;
    }

    /**
     * Migrations present in migrations/ that the database has not executed.
     *
     * @return list<string> Migration class names, oldest first
     */
    public function pending(): array
    {
        $versions = [];
        foreach ($this->dependencyFactory()->getMigrationStatusCalculator()->getNewMigrations()->getItems() as $migration) {
            /** @var AvailableMigration $migration */
            $versions[] = (string) $migration->getVersion();
        }

        return $versions;
    }

    /**
     * Execute every pending migration, oldest first.
     *
     * @return list<string> The migrations executed; empty when the schema was current
     */
    public function migrate(): array
    {
        $factory = $this->dependencyFactory();
        $factory->getMetadataStorage()->ensureInitialized();
        $latest = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($latest);
        $configuration = $factory->getConsoleInputMigratorConfigurationFactory()
            ->getMigratorConfiguration(new ArrayInput([]));

        // Keyed by the version each group of statements belongs to.
        $executed = $factory->getMigrator()->migrate($plan, $configuration);

        return array_map('strval', array_keys($executed));
    }
}
