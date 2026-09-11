<?php

declare(strict_types=1);

namespace Lotgd\Tests\Newday;

use Lotgd\Newday;
use Lotgd\Output;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Newday::dragonPointRecalc() spends dragon points on permanent stats, from
 * numbers the player posts. Nothing about that is reversible and nothing about
 * it was tested.
 *
 * Two conditions stand between the form and the grant:
 *
 *     if ($pdktotal == $dkills - $dp && !$pdkneg) {
 *
 * The first is bookkeeping -- spend exactly what you have. The second is the
 * one that matters: without it, posting hp=10 together with at=-9 sums to 1
 * while asking for ten points' worth of hitpoints. A sum check alone cannot
 * tell the difference, which is why both halves need a case of their own.
 */
final class DragonPointRecalcTest extends TestCase
{
    /**
     * The labels newday.php passes in.
     *
     * The entries carrying a comma are section titles rather than spendable
     * types, and dragonPointRecalc() skips them. They are kept here because
     * their presence is part of what the method has to cope with.
     *
     * @var array<int|string, string>
     */
    private const LABELS = [
        'General Stuff,title',
        'hp' => 'Max Hitpoints + 5',
        'ff' => 'Forest Fights + 1',
        'Attributes,title',
        'str' => 'Strength +1',
        'dex' => 'Dexterity +1',
        'con' => 'Constitution +1',
        'int' => 'Intelligence +1',
        'wis' => 'Wisdom +1',
        'at' => 'Attack + 1',
        'de' => 'Defense + 1',
    ];

    private const BASE_STATS = [
        'maxhitpoints' => 100,
        'attack' => 10,
        'defense' => 10,
        'strength' => 20,
        'dexterity' => 21,
        'intelligence' => 22,
        'constitution' => 23,
        'wisdom' => 24,
    ];

    protected function setUp(): void
    {
        global $session;

        $_POST = [];
        $session = ['user' => self::BASE_STATS + ['dragonpoints' => []]];
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    /**
     * Post a spend and run the recalculation.
     *
     * Keyed by array-key rather than string: the section-title entries in the
     * label list carry integer keys, and a field naming one is posted as such.
     *
     * @param array<array-key, string> $spend
     */
    private function spend(array $spend, int $dkills, int &$dp): void
    {
        $_POST = $spend;
        Newday::dragonPointRecalc(self::LABELS, $dkills, $dp);
    }

    /**
     * @return array<string, int|float>
     */
    private static function stats(): array
    {
        global $session;

        return array_intersect_key($session['user'], self::BASE_STATS);
    }

    public function testASpendThatAddsUpGrantsTheStats(): void
    {
        global $session;

        $dp = 0;
        $this->spend(['hp' => '2', 'str' => '1', 'wis' => '1'], dkills: 4, dp: $dp);

        self::assertSame(4, $dp, 'the points are now spent');
        self::assertSame(110, $session['user']['maxhitpoints'], 'five hitpoints per point');
        self::assertSame(21, $session['user']['strength']);
        self::assertSame(25, $session['user']['wisdom']);
        self::assertSame(10, $session['user']['attack'], 'untouched types do not move');
    }

    /**
     * The spend is recorded per point, not per type, because the list is what
     * a later newday reads back to know what was bought.
     */
    public function testEachPointIsRecordedSeparately(): void
    {
        global $session;

        $dp = 0;
        $this->spend(['hp' => '2', 'ff' => '1'], dkills: 3, dp: $dp);

        self::assertSame(['hp', 'hp', 'ff'], $session['user']['dragonpoints']);
    }

    /**
     * Forest fights are spendable and countable but grant no stat here -- the
     * record in dragonpoints is the whole effect.
     */
    public function testForestFightsCountWithoutChangingAStat(): void
    {
        $dp = 0;
        $this->spend(['ff' => '2'], dkills: 2, dp: $dp);

        self::assertSame(2, $dp);
        self::assertSame(self::BASE_STATS, self::stats());
    }

    /**
     * A rejected spend must change nothing at all.
     *
     * Asserting only on the error message would let a partial grant through,
     * which is the failure that would actually matter.
     *
     * @param array<string, string> $spend
     */
    #[DataProvider('rejectedSpendProvider')]
    public function testARejectedSpendGrantsNothing(array $spend, int $dkills): void
    {
        global $session;

        $dp = 0;
        $this->spend($spend, $dkills, $dp);

        self::assertSame(0, $dp, 'no point may be marked spent');
        self::assertSame(self::BASE_STATS, self::stats(), 'no stat may move');
        self::assertSame([], $session['user']['dragonpoints'], 'nothing may be recorded');
        self::assertStringContainsString(
            'spend the correct total amount',
            Output::getInstance()->getRawOutput(),
            'and the player is told'
        );
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: int}>
     */
    public static function rejectedSpendProvider(): array
    {
        return [
            'spending more than is available' => [['hp' => '5'], 2],
            'spending less than is available' => [['hp' => '1'], 4],
            'spending nothing at all' => [[], 2],

            // The exploit the negative check exists for: these sum to exactly
            // the points available, so the total check passes on its own.
            'a negative offsetting a larger positive' => [['hp' => '10', 'at' => '-9'], 1],
            'a negative offset across three types' => [['hp' => '8', 'str' => '2', 'de' => '-8'], 2],

            // A negative on its own does not add up either, but it must be
            // refused for being negative rather than by luck of the sum.
            'a bare negative' => [['hp' => '-2'], -2],
        ];
    }

    /**
     * The section titles in the label list are not spendable types, and a post
     * naming one must not become a point.
     *
     * The field to post is the numeric 0, not the label text. The title entries
     * are written into the array without an explicit key, so they get the next
     * integer -- and dragonPointRecalc() reads the POST by key, via
     * Http::post($type). Posting 'General Stuff,title' would name a field the
     * method never looks at, which would leave the assertions below passing for
     * the wrong reason.
     */
    public function testPostingAgainstASectionTitleIsIgnored(): void
    {
        global $session;

        $dp = 0;
        $this->spend(['hp' => '2', 0 => '5'], dkills: 2, dp: $dp);

        self::assertSame(2, $dp, 'only the two hitpoint points count');
        self::assertSame(110, $session['user']['maxhitpoints']);
        self::assertSame(['hp', 'hp'], $session['user']['dragonpoints']);
    }

    /**
     * Points already spent are subtracted before the total is checked, so a
     * player returning with some of their dragon kills already committed can
     * only spend the remainder.
     */
    public function testOnlyTheUnspentRemainderMayBeSpent(): void
    {
        global $session;

        $session['user']['dragonpoints'] = ['hp', 'hp'];
        $dp = 2;
        $this->spend(['str' => '1'], dkills: 3, dp: $dp);

        self::assertSame(3, $dp);
        self::assertSame(21, $session['user']['strength']);
    }
}
