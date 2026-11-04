<?php

declare(strict_types=1);

namespace Lotgd\Encounter;

use Lotgd\Battle;
use Lotgd\Battle\CombatContext;
use Lotgd\Battle\Combatant;
use Lotgd\Battle\RandomSource;

/**
 * A duel in rounds (docs/ReactiveCore.md, Phase 7): both players choose at
 * once and in secret -- attack, guard or surrender -- and the round is one
 * exchange of the core's PvP damage roll (Battle::computePlayerDamage()).
 * A player who did not choose in time attacks.
 *
 * Only the values the duel started with count: attack, defense and the
 * hitpoints of each player when the challenge was accepted, no buffs, no
 * companions. Everything here is arithmetic on the duel's state; storing it
 * and announcing it is Lotgd\Encounter\Duel's.
 */
final class RoundDuel implements Engine
{
    public const ATTACK = 'attack';
    public const GUARD = 'guard';
    public const SURRENDER = 'surrender';
    public const CHOICES = [self::ATTACK, self::GUARD, self::SURRENDER];

    /** Rounds in a row without a choice from either player that end the duel. */
    public const IDLE_ROUNDS = 2;

    /** What guarding does to a player's own attack and defense. */
    private const GUARD_ATTACK = 0.5;
    private const GUARD_DEFENSE = 1.5;

    public function __construct(private readonly int $maxRounds = 30)
    {
    }

    public function kind(): string
    {
        return 'duel';
    }

    public function start(array $players): array
    {
        $snapshot = [];
        foreach ($players as $acctid => $player) {
            $hitpoints = max(1, (int) $player['hitpoints']);
            $snapshot[(string) $acctid] = [
                'name' => (string) $player['name'],
                'level' => (int) $player['level'],
                'attack' => (float) $player['attack'],
                'defense' => (float) $player['defense'],
                'maxhp' => $hitpoints,
                'hp' => $hitpoints,
            ];
        }

        return ['players' => $snapshot, 'round' => 1, 'idle' => 0, 'log' => [], 'result' => null];
    }

    /**
     * Resolve the current round.
     *
     * @param array<string,mixed>          $data    The duel's state.
     * @param array<int,string|null>       $choices By account id; null for a player who did not choose.
     * @return array{data:array<string,mixed>,round:array<string,mixed>,outcome:?Outcome}
     */
    public function resolve(array $data, array $choices, RandomSource $random): array
    {
        $round = (int) $data['round'];
        $ids = array_map('intval', array_keys($data['players']));
        [$a, $b] = $ids;
        $missing = array_values(array_filter($ids, static fn (int $id): bool => ($choices[$id] ?? null) === null));
        $chosen = [];
        foreach ($ids as $id) {
            $chosen[$id] = in_array($choices[$id] ?? null, self::CHOICES, true) ? (string) $choices[$id] : self::ATTACK;
        }
        $entry = ['round' => $round, 'choices' => $chosen, 'auto' => $missing, 'damage' => [$a => 0, $b => 0]];

        // Giving up ends it before any blow.
        $surrendered = array_values(array_filter($ids, static fn (int $id): bool => $chosen[$id] === self::SURRENDER));
        if ($surrendered !== []) {
            $entry['hp'] = $this->hitpoints($data);
            $outcome = count($surrendered) === 2
                ? Outcome::draw(Outcome::SURRENDER, $round)
                : Outcome::won($surrendered[0] === $a ? $b : $a, $surrendered[0], Outcome::SURRENDER, $round);

            return $this->finished($data, $entry, $outcome);
        }

        $data['idle'] = count($missing) === 2 ? (int) $data['idle'] + 1 : 0;
        if ($data['idle'] >= self::IDLE_ROUNDS) {
            $entry['hp'] = $this->hitpoints($data);

            return $this->finished($data, $entry, Outcome::draw(Outcome::ABANDONED, $round));
        }

        // Who swings first is the roll's own business; either side lands or
        // ripostes within one exchange.
        [$self, $other] = $random->int(0, 1) === 0 ? [$a, $b] : [$b, $a];
        $damage = $this->exchange($data['players'][(string) $self], $chosen[$self], $data['players'][(string) $other], $chosen[$other], $random);
        $entry['damage'] = [$self => $damage[0], $other => $damage[1]];
        foreach ([$self => $damage[0], $other => $damage[1]] as $id => $taken) {
            $data['players'][(string) $id]['hp'] = (int) $data['players'][(string) $id]['hp'] - $taken;
        }
        $entry['hp'] = $this->hitpoints($data);

        $hpA = (int) $data['players'][(string) $a]['hp'];
        $hpB = (int) $data['players'][(string) $b]['hp'];
        if ($hpA <= 0 || $hpB <= 0) {
            // Both down: whoever is less far below goes on standing.
            $outcome = $hpA === $hpB
                ? Outcome::draw(Outcome::KNOCKOUT, $round)
                : ($hpA > $hpB ? Outcome::won($a, $b, Outcome::KNOCKOUT, $round) : Outcome::won($b, $a, Outcome::KNOCKOUT, $round));

            return $this->finished($data, $entry, $outcome);
        }
        if ($round >= $this->maxRounds) {
            $shareA = $hpA / max(1, (int) $data['players'][(string) $a]['maxhp']);
            $shareB = $hpB / max(1, (int) $data['players'][(string) $b]['maxhp']);
            $outcome = abs($shareA - $shareB) < 0.00001
                ? Outcome::draw(Outcome::ROUNDS, $round)
                : ($shareA > $shareB ? Outcome::won($a, $b, Outcome::ROUNDS, $round) : Outcome::won($b, $a, Outcome::ROUNDS, $round));

            return $this->finished($data, $entry, $outcome);
        }

        $data['round'] = $round + 1;
        $data['log'] = $this->logged($data, $entry);

        return ['data' => $data, 'round' => $entry, 'outcome' => null];
    }

