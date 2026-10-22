<?php

declare(strict_types=1);

namespace Lotgd\Reactive;

use Closure;
use Lotgd\Action;
use Lotgd\Encounter;
use Lotgd\Grant;
use Lotgd\MySQL\StorageEngine;
use Lotgd\Page\PartialMode;
use Lotgd\Realtime;
use Lotgd\Settings;
use Lotgd\Upgrade\SchemaUpgrade;

/**
 * The reactive features at a glance (docs/ReactiveCore.md): for each, whether
 * it is on, and what keeps it off. Game Settings shows this above the form,
 * with a button that turns on what can run here.
 *
 * The reasons come from the same checks the features make themselves -- the
 * settings, the tables' storage engine, the migrations -- read here once.
 */
final class Status
{
    public const ON = 'on';
    public const OFF = 'off';
    public const BLOCKED = 'blocked';

    /** The switches "turn on the recommended" may set, in dependency order. */
    public const RECOMMENDED = ['guarded_actions', 'partial_render', 'realtime_enabled', 'grants_enabled', 'live_encounters'];

    /** @var array<string,list<string>> */
    private array $engines = [];

    private Closure $pendingMigrations;

    private Closure $nonTransactional;

    /**
     * @param (callable():(list<string>|null))|null       $pendingMigrations Defaults to the recorded schema migration
     * @param (callable(list<string>):list<string>)|null $nonTransactional  Defaults to StorageEngine
     */
    public function __construct(private Settings $settings, ?callable $pendingMigrations = null, ?callable $nonTransactional = null)
    {
        $this->pendingMigrations = Closure::fromCallable(
            $pendingMigrations ?? fn (): array => (string) $this->settings->getSetting(SchemaUpgrade::MIGRATION_SETTING, '') === SchemaUpgrade::LATEST_MIGRATION
                ? []
                : [SchemaUpgrade::LATEST_MIGRATION]
        );
        $this->nonTransactional = Closure::fromCallable(
            $nonTransactional ?? static fn (array $tables): array => StorageEngine::nonTransactional($tables)
        );
    }

    /**
     * One row per feature: its name, state, the settings behind it, and what
     * is missing (translatable sentences with their arguments).
     *
     * @return list<array{feature:string, state:string, settings:list<string>, missing:list<array{0:string,1:list<string>}>}>
     */
    public function rows(): array
    {
        $pending = ($this->pendingMigrations)();
        $schema = $pending === null || $pending === []
            ? []
            : [['The database is not yet at migration %s (Diagnostics applies pending migrations)', [implode(', ', $pending)]]];
        $skin = (string) $this->settings->getSetting('defaultskin', defined('DEFAULT_TEMPLATE') ? (string) DEFAULT_TEMPLATE : '');

        $guarded = $this->tables(Action::TRANSACTIONAL_TABLES);
        $realtime = $this->tables(Realtime::TABLES);
        $grants = array_merge(
            $this->tables([Grant::TABLE]),
            $this->needs('guarded_actions', $guarded)
        );
        $realtimeMissing = array_merge($realtime, $this->needs('partial_render', []));
        $encounters = array_merge(
            $this->tables(Encounter::TABLES),
            $this->needs('realtime_enabled', $realtimeMissing),
            $this->needs('grants_enabled', $grants)
        );
        $regions = PartialMode::skinDeclaresFor($skin, 'regions')
            ? []
            : [['The default skin %s does not declare regions; its players get whole pages', [$skin]]];
        $overlay = PartialMode::skinDeclaresFor($skin, 'overlay')
            ? []
            : [['The default skin %s does not declare the overlay; mail opens in a window', [$skin]]];
        $sse = $this->setting('realtime_transport', 'poll') === 'sse'
            ? $this->needs('realtime_enabled', $realtimeMissing)
            : [['Polling is used (set the transport to server-sent events on a host that keeps one PHP worker per open stream)', []]];

        return [
            $this->row('Guarded actions', ['guarded_actions'], array_merge($schema, $guarded)),
            $this->row('Pages change in place (partial rendering)', ['partial_render', 'partial_render_default'], array_merge($schema, $regions)),
            $this->row('Mail and MoTD in the page (overlay)', [], $overlay, true),
            $this->row('Realtime', ['realtime_enabled'], array_merge($schema, $realtimeMissing)),
            $this->row('Server-sent events', ['realtime_transport'], $sse, $this->setting('realtime_transport', 'poll') === 'sse'),
            $this->row('Grants', ['grants_enabled'], array_merge($schema, $grants)),
            $this->row('Live duels and presence', ['live_encounters'], array_merge($schema, $encounters)),
        ];
    }

