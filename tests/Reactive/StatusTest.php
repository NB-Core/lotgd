<?php

declare(strict_types=1);

namespace Lotgd\Tests\Reactive;

use Lotgd\Reactive\Status;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * The live features at a glance: each feature's state and what keeps it off,
 * and which switches "turn on the recommended" may set.
 */
final class StatusTest extends TestCase
{
    private const ALL_ON = [
        'guarded_actions' => 1, 'partial_render' => 1, 'realtime_enabled' => 1, 'grants_enabled' => 1,
        'live_encounters' => 1, 'defaultskin' => 'twig:aurora',
    ];

    public function testWithEverythingOnAndReadyEveryFeatureIsOn(): void
    {
        $rows = $this->states(self::ALL_ON);

        self::assertSame('on', $rows['Guarded actions']);
        self::assertSame('on', $rows['Pages change in place (partial rendering)']);
        self::assertSame('on', $rows['Mail and MoTD in the page (overlay)']);
        self::assertSame('on', $rows['Realtime']);
        self::assertSame('off', $rows['Server-sent events'], 'Polling unless chosen.');
        self::assertSame('on', $rows['Grants']);
        self::assertSame('on', $rows['Live duels and presence']);
    }

    public function testAFreshServerHasEverythingOffAndRecommendsAll(): void
    {
        $status = $this->make(['defaultskin' => 'twig:aurora']);

        self::assertSame(['off'], array_values(array_unique(array_column($this->rowsOf($status, without: 'Mail and MoTD in the page (overlay)'), 'state'))));
        self::assertSame(['guarded_actions', 'partial_render', 'realtime_enabled', 'grants_enabled', 'live_encounters'], $status->recommended());
    }

    public function testATableThatIsNotInnoDbBlocksItAndWhatNeedsIt(): void
    {
        $status = $this->make(self::ALL_ON, nonTransactional: ['action_log']);
        $rows = $this->byFeature($status->rows());

        self::assertSame('blocked', $rows['Guarded actions']['state']);
        self::assertSame([['Tables missing or not InnoDB: %s', ['action_log']]], $rows['Guarded actions']['missing']);
        self::assertSame('blocked', $rows['Grants']['state']);
        self::assertContains(['Needs %s, which cannot run yet', ['guarded_actions']], $rows['Grants']['missing']);
        self::assertSame('blocked', $rows['Live duels and presence']['state']);
        self::assertSame('on', $rows['Realtime']['state'], 'Realtime does not need guarded actions.');
    }

    public function testTheRecommendedSkipWhatCannotRunAndWhatNeedsIt(): void
    {
        $status = $this->make(['defaultskin' => 'twig:aurora'], nonTransactional: ['action_log']);

        self::assertSame(['partial_render', 'realtime_enabled'], $status->recommended());
    }

    public function testTheRecommendedLeaveWhatIsOnAlone(): void
    {
        $status = $this->make(['guarded_actions' => 1, 'partial_render' => 1, 'defaultskin' => 'twig:aurora']);

        self::assertSame(['realtime_enabled', 'grants_enabled', 'live_encounters'], $status->recommended());
    }

    public function testAnEnabledSwitchThatCannotRunIsNoBaseForWhatNeedsIt(): void
    {
        $status = $this->make(['guarded_actions' => 1, 'defaultskin' => 'twig:aurora'], nonTransactional: ['action_log']);

        self::assertSame(['partial_render', 'realtime_enabled'], $status->recommended(), 'Grants and duels need guarded actions that cannot run.');
    }

    public function testASettingOnWithoutWhatItNeedsSaysSo(): void
    {
        $rows = $this->byFeature($this->make(['live_encounters' => 1, 'defaultskin' => 'twig:aurora'])->rows());

        self::assertSame('blocked', $rows['Live duels and presence']['state']);
        self::assertContains(['Needs %s turned on', ['realtime_enabled']], $rows['Live duels and presence']['missing']);
        self::assertContains(['Needs %s turned on', ['grants_enabled']], $rows['Live duels and presence']['missing']);
    }

    public function testPendingMigrationsBlockTheFeaturesThatNeedSchema(): void
    {
        $rows = $this->byFeature($this->make(self::ALL_ON, pending: ['Version20261026000001'])->rows());

        self::assertSame('blocked', $rows['Live duels and presence']['state']);
        self::assertContains(['The database is not yet at migration %s (Diagnostics applies pending migrations)', ['Version20261026000001']], $rows['Realtime']['missing']);
    }

    public function testTheRecordedMigrationTellsWhetherTheSchemaIsCurrent(): void
    {
        $current = new Status(new DummySettings(['schema_migration' => \Lotgd\Upgrade\SchemaUpgrade::LATEST_MIGRATION] + self::ALL_ON), null, static fn (): array => []);
        $behind = new Status(new DummySettings(['schema_migration' => 'Version20250101000001'] + self::ALL_ON), null, static fn (): array => []);

        self::assertSame('on', $this->byFeature($current->rows())['Realtime']['state']);
        self::assertSame('blocked', $this->byFeature($behind->rows())['Realtime']['state']);
    }

    public function testALegacyDefaultSkinBlocksPartialRenderingAndTheOverlay(): void
    {
        $rows = $this->byFeature($this->make(['defaultskin' => 'jade'] + self::ALL_ON)->rows());

        self::assertSame('blocked', $rows['Pages change in place (partial rendering)']['state']);
        self::assertSame('blocked', $rows['Mail and MoTD in the page (overlay)']['state']);
    }

    public function testServerSentEventsNeedRealtime(): void
    {
        $rows = $this->byFeature($this->make(['realtime_transport' => 'sse', 'defaultskin' => 'twig:aurora'])->rows());

        self::assertSame('blocked', $rows['Server-sent events']['state']);
        self::assertSame([['Needs %s turned on', ['realtime_enabled']]], $rows['Server-sent events']['missing']);
        self::assertSame('on', $this->states(['realtime_transport' => 'sse'] + self::ALL_ON)['Server-sent events']);
    }

    /**
     * @param array<string,mixed> $settings
     * @param list<string>        $nonTransactional
     * @param list<string>        $pending
     */
    private function make(array $settings, array $nonTransactional = [], array $pending = []): Status
    {
        return new Status(
            new DummySettings($settings),
            static fn (): array => $pending,
            static fn (array $tables): array => array_values(array_intersect($tables, $nonTransactional))
        );
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,string>
     */
    private function states(array $settings): array
    {
        return array_map(static fn (array $row): string => $row['state'], $this->byFeature($this->make($settings)->rows()));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function byFeature(array $rows): array
    {
        return array_column($rows, null, 'feature');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rowsOf(Status $status, string $without): array
    {
        return array_values(array_filter($status->rows(), static fn (array $row): bool => $row['feature'] !== $without));
    }
}
