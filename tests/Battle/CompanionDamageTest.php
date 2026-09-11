<?php

declare(strict_types=1);

namespace Lotgd\Tests\Battle;

use Lotgd\Battle;
use Lotgd\Battle\CombatContext;
use Lotgd\Battle\Combatant;
use Lotgd\Battle\DamageRoll;
use Lotgd\Tests\Stubs\ScriptedRandom;
use PHPUnit\Framework\TestCase;

/**
 * Battle::computeCompanionDamage() is the near-twin of the player roll, and the
 * cases below are deliberately the ones where the twins differ. The shared
 * behaviour -- the signed damage figures, the halved riposte, the crossed
 * modifiers, the re-roll while nothing happens -- is stated once in
 * PlayerDamageTest and only re-checked here where the companion changes it.
 *
 * Four rules separate them:
 *
 *   1. a critical hit triples the attack score instead of doubling it;
 *   2. there is no power attack, so the creature never gets a bonus swing;
 *   3. physical resistance does not apply to either side;
 *   4. the companion's own blows scale with compdmgmod rather than dmgmod.
 *
 * And one safeguard the player roll lacks: after fifty fruitless exchanges the
 * companion is awarded a single point of damage rather than rolling forever.
 */
final class CompanionDamageTest extends TestCase
{
    /**
     * @return array<string, int>
     */
    private static function badguy(): array
    {
        return ['creaturehealth' => 50, 'creatureattack' => 12, 'creaturedefense' => 8];
    }

    private static function companion(float $resistance = 0.0): Combatant
    {
        return new Combatant(attack: 14.0, defense: 9.0, hitpoints: 30.0, resistance: $resistance);
    }

    /**
     * @param list<int>       $ints
     * @param list<int|float> $bells
     */
    private function roll(
        array &$badguy,
        array $ints,
        array $bells,
        ?CombatContext $context = null,
        ?Combatant $companion = null
    ): DamageRoll {
        $random = new ScriptedRandom($ints, $bells);
        $roll = Battle::computeCompanionDamage(
            $badguy,
            $companion ?? self::companion(),
            $context ?? new CombatContext(),
            $random
        );

        self::assertTrue(
            $random->isDrained(),
            'the roll took a different path than scripted, leftovers: ' . json_encode($random->remaining())
        );

        return $roll;
    }

    /**
     * The baseline, so the cases that follow have something to differ from.
     */
    public function testDamageIsTheDifferenceBetweenTheTwoRolls(): void
    {
        $badguy = self::badguy();
        $roll = $this->roll($badguy, [5], [12.0, 4.0, 9.0, 3.0]);

        self::assertSame(8.0, $roll->creatureDamage, 'the companion lands the full margin');
        self::assertSame(-3.0, $roll->selfDamage, 'the creature misses and takes the halved riposte');
    }

    /**
     * A companion's critical hit triples where the player's doubles.
     *
     * Like the player's it widens the range the attack is rolled against rather
     * than multiplying the result, so the assertion is on the range.
     */
    public function testACriticalHitTriplesTheAttackScore(): void
    {
        $badguy = self::badguy();
        $random = new ScriptedRandom([1], [12.0, 4.0, 9.0, 3.0]);
        Battle::computeCompanionDamage($badguy, self::companion(), new CombatContext(), $random);

        self::assertSame([0, 42.0], $random->bellRanges()[0], 'an attack score of 14 tripled, not doubled');

        $badguy = self::badguy();
        $random = new ScriptedRandom([5], [12.0, 4.0, 9.0, 3.0]);
        Battle::computeCompanionDamage($badguy, self::companion(), new CombatContext(), $random);

        self::assertSame([0, 14.0], $random->bellRanges()[0], 'control: without the crit the score stands');
    }

    /**
     * The companion's blows scale with compdmgmod, and the creature's with
     * badguydmgmod -- crossed the same way the player's are, but with a
     * different modifier on the companion's side.
     */
    public function testTheCompanionsBlowsScaleWithTheCompanionModifier(): void
    {
        $context = new CombatContext(compDmgMod: 3.0, badguyDmgMod: 5.0, dmgMod: 100.0);

        $badguy = self::badguy();
        $hits = $this->roll($badguy, [5], [12.0, 4.0, 3.0, 9.0], $context);

        self::assertSame(24.0, $hits->creatureDamage, 'the companion lands 8, tripled by compdmgmod');
        self::assertSame(30.0, $hits->selfDamage, 'the creature lands 6, quintupled by badguydmgmod');

        $badguy = self::badguy();
        $ripostes = $this->roll($badguy, [5], [3.0, 15.0, 9.0, 3.0], $context);

        self::assertSame(-30.0, $ripostes->creatureDamage, 'the creature ripostes 6, quintupled by badguydmgmod');
        self::assertSame(-9.0, $ripostes->selfDamage, 'the companion ripostes 3, tripled by compdmgmod');
    }

    /**
     * dmgmod is set absurdly high in the case above and appears in none of the
     * four figures. Stated on its own so the reason is not merely implied: the
     * player's damage modifier has no say over a companion's blows.
     */
    public function testThePlayersDamageModifierDoesNotReachTheCompanion(): void
    {
        $badguy = self::badguy();
        $roll = $this->roll($badguy, [5], [12.0, 4.0, 9.0, 3.0], new CombatContext(dmgMod: 100.0));

        self::assertSame(8.0, $roll->creatureDamage, 'unscaled, as if dmgmod were 1');
        self::assertSame(-3.0, $roll->selfDamage);
    }

