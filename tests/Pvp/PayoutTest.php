<?php

declare(strict_types=1);

namespace Lotgd\Tests\Pvp;

use Lotgd\Pvp\Payout;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * A serious duel pays and costs what PvP does (Pvp::victory() and
 * Pvp::defeat()), worked out without a session.
 */
final class PayoutTest extends TestCase
{
    protected function setUp(): void
    {
        Settings::setInstance(new DummySettings([
            'pvpattgain' => 10, 'pvpdeflose' => 5, 'pvpdefgain' => 10, 'pvpattlose' => 15, 'pvphardlimit' => 0,
        ]));
    }

    public function testAnAttackerWhoWinsIsPaidLikeOneWhoSlewTheirTarget(): void
    {
        $payout = Payout::attackerWins(
            ['level' => 5, 'gold' => 100, 'experience' => 1000],
            ['level' => 6, 'gold' => 200, 'experience' => 2000]
        );

        self::assertSame([
            'winnerGold' => (int) round(10 * 6 * log(200)),
            // 10% of 2000, and 10% more for the level above.
            'winnerExp' => 200 + 20,
            'loserGold' => 200,
            'loserExp' => 100,
        ], $payout);
    }

    public function testADefenderWhoWinsIsPaidLikeOneWhoSurvived(): void
    {
        $payout = Payout::defenderWins(
            ['level' => 5, 'gold' => 100, 'experience' => 1000],
            ['level' => 6, 'gold' => 200, 'experience' => 2000]
        );

        self::assertSame([
            'winnerGold' => (int) round(10 * 5 * log(100)),
            'winnerExp' => 100,
            'loserGold' => 100,
            'loserExp' => 150,
        ], $payout);
    }

    public function testAtTheTopLevelOnlyTheVictoryCounts(): void
    {
        $won = Payout::attackerWins(['level' => 15, 'gold' => 1, 'experience' => 1], ['level' => 15, 'gold' => 500, 'experience' => 9000]);
        self::assertSame(0, $won['winnerGold']);
        self::assertSame(0, $won['winnerExp']);

        $held = Payout::defenderWins(['level' => 15, 'gold' => 500, 'experience' => 9000], ['level' => 15, 'gold' => 1, 'experience' => 1]);
        self::assertSame(0, $held['winnerGold'], 'What Pvp::defeat() means to do.');
        self::assertSame(0, $held['winnerExp']);
    }

    public function testTheHardLimitCapsTheExperience(): void
    {
        Settings::setInstance(new DummySettings(['pvpattgain' => 10, 'pvpdeflose' => 5, 'pvphardlimit' => 1, 'pvphardlimitamount' => 50]));

        $payout = Payout::attackerWins(['level' => 5, 'gold' => 0, 'experience' => 0], ['level' => 5, 'gold' => 0, 'experience' => 5000]);

        self::assertSame(50, $payout['winnerExp']);
        self::assertSame(0, $payout['winnerGold'], 'log(1) of no gold.');
    }
}
