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
     * A companion is healed if it has a maximum, whether or not it fights.
     *
     * The healing step used to test 'attack' and then assign from
     * 'maxhitpoints', so a non-fighting companion carrying hitpoints was never
     * healed. That was invisible while train.php discarded the result; it
     * became real when the write-back landed, so it is corrected rather than
     * pinned.
     */
    public function testANonFightingCompanionIsHealedToo(): void
    {
        $after = PlayerFunctions::levelUpCompanion([
            'name' => 'Songbird',
            'maxhitpoints' => 20,
            'maxhitpointsperlevel' => 2,
            'hitpoints' => 3,
        ]);

        self::assertSame(22, $after['maxhitpoints'], 'the maximum grows');
        self::assertSame(22, $after['hitpoints'], 'and the healing follows it, with no attack key in sight');
    }

    /**
     * A fighting companion with no maximum is left alone rather than having its
     * hitpoints cleared.
     *
     * The other half of the same slip: the old condition ran the healing for
     * anything that could fight, and then read a key that was not there --
     * an undefined-key warning, and hitpoints set to null. The warning is
     * asserted absent rather than merely unobserved, with a scoped handler, so
     * that a future regression reintroducing it fails here.
     */
    public function testAFightingCompanionWithoutAMaximumIsLeftAlone(): void
    {
        $companion = ['name' => 'Wisp', 'attack' => 4, 'attackperlevel' => 1, 'hitpoints' => 7];

        /** @var list<string> $raised */
        $raised = [];
        set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        }, E_WARNING);

        try {
            $after = PlayerFunctions::levelUpCompanion($companion);
        } finally {
            restore_error_handler();
        }

        self::assertSame(5, $after['attack'], 'the attack still grows');
        self::assertSame(7, $after['hitpoints'], 'and the hitpoints are untouched rather than cleared');
        self::assertSame([], $raised, 'with no warning raised at all');
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
     * The level-up is kept rather than computed and dropped.
     *
     * `$newcompanions` was built and never read -- not assigned back, not
     * serialised into the session -- so this block, and the
     * `companionslevelup` setting guarding it, did nothing at all for as long
     * as they have existed. Reported by Copilot on #1529 and confirmed against
     * `origin/master` before changing anything.
     *
     * Checked against the source rather than by executing the page, because
     * train.php is a top-level script needing a session, a database and a
     * rendered header before it reaches this block. The arithmetic above is
     * covered by executing cases; this one guards the wiring, which is the part
     * that was broken.
     */
    public function testTrainPhpKeepsTheLevelledCompanions(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/train.php');

        self::assertStringContainsString(
            '$companions = $newcompanions;',
            $source,
            'the levelled list replaces the old one'
        );
        self::assertStringContainsString(
            "\$session['user']['companions'] = serialize(\$companions);",
            $source,
            'and is persisted the way mercenarycamp.php and healer.php persist it'
        );
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