    /**
     * One exchange: the damage each side takes.
     *
     * @param array<string,mixed> $self
     * @param array<string,mixed> $other
     * @return array{0:int,1:int} Damage to $self, damage to $other.
     */
    private function exchange(array $self, string $selfChoice, array $other, string $otherChoice, RandomSource $random): array
    {
        [$selfAtk, $selfDef] = $this->mods($selfChoice);
        [$otherAtk, $otherDef] = $this->mods($otherChoice);
        $badguy = [
            'creatureattack' => (float) $other['attack'],
            'creaturedefense' => (float) $other['defense'] * $otherDef,
            'creaturehealth' => (int) $other['hp'],
            'physicalresistance' => 0,
        ];
        $roll = Battle::computePlayerDamage(
            $badguy,
            new Combatant((float) $self['attack'], (float) $self['defense'], (int) $self['hp']),
            new CombatContext(isPvp: true, creatureAtkMod: $otherAtk, atkMod: $selfAtk, defMod: $selfDef),
            $random
        );
        $toOther = (int) $roll->creatureDamage;
        $toSelf = (int) $roll->selfDamage;

        // As battle.php reads them: a negative figure is the riposte the
        // other side lands.
        return [
            max(0, -$toOther) + max(0, $toSelf),
            max(0, $toOther) + max(0, -$toSelf),
        ];
    }

    /**
     * @return array{0:float,1:float} Attack and defense factors of a choice.
     */
    private function mods(string $choice): array
    {
        return $choice === self::GUARD ? [self::GUARD_ATTACK, self::GUARD_DEFENSE] : [1.0, 1.0];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,int>
     */
    private function hitpoints(array $data): array
    {
        $hp = [];
        foreach ($data['players'] as $id => $player) {
            $hp[(int) $id] = (int) $player['hp'];
        }

        return $hp;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $entry
     * @return array{data:array<string,mixed>,round:array<string,mixed>,outcome:Outcome}
     */
    private function finished(array $data, array $entry, Outcome $outcome): array
    {
        $data['log'] = $this->logged($data, $entry);
        $data['result'] = $outcome->toArray();

        return ['data' => $data, 'round' => $entry, 'outcome' => $outcome];
    }

    /**
     * The last rounds, newest first, for the page.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $entry
     * @return list<array<string,mixed>>
     */
    private function logged(array $data, array $entry): array
    {
        return array_slice([$entry, ...(array) ($data['log'] ?? [])], 0, 5);
    }
}
