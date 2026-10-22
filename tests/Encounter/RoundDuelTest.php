<?php

declare(strict_types=1);

namespace Lotgd\Tests\Encounter;

use Lotgd\Encounter\Outcome;
use Lotgd\Encounter\RoundDuel;
use Lotgd\Tests\Stubs\ScriptedRandom;
use PHPUnit\Framework\TestCase;

/**
 * The round duel's arithmetic (docs/ReactiveCore.md, Phase 7), with the
 * dice written down: one exchange of the PvP damage roll per round, what
 * guarding and giving up do, and how a duel ends.
 *
 * Each exchange rolls who swings (int 0..1), the roll's crit check (int,
 * never a crit in PvP) and four bells: the swing, the other's defense, the
 * swinger's defense, the other's attack.
 */
final class RoundDuelTest extends TestCase
{
    private const A = 2;
    private const B = 5;

    public function testTheValuesTheDuelStartsWith(): void
    {
        $data = $this->start();

        self::assertSame(['name' => 'Ann', 'level' => 5, 'attack' => 10.0, 'defense' => 10.0, 'maxhp' => 30, 'hp' => 30], $data['players']['2']);
        self::assertSame(1, $data['round']);
        self::assertNull($data['result']);
    }

    public function testAPlayerWithNoHitpointsLeftStillStartsWithOne(): void
    {
        $data = (new RoundDuel())->start([self::A => $this->player('Ann', 0), self::B => $this->player('Bo', 30)]);

        self::assertSame(1, $data['players']['2']['hp']);
    }

    public function testARoundIsOneExchangeAndBothSidesTakeTheirDamage(): void
    {
        $random = new ScriptedRandom([0, 5], [8, 3, 2, 6]);

        $result = (new RoundDuel())->resolve($this->start(), [self::A => 'attack', self::B => 'attack'], $random);

        self::assertTrue($random->isDrained());
        self::assertSame([self::A => 4, self::B => 5], $result['round']['damage']);
        self::assertSame(26, $result['data']['players']['2']['hp']);
        self::assertSame(25, $result['data']['players']['5']['hp']);
        self::assertSame(2, $result['data']['round'], 'The next round.');
        self::assertNull($result['outcome']);
        self::assertSame($result['round'], $result['data']['log'][0]);
    }

    public function testARiposteHitsTheSwinger(): void
    {
        // The swing misses by 6: half of it comes back.
        $random = new ScriptedRandom([1, 5], [2, 8, 9, 1]);

        $result = (new RoundDuel())->resolve($this->start(), [self::A => 'attack', self::B => 'attack'], $random);

        // B swung (int 1); its riposte is 3 to B, and A's blow is parried back for 4 to A.
        self::assertSame([self::B => 3 + 0, self::A => 4], $result['round']['damage']);
    }

    public function testGuardingHalvesTheAttackAndRaisesTheDefense(): void
    {
        $random = new ScriptedRandom([0, 5], [2, 3, 2, 6]);

        (new RoundDuel())->resolve($this->start(), [self::A => 'guard', self::B => 'attack'], $random);

        self::assertSame([[0, 5.0], [0, 10.0], [0, 15.0], [0, 10.0]], $random->bellRanges());
    }

    public function testThePlayerWhoDidNotChooseAttacks(): void
    {
        $random = new ScriptedRandom([0, 5], [8, 3, 2, 6]);

        $result = (new RoundDuel())->resolve($this->start(), [self::A => 'guard', self::B => null], $random);

        self::assertSame([self::B], $result['round']['auto']);
        self::assertSame('attack', $result['round']['choices'][self::B]);
        self::assertSame(0, $result['data']['idle'], 'One of them chose.');
    }

    public function testGivingUpEndsTheDuelBeforeAnyBlow(): void
    {
        $random = new ScriptedRandom();

        $result = (new RoundDuel())->resolve($this->start(), [self::A => 'surrender', self::B => null], $random);

        self::assertEquals(Outcome::won(self::B, self::A, Outcome::SURRENDER, 1), $result['outcome']);
        self::assertSame(['winner' => self::B, 'loser' => self::A, 'reason' => 'surrender', 'rounds' => 1], $result['data']['result']);
        self::assertSame([self::A => 30, self::B => 30], $result['round']['hp']);
    }

    public function testBothGivingUpIsADraw(): void
    {
        $result = (new RoundDuel())->resolve($this->start(), [self::A => 'surrender', self::B => 'surrender'], new ScriptedRandom());

        self::assertTrue($result['outcome']->isDraw());
    }

    public function testAKnockoutWins(): void
    {
        $data = $this->start();
        $data['players']['5']['hp'] = 4;

        $result = (new RoundDuel())->resolve($data, [self::A => 'attack', self::B => 'attack'], new ScriptedRandom([0, 5], [8, 3, 2, 6]));

        self::assertEquals(Outcome::won(self::A, self::B, Outcome::KNOCKOUT, 1), $result['outcome']);
    }

    public function testBothDownTheOneLessFarBelowWins(): void
    {
        $data = $this->start();
        $data['players']['2']['hp'] = 3;
        $data['players']['5']['hp'] = 3;

        // A takes 4 (to -1), B takes 5 (to -2).
        $result = (new RoundDuel())->resolve($data, [self::A => 'attack', self::B => 'attack'], new ScriptedRandom([0, 5], [8, 3, 2, 6]));

        self::assertEquals(Outcome::won(self::A, self::B, Outcome::KNOCKOUT, 1), $result['outcome']);
    }

    public function testAfterTheLastRoundTheShareOfHitpointsLeftDecides(): void
    {
        $data = $this->start();
        $data['round'] = 3;

        $result = (new RoundDuel(3))->resolve($data, [self::A => 'attack', self::B => 'attack'], new ScriptedRandom([0, 5], [8, 3, 2, 6]));

        self::assertEquals(Outcome::won(self::A, self::B, Outcome::ROUNDS, 3), $result['outcome']);
    }

    public function testTwoRoundsWithoutAChoiceCallTheDuelOff(): void
    {
        $duel = new RoundDuel();
        $first = $duel->resolve($this->start(), [self::A => null, self::B => null], new ScriptedRandom([0, 5], [8, 3, 2, 6]));
        self::assertNull($first['outcome']);
        self::assertSame(1, $first['data']['idle']);

        $second = $duel->resolve($first['data'], [self::A => null, self::B => null], new ScriptedRandom());

        self::assertEquals(Outcome::draw(Outcome::ABANDONED, 2), $second['outcome']);
    }

    public function testTheLogKeepsTheLastFiveRoundsNewestFirst(): void
    {
        $duel = new RoundDuel();
        $data = $this->start();
        $data['players']['2']['hp'] = 1000;
        $data['players']['5']['hp'] = 1000;
        for ($i = 0; $i < 7; $i++) {
            $data = $duel->resolve($data, [self::A => 'attack', self::B => 'attack'], new ScriptedRandom([0, 5], [8, 3, 2, 6]))['data'];
        }

        self::assertCount(5, $data['log']);
        self::assertSame([7, 6, 5, 4, 3], array_column($data['log'], 'round'));
    }

    /**
     * @return array<string,mixed>
     */
    private function start(): array
    {
        return (new RoundDuel())->start([self::A => $this->player('Ann', 30), self::B => $this->player('Bo', 30)]);
    }

    /**
     * @return array<string,mixed>
     */
    private function player(string $name, int $hitpoints): array
    {
        return ['name' => $name, 'level' => 5, 'attack' => 10, 'defense' => 10, 'hitpoints' => $hitpoints];
    }
}
