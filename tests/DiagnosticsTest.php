<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Diagnostics;
use Lotgd\GameLog;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

final class DiagnosticsTest extends TestCase
{
    private Diagnostics $diagnostics;

    protected function setUp(): void
    {
        class_exists(Database::class);
        Database::$queries = [];
        Database::$mockResults = [];
        Database::$tablePrefix = '';
        Database::resetDoctrineConnection();

        $this->diagnostics = new Diagnostics();
    }

    protected function tearDown(): void
    {
        Database::$queries = [];
        Database::$mockResults = [];
        Database::resetDoctrineConnection();
        Settings::setInstance(null);
    }

    /**
     * @return list<array{sql:string,params:array,types:array}>
     */
    private function statements(): array
    {
        return Database::getDoctrineConnection()->fetchAllLog;
    }

    // ------------------------------------------------------ input boundary --

    public function testAnAbsentWindowFallsBackToTheDefault(): void
    {
        $this->assertSame(Diagnostics::DEFAULT_HOURS, Diagnostics::normalizeHours(false));
        $this->assertSame(Diagnostics::DEFAULT_HOURS, Diagnostics::normalizeHours(null));
        $this->assertSame(Diagnostics::DEFAULT_HOURS, Diagnostics::normalizeHours([]));
    }

    public function testEveryOfferedWindowIsAccepted(): void
    {
        foreach (Diagnostics::WINDOWS as $hours) {
            $this->assertSame($hours, Diagnostics::normalizeHours($hours));
            $this->assertSame($hours, Diagnostics::normalizeHours((string) $hours));
        }
    }

    /**
     * A window that is not on the list is not clamped to the nearest one, it is
     * replaced: the set of windows is also the set of links the page registers
     * with the navigation, and an unregistered URI is refused before it gets here.
     */
    public function testAnUnofferedWindowIsReplacedByTheDefault(): void
    {
        foreach (['0', '-5', '999999', '23', 'nonsense', '24; DROP TABLE', '1e3'] as $raw) {
            $this->assertSame(
                Diagnostics::DEFAULT_HOURS,
                Diagnostics::normalizeHours($raw),
                sprintf('%s should not be accepted as a window', var_export($raw, true))
            );
        }
    }

    public function testOnlyTheGameLogVocabularyIsAcceptedAsSeverity(): void
    {
        $this->assertSame('warning', Diagnostics::normalizeSeverity('warning'));
        $this->assertSame('error', Diagnostics::normalizeSeverity('ERROR'));
        $this->assertNull(Diagnostics::normalizeSeverity('critical'));
        $this->assertNull(Diagnostics::normalizeSeverity(''));
        $this->assertNull(Diagnostics::normalizeSeverity(false));
        $this->assertNull(Diagnostics::normalizeSeverity(['warning']));
    }

    // -------------------------------------------------------------- queries --

    public function testTheWindowIsBoundRatherThanInterpolated(): void
    {
        $this->diagnostics->gameLog(24, null, 50);

        $statement = $this->statements()[0] ?? null;
        $this->assertNotNull($statement);
        $this->assertStringContainsString('g.date > :since', $statement['sql']);
        $this->assertArrayHasKey('since', $statement['params']);
        $this->assertStringNotContainsString($statement['params']['since'], $statement['sql']);
    }

    public function testTheSeverityFilterIsOmittedEntirelyWhenNoneIsRequested(): void
    {
        $this->diagnostics->gameLog(24, null, 50);

        $statement = $this->statements()[0];
        $this->assertStringNotContainsString(':severity', $statement['sql']);
        $this->assertArrayNotHasKey('severity', $statement['params']);
    }

    public function testTheSeverityFilterIsBoundWhenRequested(): void
    {
        $this->diagnostics->gameLog(24, GameLog::SEVERITY_ERROR, 50);

        $statement = $this->statements()[0];
        $this->assertStringContainsString('g.severity = :severity', $statement['sql']);
        $this->assertSame('error', $statement['params']['severity']);
    }

