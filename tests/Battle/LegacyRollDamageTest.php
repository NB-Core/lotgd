<?php

declare(strict_types=1);

namespace Lotgd\Tests\Battle;

use Lotgd\Battle;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * Battle::rollDamage() and rollCompanionDamage() are module API.
 * lib/battle-skills.php forwards the first as the global rolldamage(), and
 * AGENTS.md rules out breaking that inside 2.x.
 *
 * The arithmetic now lives in computePlayerDamage() and
 * computeCompanionDamage(), which read nothing from global scope; these two
 * methods are the adapters that still do. This file is the guard on those
 * adapters -- that they read every global they used to, publish every global
 * they used to, and hand back the same array shape. Without it the adapters are
 * the one unproved part of the refactor, and they are exactly the part modules
 * depend on.
 *
 * Randomness is real here rather than scripted, because that is what a module
 * gets. The assertions are therefore on shape and range, never on a particular
 * roll.
 */
final class LegacyRollDamageTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $creatureattack, $creatureatkmod, $adjustment;
        global $creaturedefmod, $defmod, $atkmod, $compatkmod, $compdefmod;
        global $buffset, $atk, $def, $options;

        Settings::setInstance(new DummySettings([
            'forestpowerattackchance' => 10,
            'forestpowerattackmulti' => 3,
        ]));

        $session = ['user' => [
            'hitpoints' => 100,
            'maxhitpoints' => 100,
            'attack' => 25,
            'defense' => 18,
            'strength' => 20,
            'dexterity' => 15,
            'constitution' => 14,
            'intelligence' => 12,
            'wisdom' => 11,
            'level' => 5,
            'dragonkills' => 0,
        ]];

        $options = ['type' => 'forest'];
        $adjustment = 1;
        $creatureatkmod = 1.0;
        $creaturedefmod = 1.0;
        $atkmod = 1.0;
        $defmod = 1.0;
        $compatkmod = 1.0;
        $compdefmod = 1.0;
        $creatureattack = null;
        $atk = null;
        $def = null;
        $buffset = [
            'dmgmod' => 1.0,
            'badguydmgmod' => 1.0,
            'compdmgmod' => 1.0,
            'invulnerable' => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function badguy(): array
    {
        return [
            'creaturehealth' => 50,
            'creatureattack' => 12,
            'creaturedefense' => 8,
        ];
    }

    public function testTheReturnShapeIsUnchanged(): void
    {
        $badguy = self::badguy();
        $roll = Battle::rollDamage($badguy);

        self::assertSame(['creaturedmg', 'selfdmg'], array_keys($roll), 'the keys modules read');
        self::assertIsNumeric($roll['creaturedmg']);
        self::assertIsNumeric($roll['selfdmg']);
        self::assertTrue(
            $roll['creaturedmg'] != 0 || $roll['selfdmg'] != 0,
            'a round produces something'
        );
    }

    /**
     * The one global with a reader left: battle.php:603 passes $atk to
     * reportPowerMove() to decide whether the blow was a power move.
     */
    public function testTheAttackRollIsPublishedToTheGlobal(): void
    {
        global $atk, $session;

        $badguy = self::badguy();
        Battle::rollDamage($badguy);

        self::assertNotNull($atk, 'the global is set');
        self::assertGreaterThanOrEqual(0, $atk);
        self::assertLessThanOrEqual(
            $session['user']['attack'] * 4,
            $atk,
            'and holds a roll, not something unrelated -- generously bounded because a crit doubles the score'
        );
    }

    /**
     * $creatureattack has no reader left anywhere in the codebase, but it is
     * still published in case a module has one. Asserted so that dropping it
     * is a deliberate act rather than an accident.
     */
    public function testTheCreatureAttackGlobalIsStillPublished(): void
    {
        global $creatureattack, $creatureatkmod;

        $creatureatkmod = 2.0;
        $badguy = self::badguy();
        Battle::rollDamage($badguy);

        self::assertSame(24.0, $creatureattack, 'the creature attack of 12 times the modifier');
    }

    /**
     * The adapter reads the buffset out of global scope rather than ignoring
     * it: god mode set there still reaches the arithmetic.
     *
     * The creature is given absurd stats on purpose. Against a fair one the
     * player wins most exchanges anyway, so asserting "the player was not hit"
     * would hold whether or not god mode was read -- the first version of this
     * test passed with the buffset ignored, and a mutation caught it. Here the
     * player loses both halves of the exchange by many thousands of points
     * unless the flag reaches the arithmetic and flips the signs.
     */
    public function testTheGlobalBuffsetStillReachesTheArithmetic(): void
    {
        global $buffset;

        $hopeless = static function (): array {
            $badguy = self::badguy();
            $badguy['creaturedefense'] = 100000;
            $badguy['creatureattack'] = 100000;

            return $badguy;
        };

        $badguy = $hopeless();
        $doomed = Battle::rollDamage($badguy);

        self::assertLessThan(0, $doomed['creaturedmg'], 'control: the player is riposted');
        self::assertGreaterThan(0, $doomed['selfdmg'], 'control: and is hit');

        $buffset['invulnerable'] = 1;

        $badguy = $hopeless();
        $roll = Battle::rollDamage($badguy);

        self::assertGreaterThan(0, $roll['creaturedmg'], 'god mode turns the riposte into a hit');
        self::assertLessThan(0, $roll['selfdmg'], 'and the hit into a riposte');
    }

    /**
     * The adapter reads $options out of global scope: it is what selects the
     * PvP path, on which the creature's defence is taken as it stands instead
     * of being divided by the square of the difficulty adjustment.
     *
     * The forest half of this is an arithmetic certainty rather than a
     * likelihood. A defence of 10000 over an adjustment of 100 squared is 1, so
     * the creature's defence roll cannot exceed 1; a margin of at most 1 is
     * truncated to nothing by the (int) cast, or halved to nothing on the
     * riposte branch. The player can therefore never be riposted -- measured
     * over 20000 rounds while writing this, and not once.
     *
     * The PvP half is a near-certainty rather than an arithmetic one, and the
     * distinction is worth stating because the first version of this test got
     * it wrong: BellRand clamps at roughly three sigma, so a roll against any
     * range whatsoever can come out at zero about once in 750. The assertion is
     * therefore that at least one of thirty rounds is a riposte -- 19970 of
     * 20000 were -- rather than that every round is.
     */
    public function testTheGlobalOptionsStillSelectThePvpPath(): void
    {
        global $options, $adjustment;

        $adjustment = 100;

        $ripostes = static function (string $type): int {
            global $options;

            $options = ['type' => $type];
            $count = 0;
            for ($i = 0; $i < 30; $i++) {
                $badguy = self::badguy();
                $badguy['creaturedefense'] = 10000;
                if (Battle::rollDamage($badguy)['creaturedmg'] < 0) {
                    $count++;
                }
            }

            return $count;
        };

        self::assertSame(0, $ripostes('forest'), 'divided down to 1, the defence cannot riposte at all');
        self::assertGreaterThan(0, $ripostes('pvp'), 'undivided, it ripostes essentially every round');
    }

    /**
     * A round that never happens leaves the globals as the last real round left
     * them, rather than clearing them.
     *
     * This is the half of the adapter contract that is easiest to break by
     * accident, and it was: the first version of this refactor published the
     * roll unconditionally, so a call against a downed creature reset $atk to
     * zero where it used to keep its value. battle.php:603 hands $atk to
     * reportPowerMove() on every round, so the difference reaches the player as
     * a power-move message that either does or does not appear.
     */
    public function testANonFightLeavesTheGlobalsWhereTheyWere(): void
    {
        global $atk, $creatureattack;

        $alive = self::badguy();
        Battle::rollDamage($alive);

        $atkAfterFight = $atk;
        $creatureAttackAfterFight = $creatureattack;

        self::assertNotNull($atkAfterFight, 'precondition: the first round set them');

        $dead = self::badguy();
        $dead['creaturehealth'] = 0;
        $dead['creatureattack'] = 999;
        Battle::rollDamage($dead);

        self::assertSame($atkAfterFight, $atk, 'the stale attack roll survives');
        self::assertSame($creatureAttackAfterFight, $creatureattack, 'and so does the creature attack');
    }

    /**
     * The companion adapter carries the same contract.
     */
    public function testANonFightLeavesTheGlobalsWhereTheyWereForCompanionsToo(): void
    {
        global $atk, $creatureattack;

        $companion = ['attack' => 14, 'defense' => 9, 'hitpoints' => 30];

        $alive = self::badguy();
        Battle::rollCompanionDamage($alive, $companion);

        $atkAfterFight = $atk;
        $creatureAttackAfterFight = $creatureattack;

        self::assertNotNull($atkAfterFight, 'precondition: the first round set them');

        $dead = self::badguy();
        $dead['creaturehealth'] = 0;
        $dead['creatureattack'] = 999;
        Battle::rollCompanionDamage($dead, $companion);

        self::assertSame($atkAfterFight, $atk);
        self::assertSame($creatureAttackAfterFight, $creatureattack);
    }

    public function testAMissingCreatureResistanceIsFilledIn(): void
    {
        $badguy = self::badguy();
        Battle::rollDamage($badguy);

        self::assertSame(0, $badguy['physicalresistance'], 'written back through the reference');
    }

    public function testTheCompanionAdapterKeepsItsReturnShape(): void
    {
        $badguy = self::badguy();
        $companion = ['attack' => 14, 'defense' => 9, 'hitpoints' => 30];
        $roll = Battle::rollCompanionDamage($badguy, $companion);

        self::assertSame(['creaturedmg', 'selfdmg'], array_keys($roll));
        self::assertTrue(
            $roll['creaturedmg'] != 0 || $roll['selfdmg'] != 0,
            'a companion round produces something too'
        );
    }

    public function testTheCompanionAdapterPublishesTheAttackRoll(): void
    {
        global $atk;

        $badguy = self::badguy();
        $companion = ['attack' => 14, 'defense' => 9, 'hitpoints' => 30];
        Battle::rollCompanionDamage($badguy, $companion);

        self::assertNotNull($atk);
        self::assertGreaterThanOrEqual(0, $atk);
        self::assertLessThanOrEqual(14 * 3, $atk, 'bounded by the tripled companion attack');
    }
}