    /**
     * Physical resistance protects a player and does nothing for a companion.
     *
     * The same figure in PlayerDamageTest is reduced to nothing by a resistance
     * this large; here it lands in full.
     */
    public function testResistanceDoesNotProtectACompanion(): void
    {
        $badguy = self::badguy();
        $roll = $this->roll($badguy, [5], [12.0, 4.0, 3.0, 9.0], null, self::companion(resistance: 400.0));

        self::assertSame(6.0, $roll->selfDamage, 'the creature lands 6 regardless');
    }

    /**
     * There is no power attack in a companion's exchange: the forest settings
     * are on, and no die is drawn for them.
     *
     * The drained assertion is what proves it -- an extra draw would leave the
     * script short.
     */
    public function testTheForestPowerAttackDoesNotApply(): void
    {
        $badguy = self::badguy();
        $roll = $this->roll(
            $badguy,
            [5],
            [12.0, 4.0, 9.0, 3.0],
            new CombatContext(powerAttackChance: 10, powerAttackMulti: 3.0)
        );

        self::assertSame(-3.0, $roll->selfDamage, 'the same figures as with the settings off');
    }

    /**
     * The safeguard the player roll does not have.
     *
     * Fifty exchanges in which neither side can gain a margin end with the
     * companion awarded a single point of damage, rather than the loop spinning
     * forever. Scripted with exactly fifty crit draws and two hundred rolls, so
     * the drained assertion pins the count as well as the outcome.
     */
    public function testFiftyFruitlessExchangesEndInASinglePointOfDamage(): void
    {
        $badguy = self::badguy();
        $roll = $this->roll(
            $badguy,
            array_fill(0, 50, 5),
            array_fill(0, 200, 5.0)
        );

        self::assertSame(1, $roll->creatureDamage, 'the companion is given the point');
        self::assertSame(0, $roll->selfDamage, 'and takes nothing');
    }

    /**
     * God mode reaches the companions too, with both signs forced.
     */
    public function testInvulnerabilityForcesTheSignsBothWays(): void
    {
        $badguy = self::badguy();
        $roll = $this->roll($badguy, [5], [3.0, 15.0, 3.0, 9.0], new CombatContext(invulnerable: true));

        self::assertSame(6.0, $roll->creatureDamage, 'the creature riposte becomes a companion hit');
        self::assertSame(-6.0, $roll->selfDamage, 'the creature hit becomes a companion riposte');
    }

    /**
     * PvP takes the defender's defence as it stands, as it does for the player.
     */
    public function testPvpSkipsTheForestDefenceAdjustment(): void
    {
        $context = fn (bool $pvp): CombatContext => new CombatContext(
            isPvp: $pvp,
            adjustment: 2.0,
            creatureDefMod: 1.0,
        );

        $badguy = self::badguy();
        $random = new ScriptedRandom([5], [12.0, 4.0, 9.0, 3.0]);
        Battle::computeCompanionDamage($badguy, self::companion(), $context(false), $random);

        self::assertSame([0, 2.0], $random->bellRanges()[1], 'the forest divides by the adjustment squared');

        $badguy = self::badguy();
        $random = new ScriptedRandom([5], [12.0, 4.0, 9.0, 3.0]);
        Battle::computeCompanionDamage($badguy, self::companion(), $context(true), $random);

        self::assertSame([0, 8], $random->bellRanges()[1], 'PvP uses the raw defence');
    }

    public function testNothingIsRolledAgainstADeadCreature(): void
    {
        $badguy = ['creaturehealth' => 0, 'creatureattack' => 12, 'creaturedefense' => 8];
        $roll = $this->roll($badguy, [], []);

        self::assertSame(0, $roll->creatureDamage);
        self::assertSame(0, $roll->selfDamage);
    }

    public function testAFallenCompanionRollsNothingEither(): void
    {
        $badguy = self::badguy();
        $roll = $this->roll($badguy, [], [], null, new Combatant(14.0, 9.0, 0.0));

        self::assertSame(0, $roll->creatureDamage);
        self::assertSame(0, $roll->selfDamage);
    }

    /**
     * As with the player roll: no exchange means no roll to report, which is
     * what keeps rollCompanionDamage() from clearing the legacy globals.
     */
    public function testNoExchangeReportsNoRollRatherThanAZeroRoll(): void
    {
        $badguy = ['creaturehealth' => 0, 'creatureattack' => 12, 'creaturedefense' => 8];
        $none = $this->roll($badguy, [], []);

        self::assertNull($none->attackRoll);
        self::assertNull($none->creatureAttack);

        $badguy = self::badguy();
        $fought = $this->roll($badguy, [5], [12.0, 4.0, 9.0, 3.0]);

        self::assertNotNull($fought->attackRoll, 'control: a real exchange reports both');
        self::assertNotNull($fought->creatureAttack);
    }

    /**
     * Unlike the player roll, this one does not reach into the creature array --
     * a missing physicalresistance is left missing, because nothing here reads
     * it.
     */
    public function testTheCreatureArrayIsLeftAlone(): void
    {
        $badguy = self::badguy();
        $before = $badguy;
        $this->roll($badguy, [5], [12.0, 4.0, 9.0, 3.0]);

        self::assertSame($before, $badguy);
    }
}
