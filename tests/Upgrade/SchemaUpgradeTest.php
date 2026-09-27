<?php

declare(strict_types=1);

namespace Lotgd\Tests\Upgrade;

use Lotgd\Doctrine\MigrationRunner;
use Lotgd\GameLog;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Upgrade\SchemaUpgrade;
use Lotgd\Upgrade\SchemaUpgradeLock;
use PHPUnit\Framework\TestCase;

/**
 * An update used to lock the game until an administrator ran the installer,
 * and in a container the installer is gone once installation completes. These
 * cases pin the replacement: the game upgrades itself, only once, only after
 * every migration succeeded, and without hammering a broken database.
 */
final class SchemaUpgradeTest extends TestCase
{
    private const CODE = '2.0.8 +nb Edition';
    private const INSTALLED = '2.0.7 +nb Edition';

    /** @var list<array{string,string}> */
    private array $logged = [];
    private int $now = 1_800_000_000;

    public function testAnInstallationWithMigrationsUpgradesItselfWhenTheVersionChanged(): void
    {
        self::assertTrue(SchemaUpgrade::canUpgradeItself(self::CODE, self::INSTALLED, static fn (): bool => true));
    }

    public function testNothingHappensWhenTheVersionsMatch(): void
    {
        $asked = false;
        $table = static function () use (&$asked): bool {
            $asked = true;

            return true;
        };

        self::assertFalse(SchemaUpgrade::canUpgradeItself(self::CODE, self::CODE, $table));
        self::assertFalse($asked, 'every page passes here; the table check must only run on a version change');
    }

    public function testAFreshInstallStillNeedsTheInstaller(): void
    {
        self::assertFalse(SchemaUpgrade::canUpgradeItself(self::CODE, '-1', static fn (): bool => true));
        self::assertFalse(SchemaUpgrade::canUpgradeItself(self::CODE, '', static fn (): bool => true));
    }

    public function testALegacyDatabaseWithoutTheMigrationTableStillNeedsTheInstaller(): void
    {
        self::assertFalse(SchemaUpgrade::canUpgradeItself(self::CODE, '1.2.7 +nb Edition', static fn (): bool => false));
    }

    public function testASuccessfulUpgradeRecordsTheNewVersionAndReleasesTheLock(): void
    {
        $settings = new DummySettings(['installer_version' => self::INSTALLED]);
        $lock = $this->lock(true);

        $outcome = $this->upgrade($settings, $this->runner(['Lotgd\\Migrations\\Version20260101000000']), $lock)->run(self::CODE);

        self::assertSame(SchemaUpgrade::UPGRADED, $outcome);
        self::assertSame(self::CODE, $settings->getSetting('installer_version'));
        self::assertSame(1, $lock->released);
        self::assertSame(GameLog::SEVERITY_INFO, $this->logged[0][1]);
        self::assertStringContainsString(self::INSTALLED, $this->logged[0][0]);
        self::assertStringContainsString('Version20260101000000', $this->logged[0][0]);
    }

    public function testAnUpgradeWithoutPendingMigrationsStillRecordsTheVersion(): void
    {
        // A release that changes code only: the game must not stay locked
        // just because there was nothing to migrate.
        $settings = new DummySettings(['installer_version' => self::INSTALLED]);

        $outcome = $this->upgrade($settings, $this->runner([]), $this->lock(true))->run(self::CODE);

        self::assertSame(SchemaUpgrade::UPGRADED, $outcome);
        self::assertSame(self::CODE, $settings->getSetting('installer_version'));
    }

    public function testWhileAnotherRequestHoldsTheLockNothingRuns(): void
    {
        $settings = new DummySettings(['installer_version' => self::INSTALLED]);
        $runner = $this->createMock(MigrationRunner::class);
        $runner->expects(self::never())->method('migrate');

        $outcome = $this->upgrade($settings, $runner, $this->lock(false))->run(self::CODE);

        self::assertSame(SchemaUpgrade::BUSY, $outcome);
        self::assertSame(self::INSTALLED, $settings->getSetting('installer_version'));
    }

    public function testARequestThatFindsTheWorkDoneDoesNotMigrateAgain(): void
    {
        // The lock was free again because the other request finished.
        $settings = new DummySettings(['installer_version' => self::CODE]);
        $runner = $this->createMock(MigrationRunner::class);
        $runner->expects(self::never())->method('migrate');

        self::assertSame(SchemaUpgrade::UPGRADED, $this->upgrade($settings, $runner, $this->lock(true))->run(self::CODE));
    }

    public function testAFailedMigrationKeepsTheGameLockedAndIsLogged(): void
    {
        $settings = new DummySettings(['installer_version' => self::INSTALLED]);
        $runner = $this->createStub(MigrationRunner::class);
        $runner->method('migrate')->willThrowException(new \RuntimeException('Duplicate column name'));
        $lock = $this->lock(true);

        $outcome = $this->upgrade($settings, $runner, $lock)->run(self::CODE);

        self::assertSame(SchemaUpgrade::FAILED, $outcome);
        self::assertSame(self::INSTALLED, $settings->getSetting('installer_version'));
        self::assertSame($this->now, $settings->getSetting(SchemaUpgrade::FAILED_AT_SETTING));
        self::assertSame(1, $lock->released);
        self::assertSame(GameLog::SEVERITY_ERROR, $this->logged[0][1]);
        self::assertStringContainsString('Duplicate column name', $this->logged[0][0]);
    }

