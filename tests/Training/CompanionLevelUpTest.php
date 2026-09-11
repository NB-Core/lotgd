<?php

declare(strict_types=1);

namespace Lotgd\Tests\Training;

use Lotgd\PlayerFunctions;
use PHPUnit\Framework\TestCase;

/**
 * Companions gain their share when the player beats a master.
 *
 * One level per page load, so the per-level figures are simply added -- nothing
 * is multiplied or recomputed from the player's level, which is what the
 * comment in train.php was getting at.
 *
 * Each stat grows only if the companion already has it. A companion without an
 * 'attack' key is one that does not fight, and inventing the key would give it
 * a weapon it was never meant to have.
 */
final class CompanionLevelUpTest extends TestCase
{
    /**
     * @return array<string, int|string>
     */
    private static function fighter(): array
    {
        return [
            'name' => 'Rex',
            'attack' => 10,
            'attackperlevel' => 2,
            'defense' => 8,
            'defenseperlevel' => 1,
            'maxhitpoints' => 40,
            'maxhitpointsperlevel' => 5,
            'hitpoints' => 12,
        ];
    }

    public function testEachStatGrowsByItsOwnPerLevelFigure(): void
    {
        $after = PlayerFunctions::levelUpCompanion(self::fighter());

        self::assertSame(12, $after['attack'], '10 + 2');
        self::assertSame(9, $after['defense'], '8 + 1');
        self::assertSame(45, $after['maxhitpoints'], '40 + 5');
    }

    /**
     * A wounded companion comes out of the level-up at full health.
     */
    public function testTheCompanionIsHealedToItsNewMaximum(): void
    {
        $after = PlayerFunctions::levelUpCompanion(self::fighter());

        self::assertSame(45, $after['hitpoints'], 'the new maximum, not the old 40 and not the wounded 12');
    }

    /**
     * A stat with no per-level figure stands still rather than vanishing or
     * turning into a warning.
     */
    public function testAStatWithoutAPerLevelFigureStandsStill(): void
    {
        $after = PlayerFunctions::levelUpCompanion([
            'attack' => 10,
            'defense' => 8,
            'defenseperlevel' => 3,
            'maxhitpoints' => 40,
        ]);

        self::assertSame(10, $after['attack'], 'no attackperlevel, so no gain');
        self::assertSame(11, $after['defense'], 'but defense has one');
        self::assertSame(40, $after['maxhitpoints']);
    }

    /**
     * A companion that does not fight does not acquire an attack.
     */
    public function testAStatTheCompanionDoesNotHaveIsNotInvented(): void
    {
        $after = PlayerFunctions::levelUpCompanion([
            'name' => 'Songbird',
            'maxhitpoints' => 20,
            'maxhitpointsperlevel' => 2,
            'attackperlevel' => 5,
        ]);

        self::assertArrayNotHasKey('attack', $after, 'a per-level figure alone does not create the stat');
        self::assertArrayNotHasKey('defense', $after);
        self::assertSame(22, $after['maxhitpoints']);
    }

    /**
     * Pinned as a bug rather than as intended behaviour.
     *
     * The healing step tests for 'attack' and then assigns from
     * 'maxhitpoints'. Almost certainly a slip, and it cuts both ways: a
     * non-fighting companion that has hitpoints is never healed, and a
     * fighting companion that has none gets a warning and a null.
     *
     * Reproduced rather than repaired here, because correcting it would change
     * behaviour and this extraction deliberately changes none. These two cases
     * are where it would be restated.
     */
    public function testANonFightingCompanionIsNotHealed(): void
    {
        $after = PlayerFunctions::levelUpCompanion([
            'name' => 'Songbird',
            'maxhitpoints' => 20,
            'maxhitpointsperlevel' => 2,
            'hitpoints' => 3,
        ]);

        self::assertSame(22, $after['maxhitpoints'], 'the maximum does grow');
        self::assertSame(3, $after['hitpoints'], 'but the healing never runs, because there is no attack key');
    }

    /**
     * The other half of the same slip.
     */
    public function testAFightingCompanionWithoutAMaximumGetsANullInstead(): void
    {
        $companion = ['name' => 'Wisp', 'attack' => 4, 'attackperlevel' => 1, 'hitpoints' => 7];

        $after = @PlayerFunctions::levelUpCompanion($companion);

        self::assertSame(5, $after['attack'], 'the attack still grows');
        self::assertNull($after['hitpoints'], 'and the hitpoints are cleared by the missing maximum');
    }

    /**
     * The companion is returned rather than modified in place, which is what
     * lets train.php build a fresh list instead of mutating the one it is
     * iterating.
     */
    public function testTheCompanionIsReturnedRatherThanModified(): void
    {
        $before = self::fighter();
        $untouched = $before;

        PlayerFunctions::levelUpCompanion($before);

        self::assertSame($untouched, $before);
    }

    /**
     * Two level-ups in a row add twice, because the figures are per level and
     * applied once each time.
     */
    public function testTwoLevelUpsAddTwice(): void
    {
        $once = PlayerFunctions::levelUpCompanion(self::fighter());
        $twice = PlayerFunctions::levelUpCompanion($once);

        self::assertSame(14, $twice['attack'], '10 + 2 + 2');
        self::assertSame(50, $twice['maxhitpoints'], '40 + 5 + 5');
    }
}