    /**
     * The new day routine empties debuglog into debuglog_archive with a cutoff
     * of "now", so reading only the live table loses everything written before
     * the last new day -- which for a 24 hour window is most of it.
     */
    public function testTheCharacterAuditTrailIsReadFromBothTables(): void
    {
        $this->diagnostics->debugLog(24, 50);

        $statement = $this->statements()[0];
        $this->assertStringContainsString('FROM debuglog ', $statement['sql']);
        $this->assertStringContainsString('FROM debuglog_archive ', $statement['sql']);
        $this->assertStringContainsString('UNION ALL', $statement['sql']);
    }

    /**
     * A named placeholder repeated in one statement is not safe with PDO unless
     * emulated prepares are on, so the two halves get their own names.
     */
    public function testTheUnionUsesOnePlaceholderPerHalf(): void
    {
        $this->diagnostics->debugLog(24, 50);

        $statement = $this->statements()[0];
        $this->assertStringContainsString(':since_live', $statement['sql']);
        $this->assertStringContainsString(':since_archive', $statement['sql']);
        $this->assertSame(
            $statement['params']['since_live'],
            $statement['params']['since_archive'],
            'both halves must cover the same window'
        );
    }

    public function testTheRowLimitIsCappedRegardlessOfWhatIsAskedFor(): void
    {
        $this->diagnostics->gameLog(24, null, 100000);

        $this->assertStringContainsString(
            'LIMIT ' . Diagnostics::ROW_LIMIT,
            $this->statements()[0]['sql']
        );
    }

    public function testProfilingOrdersByAConstantAndBindsItsType(): void
    {
        $this->diagnostics->profiling(24, 'hooktime', 30);

        $statement = $this->statements()[0];
        $this->assertStringContainsString('type = :type', $statement['sql']);
        $this->assertSame('hooktime', $statement['params']['type']);
        $this->assertStringContainsString('ORDER BY total DESC', $statement['sql']);
    }

    /**
     * `severity` arrived with a migration and every query names it, in the
     * SELECT list even when no filter was asked for. An installation that has
     * not run that migration must still get a usable page, so the fallback has
     * to apply whether or not a filter was requested -- it once only applied
     * when one was, which left the unfiltered view empty on exactly the
     * installations that needed it most.
     */
    public function testAMissingSeverityColumnFallsBackWithAndWithoutAFilter(): void
    {
        foreach ([null, 'error'] as $severity) {
            Database::resetDoctrineConnection();
            $connection = Database::getDoctrineConnection();
            $connection->throwOnceOnFetchAll = true;

            $this->diagnostics->gameLog(24, $severity, 50);

            $statements = $connection->fetchAllLog;
            $last = end($statements);
            self::assertIsArray($last);
            self::assertStringNotContainsString(
                'severity',
                $last['sql'],
                'the fallback query must not name the column that is missing'
            );
            self::assertStringContainsString('g.date > :since', $last['sql']);
        }
    }

    // ------------------------------------------------------------- timeline --

    /**
     * The restriction must live in the statement, not in a filter applied to
     * the rows afterwards.
     *
     * Routine bookkeeping is the bulk of the game log, so fetching the newest N
     * rows and filtering them in PHP drops every warning, error and security
     * event that sits behind the first N routine rows -- on a busy server, all
     * of them. It was written that way once; this is the guard against it
     * coming back.
     */
    public function testTheTimelineRestrictsInSqlRatherThanAfterTheRowCap(): void
    {
        $this->diagnostics->timeline(24, 50);

        $statement = $this->statements()[0] ?? null;
        $this->assertNotNull($statement);
        $this->assertStringContainsString('g.category = :security', $statement['sql']);
        $this->assertStringContainsString('g.severity IN (:warning, :error)', $statement['sql']);
        $this->assertSame('security', $statement['params']['security']);
        $this->assertSame('warning', $statement['params']['warning']);
        $this->assertSame('error', $statement['params']['error']);
    }

    /**
     * Without the severity column the timeline narrows to the security category
     * rather than failing outright.
     */
    public function testTheTimelineFallsBackToTheSecurityCategoryAlone(): void
    {
        $connection = Database::getDoctrineConnection();
        $connection->throwOnceOnFetchAll = true;

        $this->diagnostics->timeline(24, 50);

        $statements = $connection->fetchAllLog;
        $this->assertStringNotContainsString('severity', $statements[1]['sql']);
        $this->assertStringContainsString('g.category = :security', $statements[1]['sql']);
    }