    public function testAFailureIsNotRetriedOnEveryRequest(): void
    {
        $settings = new DummySettings([
            'installer_version' => self::INSTALLED,
            SchemaUpgrade::FAILED_AT_SETTING => $this->now - 10,
        ]);
        $runner = $this->createMock(MigrationRunner::class);
        $runner->expects(self::never())->method('migrate');
        $lock = $this->lock(true);

        self::assertSame(SchemaUpgrade::WAITING, $this->upgrade($settings, $runner, $lock)->run(self::CODE));
        self::assertSame(0, $lock->acquired, 'waiting must not even take the lock');
    }

    public function testAFailureIsRetriedOnceTheDelayHasPassedAndClearedOnSuccess(): void
    {
        $settings = new DummySettings([
            'installer_version' => self::INSTALLED,
            SchemaUpgrade::FAILED_AT_SETTING => $this->now - SchemaUpgrade::RETRY_AFTER_SECONDS,
        ]);

        $outcome = $this->upgrade($settings, $this->runner([]), $this->lock(true))->run(self::CODE);

        self::assertSame(SchemaUpgrade::UPGRADED, $outcome);
        self::assertSame(0, $settings->getSetting(SchemaUpgrade::FAILED_AT_SETTING));
    }

    public function testApplyingPendingMigrationsLeavesTheVersionAlone(): void
    {
        $settings = new DummySettings(['installer_version' => self::CODE]);

        $outcome = $this->upgrade($settings, $this->runner(['Lotgd\\Migrations\\Version20260101000000']), $this->lock(true))
            ->applyPending();

        self::assertSame(SchemaUpgrade::UPGRADED, $outcome);
        self::assertSame(self::CODE, $settings->getSetting('installer_version'));
        self::assertStringContainsString('Version20260101000000', $this->logged[0][0]);
    }

    public function testApplyingPendingMigrationsReportsAFailure(): void
    {
        $runner = $this->createStub(MigrationRunner::class);
        $runner->method('migrate')->willThrowException(new \RuntimeException('Table exists'));
        $lock = $this->lock(true);

        $outcome = $this->upgrade(new DummySettings(), $runner, $lock)->applyPending();

        self::assertSame(SchemaUpgrade::FAILED, $outcome);
        self::assertSame(1, $lock->released);
        self::assertSame(GameLog::SEVERITY_ERROR, $this->logged[0][1]);
    }

    public function testApplyingPendingMigrationsRespectsTheLock(): void
    {
        $runner = $this->createMock(MigrationRunner::class);
        $runner->expects(self::never())->method('migrate');

        self::assertSame(SchemaUpgrade::BUSY, $this->upgrade(new DummySettings(), $runner, $this->lock(false))->applyPending());
    }

    public function testTheWaitingPageReloadsAndTouchesNothing(): void
    {
        $page = SchemaUpgrade::unavailablePage(SchemaUpgrade::BUSY, false);

        self::assertStringContainsString("http-equiv='refresh' content='10'", $page);
        self::assertStringContainsString('being brought up to date', $page);
        self::assertStringNotContainsString('installer.php', $page);
    }

    public function testTheFailurePageNamesTheLogsAndTheInstallerOnlyWhenPresent(): void
    {
        $withInstaller = SchemaUpgrade::unavailablePage(SchemaUpgrade::FAILED, true);
        $withoutInstaller = SchemaUpgrade::unavailablePage(SchemaUpgrade::WAITING, false);

        self::assertStringContainsString('game log', $withInstaller);
        self::assertStringContainsString('installer.php', $withInstaller);
        self::assertStringNotContainsString('installer.php', $withoutInstaller);
        self::assertStringContainsString(
            "content='" . SchemaUpgrade::RETRY_AFTER_SECONDS . "'",
            $withoutInstaller
        );
    }

    private function upgrade(DummySettings $settings, MigrationRunner $runner, SchemaUpgradeLock $lock): SchemaUpgrade
    {
        return new SchemaUpgrade(
            $settings,
            $runner,
            $lock,
            function (string $message, string $severity): void {
                $this->logged[] = [$message, $severity];
            },
            fn (): int => $this->now
        );
    }

    /**
     * @param list<string> $executed
     */
    private function runner(array $executed): MigrationRunner
    {
        $runner = $this->createMock(MigrationRunner::class);
        $runner->expects(self::once())->method('migrate')->willReturn($executed);

        return $runner;
    }

    private function lock(bool $available): SchemaUpgradeLock
    {
        return new class ($available) implements SchemaUpgradeLock {
            public int $acquired = 0;
            public int $released = 0;

            public function __construct(private bool $available)
            {
            }

            public function acquire(): bool
            {
                $this->acquired++;

                return $this->available;
            }

            public function release(): void
            {
                $this->released++;
            }
        };
    }
}
