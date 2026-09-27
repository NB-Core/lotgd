<?php

declare(strict_types=1);

namespace Lotgd\Upgrade;

use Closure;
use Lotgd\Doctrine\MigrationRunner;
use Lotgd\GameLog;
use Lotgd\MySQL\Database;
use Lotgd\Settings;

/**
 * Bring the database schema up to the code after an update.
 *
 * The game records the version it was installed or last upgraded at in the
 * `installer_version` setting, and common.php refuses to run while that
 * differs from the code's version. Before, only the installer could close the
 * gap. For an installation that already uses Doctrine migrations the installer
 * adds nothing an update needs: modules reinstall themselves when their files
 * change, and the rest of the installer serves a fresh install or a 1.x
 * database. So such an installation now upgrades itself on the first page
 * request after the update, and the installer stays for those two cases.
 *
 * `installer_version` is only advanced once every migration has succeeded. A
 * failed upgrade leaves the game locked and is retried, at most once per
 * {@see self::RETRY_AFTER_SECONDS}, so a broken migration does not hit the
 * database on every request.
 */
final class SchemaUpgrade
{
    public const UPGRADED = 'upgraded';
    /** Another request is applying the upgrade right now. */
    public const BUSY = 'busy';
    /** The last attempt failed recently; the next one waits. */
    public const WAITING = 'waiting';
    public const FAILED = 'failed';

    public const RETRY_AFTER_SECONDS = 60;

    /** Unix time of the last failed attempt, 0 after a success. */
    public const FAILED_AT_SETTING = 'schema_upgrade_failed_at';

    private Closure $log;
    private Closure $clock;

    /**
     * @param (callable(string, string): void)|null $log   Receives a message and a GameLog severity
     * @param (callable(): int)|null                $clock Current Unix time
     */
    public function __construct(
        private Settings $settings,
        private MigrationRunner $runner,
        private SchemaUpgradeLock $lock,
        ?callable $log = null,
        ?callable $clock = null
    ) {
        $this->log = Closure::fromCallable($log ?? [self::class, 'logToGameAndErrorLog']);
        $this->clock = Closure::fromCallable($clock ?? 'time');
    }

    /**
     * The upgrade for this game's own database.
     */
    public static function forGame(Settings $settings): self
    {
        return new self(
            $settings,
            new MigrationRunner(),
            new MysqlSchemaUpgradeLock(Database::getDoctrineConnection())
        );
    }

    /**
     * Whether an installation at $schemaVersion can upgrade itself to $codeVersion.
     *
     * False when the versions match, when there is no installation yet ("-1"),
     * and for a database without the migrations table: that is a 1.x schema,
     * which only the installer knows how to convert.
     *
     * @param callable(): bool $hasMigrationTable Asked last, and only when the
     *                                            versions differ: every page
     *                                            request passes through here
     */
    public static function canUpgradeItself(string $codeVersion, string $schemaVersion, callable $hasMigrationTable): bool
    {
        return $schemaVersion !== $codeVersion
            && $schemaVersion !== '-1'
            && $schemaVersion !== ''
            && $hasMigrationTable();
    }

    /**
     * Whether the database holds the migrations table, i.e. was installed or
     * upgraded by a 2.x installer.
     */
    public static function hasMigrationTable(): bool
    {
        return Database::tableExists(Database::prefix('doctrine_migration_versions'));
    }

    /**
     * Apply the pending migrations and record $codeVersion as installed.
     *
     * @return string One of the class constants
     */
    public function run(string $codeVersion): string
    {
        $failedAt = (int) $this->settings->getSetting(self::FAILED_AT_SETTING, 0);
        if ($failedAt > 0 && ($this->clock)() - $failedAt < self::RETRY_AFTER_SECONDS) {
            return self::WAITING;
        }

        if (!$this->lock->acquire()) {
            return self::BUSY;
        }

        try {
            // A request that waited for the lock may find the work done.
            $this->settings->clearSettings();
            $fromVersion = (string) $this->settings->getSetting('installer_version', '-1');
            if ($fromVersion === $codeVersion) {
                return self::UPGRADED;
            }

            ignore_user_abort(true);
            set_time_limit(0);

            $executed = $this->runner->migrate();
            $this->settings->saveSetting('installer_version', $codeVersion);
            if ($failedAt > 0) {
                $this->settings->saveSetting(self::FAILED_AT_SETTING, 0);
            }

            ($this->log)(
                sprintf(
                    'Upgraded the database from %s to %s; %d migration(s) applied%s',
                    $fromVersion,
                    $codeVersion,
                    count($executed),
                    $executed === [] ? '' : ': ' . implode(', ', $executed)
                ),
                GameLog::SEVERITY_INFO
            );

            return self::UPGRADED;
        } catch (\Throwable $exception) {
            try {
                $this->settings->saveSetting(self::FAILED_AT_SETTING, ($this->clock)());
            } catch (\Throwable) {
                // The retry delay is lost, not the report below.
            }
            ($this->log)(
                sprintf(
                    'Automatic database upgrade to %s failed; the game stays locked and retries in %d seconds: %s',
                    $codeVersion,
                    self::RETRY_AFTER_SECONDS,
                    $exception->getMessage()
                ),
                GameLog::SEVERITY_ERROR
            );

            return self::FAILED;
        } finally {
            $this->lock->release();
        }
    }

    /**
     * Apply whatever migrations are pending, at an administrator's request.
     *
     * For the case {@see self::run()} cannot see: the version already matches,
     * but a migration file arrived after the upgrade ran, as an upload in the
     * wrong order can leave it. There is no retry delay here, because a person
     * pressed the button.
     *
     * @return string {@see self::UPGRADED}, {@see self::BUSY} or {@see self::FAILED}
     */
    public function applyPending(): string
    {
        if (!$this->lock->acquire()) {
            return self::BUSY;
        }

        try {
            $executed = $this->runner->migrate();
            ($this->log)(
                sprintf(
                    'Applied %d pending migration(s) at an administrator\'s request%s',
                    count($executed),
                    $executed === [] ? '' : ': ' . implode(', ', $executed)
                ),
                GameLog::SEVERITY_INFO
            );

            return self::UPGRADED;
        } catch (\Throwable $exception) {
            ($this->log)('Applying pending migrations failed: ' . $exception->getMessage(), GameLog::SEVERITY_ERROR);

            return self::FAILED;
        } finally {
            $this->lock->release();
        }
    }

    /**
     * Report to both places an operator looks: the game log, when the database
     * still accepts writes, and PHP's error log, which does not depend on it.
     */
    private static function logToGameAndErrorLog(string $message, string $severity): void
    {
        error_log('LotGD schema upgrade: ' . $message);
        try {
            GameLog::log($message, GameLog::CATEGORY_MAINTENANCE, false, 0, $severity);
        } catch (\Throwable) {
            // Already in the error log.
        }
    }
}