    /**
     * Rows the query returns are normalised into the common shape, and a
     * security event keeps its own severity rather than being relabelled.
     */
    public function testNotableRowsAreNormalisedIntoTheCommonShape(): void
    {
        Database::$mockResults = [
            [
                ['logid' => 2, 'date' => '2026-09-09 11:00:00', 'category' => 'security', 'severity' => 'info', 'message' => 'Superuser page access denied', 'who' => 7, 'name' => 'Admin'],
            ],
            [],
        ];

        $timeline = $this->diagnostics->timeline(24, 50);

        $this->assertCount(1, $timeline);
        $this->assertSame('gamelog', $timeline[0]['source']);
        $this->assertSame('security', $timeline[0]['category']);
        $this->assertSame('info', $timeline[0]['severity']);
        $this->assertSame('Admin', $timeline[0]['actor']);
        $this->assertSame('Superuser page access denied', $timeline[0]['text']);
    }

    public function testTheTimelineIsSortedNewestFirst(): void
    {
        Database::$mockResults = [
            [
                ['logid' => 1, 'date' => '2026-09-09 10:00:00', 'category' => 'security', 'severity' => 'warning', 'message' => 'older', 'who' => 0, 'name' => null],
                ['logid' => 2, 'date' => '2026-09-09 12:00:00', 'category' => 'security', 'severity' => 'warning', 'message' => 'newer', 'who' => 0, 'name' => null],
            ],
            [
                ['eventid' => 1, 'date' => '2026-09-09 11:00:00', 'ip' => '203.0.113.7', 'acctid' => 0, 'name' => null, 'login' => null, 'superuser' => 0],
            ],
        ];

        $timeline = $this->diagnostics->timeline(24, 50);

        $this->assertSame('newer', $timeline[0]['text']);
        $this->assertStringContainsString('203.0.113.7', $timeline[1]['text']);
        $this->assertSame('older', $timeline[2]['text']);
    }

    // --------------------------------------------------------------- counts --

    /**
     * A filtered listing next to an unfiltered total reads as "showing 5 of
     * 1000" when only five rows match, which is worse than no number at all.
     */
    public function testTheGameLogCountCarriesTheSeverityFilter(): void
    {
        $this->diagnostics->counts(24, GameLog::SEVERITY_ERROR);

        $gamelogCount = null;
        foreach ($this->statements() as $statement) {
            if (str_contains($statement['sql'], 'FROM gamelog')) {
                $gamelogCount = $statement;
            }
        }

        $this->assertNotNull($gamelogCount);
        $this->assertStringContainsString('severity = :severity', $gamelogCount['sql']);
        $this->assertSame('error', $gamelogCount['params']['severity']);
    }

    public function testTheGameLogCountIsUnfilteredWhenNoSeverityIsChosen(): void
    {
        $this->diagnostics->counts(24, null);

        foreach ($this->statements() as $statement) {
            if (str_contains($statement['sql'], 'FROM gamelog')) {
                $this->assertStringNotContainsString(':severity', $statement['sql']);
            }
        }
    }

    // -------------------------------------------------------------- runtime --

    /**
     * Newday applies its own defaults when a retention key has not been
     * materialised, so reporting "kept indefinitely" for an absent key would
     * describe a retention that is not the one in force. Reading the settings
     * map means the defaults have to be mirrored here rather than arriving with
     * the value.
     */
    public function testAbsentRetentionKeysReportTheDefaultsNewdayApplies(): void
    {
        Settings::setInstance(new DummySettings([]));

        $rows = [];
        foreach ($this->diagnostics->runtime()['Logging'] as $row) {
            $rows[$row['label']] = $row;
        }

        // The value is a translatable template and the number is an argument,
        // so the assertion is on the number rather than on the formatting.
        foreach (
            [
                'Game log retention' => 30,
                'Debug log retention' => 18,
                'Fail log retention' => 1,
                'DEBUG runtime retention' => 7,
            ] as $label => $expected
        ) {
            $this->assertSame('%s days', $rows[$label]['value'] ?? null, $label);
            $this->assertSame([$expected], $rows[$label]['args'] ?? null, $label);
        }
    }