    /**
     * The settings "turn on the recommended" sets: those of RECOMMENDED that
     * are off and whose tables are ready. Realtime and grants count as on for
     * what needs them, once they are in this list.
     *
     * @return list<string>
     */
    public function recommended(): array
    {
        $ready = [
            'guarded_actions' => $this->engine(Action::TRANSACTIONAL_TABLES) === [],
            'partial_render' => true,
            'realtime_enabled' => $this->engine(Realtime::TABLES) === [],
            'grants_enabled' => $this->engine([Grant::TABLE]) === [],
            'live_encounters' => $this->engine(Encounter::TABLES) === [],
        ];
        $needs = [
            'realtime_enabled' => ['partial_render'],
            'grants_enabled' => ['guarded_actions'],
            'live_encounters' => ['realtime_enabled', 'grants_enabled'],
        ];
        $on = [];
        $set = [];
        foreach (self::RECOMMENDED as $key) {
            $enabled = (int) $this->setting($key, '0') === 1;
            $possible = $ready[$key];
            foreach ($needs[$key] ?? [] as $need) {
                $possible = $possible && ($on[$need] ?? false);
            }
            $on[$key] = $enabled || $possible;
            if (!$enabled && $possible) {
                $set[] = $key;
            }
        }

        return $set;
    }

    /**
     * @param list<string>                              $settings
     * @param list<array{0:string,1:list<string>}>      $missing
     * @return array{feature:string, state:string, settings:list<string>, missing:list<array{0:string,1:list<string>}>}
     */
    private function row(string $feature, array $settings, array $missing, ?bool $switchedOn = null): array
    {
        $switchedOn ??= $settings !== [] && (int) $this->setting($settings[0], '0') === 1;
        if (!$switchedOn) {
            $state = self::OFF;
        } else {
            $state = $missing === [] ? self::ON : self::BLOCKED;
        }

        return ['feature' => $feature, 'state' => $state, 'settings' => $settings, 'missing' => $missing];
    }

    /**
     * @param list<string> $tables
     * @return list<array{0:string,1:list<string>}>
     */
    private function tables(array $tables): array
    {
        $bad = $this->engine($tables);

        return $bad === [] ? [] : [['Tables missing or not InnoDB: %s', [implode(', ', $bad)]]];
    }

    /**
     * A feature that needs $other: that on, and nothing missing for it.
     *
     * @param list<array{0:string,1:list<string>}> $otherMissing
     * @return list<array{0:string,1:list<string>}>
     */
    private function needs(string $other, array $otherMissing): array
    {
        if ((int) $this->setting($other, '0') !== 1) {
            return [['Needs %s turned on', [$other]]];
        }

        return $otherMissing === [] ? [] : [['Needs %s, which cannot run yet', [$other]]];
    }

    /**
     * @param list<string> $tables
     * @return list<string>
     */
    private function engine(array $tables): array
    {
        $key = implode(',', $tables);

        return $this->engines[$key] ??= ($this->nonTransactional)($tables);
    }

    private function setting(string $key, string $default): string
    {
        return (string) $this->settings->getSetting($key, $default);
    }
}
