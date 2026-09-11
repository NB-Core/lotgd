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
 * Battle::computePlayerDamage() is the arithmetic every fight in the game runs
 * through -- forest, PvP, the dragon. It had no tests at all, because until the
 * randomness was injectable there was nothing to write: random_int() is a CSPRNG
 * and cannot be seeded, so any test of the old code was either flaky or asserted
 * only that nothing threw.
 *
 * Both damage figures are signed, and the sign is the whole story
 * (battle.php:606, :685): a positive creature damage is the player landing a
 * blow, a negative one is the creature riposting; a positive self damage is the
 * creature landing one, a negative one is the player riposting.
 *
 * Randomness is consumed in a fixed order per iteration -- the critical-hit
 * roll, then the player's attack roll, the creature's defence roll, the player's
 * defence roll and the creature's attack roll, then the power-attack roll. Every
 * case below scripts exactly that many values and asserts the script ran dry, so
 * a case that silently takes a different path through the method shows up as a
 * failure rather than passing for the wrong reason.
 */
final class PlayerDamageTest extends TestCase
{
    /**
     * A creature the player can trade blows with.
     *
     * @return array<string, int>
     */
    private static function badguy(int $resistance = 0): array
    {
        return [
            'creaturehealth' => 50,
            'creatureattack' => 12,
            'creaturedefense' => 8,
            'physicalresistance' => $resistance,
        ];
    }