    public function testAnExplicitZeroRetentionStillMeansIndefinitely(): void
    {
        Settings::setInstance(new DummySettings(['expiregamelog' => 0]));

        foreach ($this->diagnostics->runtime()['Logging'] as $row) {
            if ($row['label'] === 'Game log retention') {
                $this->assertSame('kept indefinitely', $row['value']);
            }
        }
    }

    /**
     * common.php:484 renders "Upgrade Needed" and its footer exits when the
     * code and schema versions differ, so this page is unreachable in exactly
     * that case. The row is reference information; it must never claim to be a
     * check that caught something.
     */
    public function testTheSchemaVersionRowNeverCarriesAWarning(): void
    {
        Settings::setInstance(new DummySettings(['installer_version' => 'something else entirely']));

        foreach ($this->diagnostics->runtime()['Version'] as $row) {
            if ($row['label'] === 'Schema version') {
                $this->assertSame('ok', $row['status']);
                $this->assertSame('something else entirely', $row['value']);
            }
        }
    }

    /**
     * The snapshot reports around twenty settings, so it takes the map once
     * instead of asking key by key: one read rather than twenty, and every row
     * then describes the same moment.
     *
     * Pinning it also keeps a later refactor from turning a page that only
     * describes the current state into one that writes the defaults it finds
     * missing -- getSetting() does that by design, and it is how a fresh
     * installation fills its settings table, but it belongs on the pages that
     * use a setting rather than on the one that merely reports it.
     */
    public function testTheSnapshotReadsTheSettingsMapOnceInsteadOfKeyByKey(): void
    {
        Settings::setInstance(new class (['installer_version' => '2.0.6 +nb Edition']) extends DummySettings {
            /**
             * `usedatacache` and `datacachepath` are exempt for a plain reason:
             * getSetting() answers them from dbconnect.php, not from the
             * settings table, so getArray() can never supply them and the
             * accessor is the only way to read them.
             */
            public function getSetting(string|int $settingname, mixed $default = false): mixed
            {
                if (in_array($settingname, ['usedatacache', 'datacachepath'], true)) {
                    return parent::getSetting($settingname, $default);
                }

                throw new \LogicException(sprintf(
                    'runtime() read "%s" key by key; take the settings map once through getArray().',
                    (string) $settingname
                ));
            }
        });

        $snapshot = $this->diagnostics->runtime();

        $this->assertArrayHasKey('Version', $snapshot);
        $this->assertArrayHasKey('Logging', $snapshot);
    }

    public function testAnUnavailableDatabaseVersionIsReportedRatherThanThrown(): void
    {
        Settings::setInstance(new DummySettings([]));
        Database::resetDoctrineConnection();

        $snapshot = $this->diagnostics->runtime();

        $labels = array_column($snapshot['Version'], 'label');
        $this->assertContains('Database server', $labels);
    }

    /**
     * The setting that publishes backtraces to every visitor is the one thing on
     * this page that is a live risk rather than a fact, so it has to stand out.
     */
    public function testPublicErrorDetailsAreFlaggedAsAnError(): void
    {
        Settings::setInstance(new DummySettings(['show_error_details' => 1]));

        $rows = $this->diagnostics->runtime()['Logging'];
        $flagged = null;
        foreach ($rows as $row) {
            if ($row['label'] === 'Public error details') {
                $flagged = $row;
            }
        }

        $this->assertNotNull($flagged);
        $this->assertSame('error', $flagged['status']);
    }

    public function testPublicErrorDetailsAreQuietWhenOff(): void
    {
        Settings::setInstance(new DummySettings(['show_error_details' => 0]));

        foreach ($this->diagnostics->runtime()['Logging'] as $row) {
            if ($row['label'] === 'Public error details') {
                $this->assertSame('ok', $row['status']);
            }
        }
    }
}
