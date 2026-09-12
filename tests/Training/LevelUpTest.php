<?php

declare(strict_types=1);

namespace Lotgd\Tests\Training;

use Lotgd\PlayerFunctions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Beating your master is the only way a character's level ever goes up, and
 * every gain it hands out is permanent. It had no tests, because all of it sat
 * inline in train.php between output lines.
 *
 * There is no undo. A level-up that awards the wrong figure is not a display
 * bug -- it is written into the account on the next save.
 */
final class LevelUpTest extends TestCase
{
    /**
     * @return array<string, int>
     */
    private static function character(): array
    {
        return [
            'level' => 5,
            'maxhitpoints' => 60,
            'soulpoints' => 20,
            'attack' => 12,
            'defense' => 11,
            'seenmaster' => 1,
            'gold' => 500,
            'experience' => 4000,
        ];
    }

    public function testEveryGainIsAwardedExactlyOnce(): void
    {
        $after = PlayerFunctions::levelUp(self::character());

        self::assertSame(6, $after['level'], 'one level');
        self::assertSame(70, $after['maxhitpoints'], 'ten hitpoints');
        self::assertSame(25, $after['soulpoints'], 'five soulpoints');
        self::assertSame(13, $after['attack'], 'one attack');
        self::assertSame(12, $after['defense'], 'one defense');
    }

    /**
     * A level-up touches five figures and the master flag. Anything else it
     * moved would be a gain nobody accounted for.
     */
    public function testNothingElseIsTouched(): void
    {
        $before = self::character();
        $after = PlayerFunctions::levelUp($before);

        self::assertSame($before['gold'], $after['gold']);
        self::assertSame($before['experience'], $after['experience']);
        self::assertSame(
            array_keys($before),
            array_keys($after),
            'and no key appears that was not there before'
        );
    }

    /**
     * With multimaster on, the flag is cleared so the next master can be
     * fought the same day; with it off, the flag stays and the day is over.
     */
    public function testTheMasterFlagFollowsTheMultimasterSetting(): void
    {
        $again = PlayerFunctions::levelUp(self::character(), true);
        self::assertSame(0, $again['seenmaster'], 'another master may be challenged today');

        $done = PlayerFunctions::levelUp(self::character(), false);
        self::assertSame(1, $done['seenmaster'], 'the flag stands, so the day is over');
    }

    /**
     * The gains are flat rather than scaled, so they are the same at every
     * level -- worth stating, because "ten hitpoints" reads like it might be
     * a rate.
     */
    #[DataProvider('levelProvider')]
    public function testTheGainsAreFlatAtEveryLevel(int $level): void
    {
        $character = self::character();
        $character['level'] = $level;

        $after = PlayerFunctions::levelUp($character);

        self::assertSame($level + 1, $after['level']);
        self::assertSame(70, $after['maxhitpoints'], 'still ten, whatever the level');
        self::assertSame(25, $after['soulpoints'], 'still five');
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function levelProvider(): array
    {
        return [
            'the first master' => [1],
            'mid-game' => [7],
            'the last master' => [14],
            'beyond the last' => [15],
        ];
    }

    /**
     * The character is returned rather than modified, so a caller that does not
     * assign the result gets no level-up at all -- and cannot get half of one.
     */
    public function testTheCharacterIsReturnedRatherThanModified(): void
    {
        $before = self::character();
        $untouched = $before;

        PlayerFunctions::levelUp($before);

        self::assertSame($untouched, $before, 'the array passed in is unchanged');
    }

    /**
     * Figures arriving as strings -- which is what a database row hands back --
     * come out as integers rather than being concatenated.
     */
    public function testStringFiguresAreTreatedAsNumbers(): void
    {
        $character = self::character();
        $character['level'] = '5';
        $character['maxhitpoints'] = '60';
        $character['attack'] = '12';

        $after = PlayerFunctions::levelUp($character);

        self::assertSame(6, $after['level']);
        self::assertSame(70, $after['maxhitpoints'], 'not the string "6010"');
        self::assertSame(13, $after['attack']);
    }
}