    private static function player(float $resistance = 0.0): Combatant
    {
        return new Combatant(attack: 20.0, defense: 10.0, hitpoints: 100.0, resistance: $resistance);
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
        ?Combatant $self = null
    ): DamageRoll {
        $random = new ScriptedRandom($ints, $bells);
        $roll = Battle::computePlayerDamage(
            $badguy,
            $self ?? self::player(),
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
     * The baseline: damage is the difference between the two rolls, and a single
     * exchange settles it.
     *
     * The player rolls 12 against a defence roll of 4 and lands 8. The creature
     * rolls 3 against the player's defence roll of 9, misses by 6 and eats the
     * riposte -- halved to 3, which is the next case.
     */
    public function testDamageIsTheDifferenceBetweenTheTwoRolls(): void
    {
        $badguy = self::badguy();
        $roll = $this->roll($badguy, [5], [12.0, 4.0, 9.0, 3.0]);

        self::assertSame(8.0, $roll->creatureDamage, 'the player lands the full margin');
        self::assertSame(-3.0, $roll->selfDamage, 'the creature misses and takes the riposte');
    }

    /**
     * A riposte is halved before any modifier touches it; a landed blow is not.
     *
     * Without the halving the creature's counter here would be 12 rather than 6,
     * so riposting would pay better than attacking.
     */
    public function testARiposteIsHalvedAndALandedBlowIsNot(): void
    {
        $badguy = self::badguy();
        $riposte = $this->roll($badguy, [5], [3.0, 15.0, 9.0, 3.0]);

        self::assertSame(-6.0, $riposte->creatureDamage, 'a margin of 12 against the player becomes 6');

        $badguy = self::badguy();
        $landed = $this->roll($badguy, [5], [15.0, 3.0, 9.0, 3.0]);

        self::assertSame(12.0, $landed->creatureDamage, 'the same margin in the other direction is paid in full');
    }

    /**
     * The least obvious rule in the method: the modifiers are crossed.
     *
     * A blow is scaled by the modifier belonging to the side that *lands* it,
     * not the side that swings. So the player's hit takes dmgmod and the
     * creature's hit takes badguydmgmod -- and on the ripostes it is the other
     * way round, because the riposte is landed by the other side.
     *
     * Swapping the pair in either branch is a change no reviewer would catch by
     * eye and would silently rebalance every fight in the game.
     */
    public function testAHitIsScaledByTheSideThatLandsIt(): void
    {
        $context = new CombatContext(dmgMod: 3.0, badguyDmgMod: 5.0);

        $badguy = self::badguy();
        $hits = $this->roll($badguy, [5], [12.0, 4.0, 3.0, 9.0], $context);

        self::assertSame(24.0, $hits->creatureDamage, 'the player lands 8, tripled by dmgmod');
        self::assertSame(30.0, $hits->selfDamage, 'the creature lands 6, quintupled by badguydmgmod');

        $badguy = self::badguy();
        $ripostes = $this->roll($badguy, [5], [3.0, 15.0, 9.0, 3.0], $context);

        self::assertSame(-30.0, $ripostes->creatureDamage, 'the creature ripostes 6, quintupled by badguydmgmod');
        self::assertSame(-9.0, $ripostes->selfDamage, 'the player ripostes 3, tripled by dmgmod');
    }

    /**
     * Physical resistance subtracts from a blow that lands, and the result is
     * floored at zero rather than turning into damage the other way.
     */
    public function testResistanceSubtractsFromALandedBlowAndCannotInvertIt(): void
    {
        $badguy = self::badguy(resistance: 4);
        $reduced = $this->roll($badguy, [5], [12.0, 4.0, 9.0, 3.0]);

        self::assertSame(4.0, $reduced->creatureDamage, '8 less the creature resistance of 4');

        $badguy = self::badguy(resistance: 400);
        $absorbed = $this->roll($badguy, [5], [12.0, 4.0, 9.0, 3.0]);

        // Integer zero rather than 0.0: the floor is the literal 0 in max(),
        // and it is that literal that wins once resistance outweighs the blow.
        // Partial damage above stays a float, because round() produced it.
        self::assertSame(0, $absorbed->creatureDamage, 'resistance beyond the blow absorbs it, it does not heal');
    }

    /**
     * The same subtraction on the other side of the exchange.
     */
    public function testResistanceProtectsThePlayerToo(): void
    {
        $badguy = self::badguy();
        $roll = $this->roll($badguy, [5], [12.0, 4.0, 3.0, 9.0], null, self::player(resistance: 4.0));

        self::assertSame(2.0, $roll->selfDamage, 'the creature lands 6, less the player resistance of 4');
    }

    /**
     * Resistance has no say over a riposte, on either side of the exchange.
     *
     * It used to: the subtraction ran on a negative figure, so a combatant's
     * own resistance made their own counter-blow *harder*. Measured over 50000
     * rounds, a creature with 10 physical resistance riposted for 10.93 where
     * one with none riposted for 1.45 -- so a resistant creature absorbed more
     * and hit back seven times harder for it.
     *
     * Damping instead of amplifying was tried and rejected: a riposte is
     * already halved and therefore small, so any meaningful resistance floors
     * it at zero and the mechanic disappears. Dropping the term leaves the
     * riposte at the halved margin times its modifier, independent of
     * resistance, while resistance keeps doing its real job on a blow that
     * lands.
     */
    public function testResistanceDoesNotTouchARiposte(): void
    {
        $badguy = self::badguy();
        $unresisted = $this->roll($badguy, [5], [12.0, 4.0, 9.0, 3.0]);

        self::assertSame(-3.0, $unresisted->selfDamage, 'control: the player ripostes for 3');

        $badguy = self::badguy();
        $resisted = $this->roll($badguy, [5], [12.0, 4.0, 9.0, 3.0], null, self::player(resistance: 4.0));

        self::assertSame(-3.0, $resisted->selfDamage, 'the player resistance changes nothing');

        $badguy = self::badguy(resistance: 4);
        $creatureRiposte = $this->roll($badguy, [5], [3.0, 15.0, 9.0, 3.0]);

        self::assertSame(-6.0, $creatureRiposte->creatureDamage, 'and neither does the creature resistance');
    }

    /**
     * God mode: whatever the dice said, the player hits and is not hit.
     *
     * Scripted so that both figures come out with the wrong sign first -- the
     * creature ripostes, the creature lands a blow -- and both are flipped.
     */
    public function testInvulnerabilityForcesTheSignsBothWays(): void
    {
        $badguy = self::badguy();
        $roll = $this->roll($badguy, [5], [3.0, 15.0, 3.0, 9.0], new CombatContext(invulnerable: true));

        self::assertSame(6.0, $roll->creatureDamage, 'the creature riposte becomes a player hit');
        self::assertSame(-6.0, $roll->selfDamage, 'the creature hit becomes a player riposte');
    }

    /**
     * PvP takes the defender's defence as it stands; the forest scales it by the
     * creature defence modifier over the square of the difficulty adjustment.
     *
     * The scripted source ignores the range it is asked for, so the assertion is
     * on the range itself -- that is the only place the two paths differ.
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
        Battle::computePlayerDamage($badguy, self::player(), $context(false), $random);

        self::assertSame([0, 2.0], $random->bellRanges()[1], 'the forest divides by the adjustment squared');

        $badguy = self::badguy();
        $random = new ScriptedRandom([5], [12.0, 4.0, 9.0, 3.0]);
        Battle::computePlayerDamage($badguy, self::player(), $context(true), $random);

        self::assertSame([0, 8], $random->bellRanges()[1], 'PvP uses the raw defence');
    }

    /**
     * A critical hit doubles the attack score the roll is made against, so it
     * widens the range rather than multiplying the result.
     *
     * It is drawn even in PvP -- the condition reads the die first and the
     * fight type second -- and simply discarded there. That matters to anyone
     * scripting this method, which is why the PvP half is asserted as well.
     */
    public function testACriticalHitDoublesTheAttackScore(): void
    {
        $badguy = self::badguy();
        $random = new ScriptedRandom([1], [12.0, 4.0, 9.0, 3.0]);
        Battle::computePlayerDamage($badguy, self::player(), new CombatContext(), $random);

        self::assertSame([0, 40.0], $random->bellRanges()[0], 'the attack score of 20 is doubled');

        $badguy = self::badguy();
        $random = new ScriptedRandom([1], [12.0, 4.0, 9.0, 3.0]);
        Battle::computePlayerDamage($badguy, self::player(), new CombatContext(isPvp: true), $random);

        self::assertSame([0, 20.0], $random->bellRanges()[0], 'PvP draws the die but ignores it');
        self::assertTrue($random->isDrained(), 'and the draw is still consumed');
    }

    /**
     * The forest's power attack multiplies the creature's attack roll, and the
     * die for it is drawn after the four rolls rather than before.
     */
    public function testThePowerAttackMultipliesTheCreaturesRoll(): void
    {
        $context = new CombatContext(powerAttackChance: 10, powerAttackMulti: 3.0);

        $badguy = self::badguy();
        $struck = $this->roll($badguy, [5, 1], [12.0, 4.0, 2.0, 4.0], $context);

        self::assertSame(10.0, $struck->selfDamage, 'a creature roll of 4 tripled to 12 against a defence roll of 2');

        $badguy = self::badguy();
        $spared = $this->roll($badguy, [5, 2], [12.0, 4.0, 2.0, 4.0], $context);

        self::assertSame(2.0, $spared->selfDamage, 'without the power attack the same rolls give 2');
    }

    /**
     * A power attack chance of zero draws no die at all, rather than drawing one
     * that can never come up.
     */
    public function testAPowerAttackChanceOfZeroDrawsNothing(): void
    {
        $badguy = self::badguy();
        $this->roll($badguy, [5], [12.0, 4.0, 2.0, 4.0], new CombatContext(powerAttackChance: 0));

        self::assertTrue(true, 'the drained assertion in roll() is the point of this case');
    }

    /**
     * A round always produces something: while both figures are zero the whole
     * exchange is rolled again.
     *
     * The first four rolls here are all equal, so neither side has a margin.
     */
    public function testTheExchangeIsRolledAgainWhileNothingHappens(): void
    {
        $badguy = self::badguy();
        $roll = $this->roll(
            $badguy,
            [5, 5],
            [5.0, 5.0, 5.0, 5.0, 12.0, 4.0, 9.0, 3.0]
        );

        self::assertSame(8.0, $roll->creatureDamage, 'the second exchange is the one that counts');
        self::assertSame(-3.0, $roll->selfDamage);
    }

    /**
     * Fifty exchanges in which neither side can gain a margin end with the
     * player awarded a single point of damage, rather than the loop spinning
     * forever.
     *
     * The safeguard was missing here while the companion roll has always had
     * it. Reaching it needs two combatants who can neither hit nor be hit, so
     * no real fight gets near it -- but nothing stopped a module from building
     * one, and the failure mode was a hung request rather than a wrong number.
     *
     * Scripted with exactly fifty crit draws and two hundred rolls, so the
     * drained assertion pins the count as well as the outcome.
     */
    public function testFiftyFruitlessExchangesEndInASinglePointOfDamage(): void
    {
        $badguy = self::badguy();
        $roll = $this->roll(
            $badguy,
            array_fill(0, 50, 5),
            array_fill(0, 200, 5.0)
        );

        self::assertSame(1, $roll->creatureDamage, 'the player is given the point');
        self::assertSame(0, $roll->selfDamage, 'and takes nothing');
    }

    /**
     * The attack roll is reported, not the attack score.
     *
     * battle.php:603 hands this value to Battle::reportPowerMove(), which
     * compares it against the player's attack to decide whether to announce a
     * power move. Reporting the score instead would make every blow a power
     * move.
     */
    public function testTheReportedAttackRollIsTheRollNotTheScore(): void
    {
        $badguy = self::badguy();
        $roll = $this->roll($badguy, [5], [7.0, 4.0, 9.0, 3.0]);

        self::assertSame(7.0, $roll->attackRoll, 'the roll');
        self::assertNotSame(20.0, $roll->attackRoll, 'not the score of 20 it was rolled against');
    }

    /**
     * On a re-rolled exchange the reported roll is the last one, which is the
     * one that produced the damage.
     */
    public function testTheReportedAttackRollComesFromTheDecidingExchange(): void
    {
        $badguy = self::badguy();
        $roll = $this->roll(
            $badguy,
            [5, 5],
            [5.0, 5.0, 5.0, 5.0, 12.0, 4.0, 9.0, 3.0]
        );

        self::assertSame(12.0, $roll->attackRoll, 'the second exchange, not the first');
    }

    /**
     * A creature that is already down is not fought, and no randomness is spent
     * on finding that out.
     */
    public function testNothingIsRolledAgainstADeadCreature(): void
    {
        $badguy = ['creaturehealth' => 0, 'creatureattack' => 12, 'creaturedefense' => 8];
        $roll = $this->roll($badguy, [], []);

        self::assertSame(0, $roll->creatureDamage);
        self::assertSame(0, $roll->selfDamage);
    }

    public function testADeadPlayerRollsNothingEither(): void
    {
        $badguy = self::badguy();
        $roll = $this->roll($badguy, [], [], null, new Combatant(20.0, 10.0, 0.0));

        self::assertSame(0, $roll->creatureDamage);
        self::assertSame(0, $roll->selfDamage);
    }

    /**
     * A creature arriving without a resistance gets one, because the subtraction
     * below would otherwise raise an undefined-key warning on every blow.
     */
    public function testAMissingCreatureResistanceIsFilledIn(): void
    {
        $badguy = ['creaturehealth' => 50, 'creatureattack' => 12, 'creaturedefense' => 8];
        $this->roll($badguy, [5], [12.0, 4.0, 9.0, 3.0]);

        self::assertSame(0, $badguy['physicalresistance']);
    }
}
