<?php

declare(strict_types=1);

namespace Lotgd\Tests\Pvp;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database as CoreDatabase;
use Lotgd\Output;
use Lotgd\Pvp;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pvp::victory() moves gold and experience from one player's account to
 * another's, and until now nothing checked the arithmetic that decides how
 * much. The formulas below were read out of src/Lotgd/Pvp.php and then
 * confirmed against the running code, so each expected number here is the one
 * the game actually produces today -- these cases pin current behaviour, they
 * do not assert what the balance ought to be.
 *
 * The one case that is a rule rather than a formula is the cap: an attacker
 * cannot take more gold than the defender owns. Without it PvP mints money.
 *
 * The expectations are floats on purpose. victory() builds both awards with
 * round(), which returns a float in PHP, so a win leaves gold and experience
 * as floats in the session. That is what the code does today; asserting ints
 * here would mean writing a test that the game fails.
 */
final class VictoryTest extends TestCase
{
    private const ATTACKER = 1;
    private const DEFENDER = 2;

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $attacker
     */
    private function runVictory(
        int $defenderGold,
        array $badguy = [],
        array $settings = [],
        array $attacker = []
    ): void {
        global $session;

        new PHPMailer();

        Settings::setInstance(new DummySettings($settings + [
            'pvpattgain' => 10,
            'pvpdeflose' => 5,
            'pvphardlimit' => 0,
            'usedatacache' => 0,
        ]));

        $session = ['user' => $attacker + [
            'acctid' => self::ATTACKER,
            'name' => 'Attacker',
            'level' => 5,
            'sex' => 0,
            'gold' => 0,
            'experience' => 0,
            'weapon' => 'Sword',
            'hitpoints' => 20,
        ]];

        CoreDatabase::resetDoctrineConnection();
        // The gold the defender actually has, as victory() reads it back before
        // deciding how much can be taken.
        Database::$mockResults = [[['gold' => $defenderGold]]];

        Pvp::victory($badguy + [
            'acctid' => self::DEFENDER,
            'creaturename' => 'Defender',
            'creaturelevel' => 6,
            'creaturegold' => 300,
            'creatureexp' => 2000,
            'playerstarthp' => 40,
            'fightstartdate' => 1700000000,
        ], 'the field');
    }

    /**
     * The UPDATE that takes the loss from the defender.
     *
     * @return array{sql: string, params: array<string, mixed>, types: array<string, mixed>}
     */
    private static function defenderUpdate(): array
    {
        $statements = array_values(array_filter(
            CoreDatabase::getDoctrineConnection()->executeStatements,
            static fn (array $statement): bool => str_contains($statement['sql'], 'UPDATE ' . CoreDatabase::prefix('accounts'))
        ));

        self::assertCount(1, $statements, 'the defender should be updated exactly once');

        return $statements[0];
    }

    /**
     * An attacker must not be able to take gold the defender does not have.
     *
     * victory() re-reads the defender's balance and caps the prize at it. The
     * cap is the only thing standing between PvP and a money press: creaturegold
     * comes from the fight setup, not from the defender's current purse.
     */
    public function testThePrizeIsCappedAtTheGoldTheDefenderActuallyHas(): void
    {
        global $session;

        // Defender carries 50; the fight offered 300.
        $this->runVictory(defenderGold: 50);

        // round(10 * 6 * log(50)) -- computed from 50, not from 300.
        self::assertSame(235.0, $session['user']['gold']);
        self::assertSame(50, self::defenderUpdate()['params']['creaturegold_for_subtract']);
    }

    public function testTheFullPrizeIsPaidWhenTheDefenderCanCoverIt(): void
    {
        global $session;

        $this->runVictory(defenderGold: 500);

        // round(10 * 6 * log(300))
        self::assertSame(342.0, $session['user']['gold']);
        self::assertSame(300, self::defenderUpdate()['params']['creaturegold_for_subtract']);
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('experienceProvider')]
    public function testExperienceAwarded(int $attackerLevel, int $defenderLevel, array $settings, float $expected): void
    {
        global $session;

        $this->runVictory(
            defenderGold: 500,
            badguy: ['creaturelevel' => $defenderLevel],
            settings: $settings,
            attacker: ['level' => $attackerLevel]
        );

        self::assertSame($expected, $session['user']['experience']);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: array<string, mixed>, 3: float}>
     */
    public static function experienceProvider(): array
    {
        // base = round(pvpattgain% of creatureexp 2000) = 200, then scaled by
        // 1 + 0.1 * (defender level - attacker level).
        return [
            'even fight pays the base award' => [6, 6, [], 200.0],
            'punching up pays a bonus' => [5, 6, [], 220.0],
            'punching further up pays more' => [3, 6, [], 260.0],
            'punching down is penalised' => [8, 6, [], 160.0],
            'the hard limit caps the award' => [6, 6, ['pvphardlimit' => 1, 'pvphardlimitamount' => 50], 50.0],
            'the cap is ignored while the limit is off' => [6, 6, ['pvphardlimitamount' => 50], 200.0],
            'a higher attack gain pays more' => [6, 6, ['pvpattgain' => 25], 500.0],
        ];
    }

    /**
     * At the level cap there is nothing left to gain, and victory() says so
     * rather than silently paying nothing: three separate branches zero the
     * gold and the experience.
     */
    public function testTheLevelCapEarnsNeitherGoldNorExperience(): void
    {
        global $session;

        $this->runVictory(defenderGold: 500, attacker: ['level' => 15]);

        // Gold is zeroed by a literal assignment and stays an int; the
        // experience still runs through round() on its way to zero and comes
        // out a float. Which is the clearest evidence of where the floats in
        // the other cases come from.
        self::assertSame(0, $session['user']['gold']);
        self::assertSame(0.0, $session['user']['experience']);
        self::assertStringContainsString(
            'sufficient accolade',
            Output::getInstance()->getRawOutput(),
            'the player is told why there is no reward'
        );
    }

    /**
     * The defender is left dead, poorer and less experienced -- and every value
     * in that statement is bound, not interpolated.
     */
    public function testTheDefenderLosesGoldAndExperienceAndIsMarkedDead(): void
    {
        $this->runVictory(defenderGold: 500);

        $update = self::defenderUpdate();

        self::assertStringContainsString('alive = 0', $update['sql']);
        self::assertSame(self::DEFENDER, $update['params']['acctid']);
        self::assertSame(ParameterType::INTEGER, $update['types']['acctid']);

        // pvpdeflose is 5%, of creatureexp 2000.
        self::assertSame(100, $update['params']['lostexp_for_subtract']);

        foreach ($update['types'] as $name => $type) {
            self::assertSame(ParameterType::INTEGER, $type, "{$name} must be bound as an integer");
        }
        self::assertStringNotContainsString('300', $update['sql'], 'amounts belong in the parameters');
    }

    /**
     * Experience cannot go negative: the statement floors it at zero rather
     * than subtracting past it.
     */
    public function testTheDefendersExperienceIsFlooredAtZero(): void
    {
        $this->runVictory(defenderGold: 500);

        self::assertStringContainsString(
            'experience = IF(experience >= :lostexp_for_check, experience - :lostexp_for_subtract, 0)',
            self::defenderUpdate()['sql']
        );
    }
}
