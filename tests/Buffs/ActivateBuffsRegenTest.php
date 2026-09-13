<?php

declare(strict_types=1);

namespace Lotgd\Tests\Buffs;

use Lotgd\Buffs;
use Lotgd\Output;
use Lotgd\Template;
use PHPUnit\Framework\TestCase;

/**
 * The regeneration branch of Buffs::activateBuffs().
 *
 * The last uncovered branch on the audit's backlog, and it needed no seam to
 * reach: unlike the minion branch below it -- which rolls random_int() -- the
 * regen arithmetic is a pure function of the buff, the player's hitpoints and
 * the companion list. Everything it reads is a global the fixture already
 * sets, so it can simply be run. That is worth stating, because the previous
 * items on this backlog (Battle::rollDamage(), PlayerFunctions::levelUp())
 * each had to be given a seam before a test could see them, and the reflex by
 * now is to assume another one is needed.
 *
 * What is being pinned is the clamp on both sides. A heal must stop at the
 * player's maximum or a regen buff is an unbounded hitpoint fountain, and the
 * separate `$hpdiff < 0` guard is what keeps a player who is *over* maximum --
 * which happens, potions and level-ups both do it -- from being pulled back
 * down by a buff that is supposed to help. Neither clamp had a test, and
 * neither announces itself when it breaks: the fight simply gets easier or
 * harder.
 *
 * @see ActivateBuffsTest for the modifier arithmetic of the same method.
 */
final class ActivateBuffsRegenTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $badguy, $count, $companions;

        $session = [
            'user' => [
                'maxhitpoints' => 100,
                'hitpoints' => 60,
                'name' => 'Hero',
                'weapon' => 'Sword',
                'armor' => 'Mail',
                'sex' => 0,
            ],
            'bufflist' => [],
        ];
        // istarget is the gate: regen only fires for the enemy the player is
        // actually facing, so a fight against several creatures does not heal
        // once per creature.
        $badguy = [
            'istarget' => true,
            'dead' => false,
            'creaturename' => 'Goblin',
            'creatureweapon' => 'Club',
        ];
        $count = 0;
        $companions = [];
        Template::getInstance()->setTemplate([]);
    }

    protected function tearDown(): void
    {
        global $session, $badguy, $count, $companions;

        unset($session, $badguy, $count, $companions);
        Template::getInstance()->setTemplate([]);
    }

    /**
     * @param array<string, mixed> $buff
     */
    private function activate(array $buff, string $tag = 'roundstart'): void
    {
        global $session;

        $session['bufflist']['regeneration'] = $buff + ['schema' => false];

        Buffs::activateBuffs($tag);
    }

    private function hitpoints(): int
    {
        global $session;

        return $session['user']['hitpoints'];
    }

    public function testARegenBuffHealsThePlayer(): void
    {
        $this->activate(['regen' => 12]);

        self::assertSame(72, $this->hitpoints());
    }

    /**
     * The heal stops at maximum rather than running past it.
     */
    public function testTheHealStopsAtMaximumHitpoints(): void
    {
        global $session;

        $session['user']['hitpoints'] = 95;

        $this->activate(['regen' => 12]);

        self::assertSame(100, $this->hitpoints(), '95 + 12 is clamped to the maximum, not carried to 107');
    }

    /**
     * A player already over maximum is not dragged back down to it.
     *
     * The `$hpdiff < 0` guard, which is easy to read as defensive noise. Drop
     * it and the difference goes negative, the clamp adopts it, and a buff
     * that is meant to heal removes hitpoints instead -- silently, because the
     * message that follows prints the absolute value.
     */
    public function testAPlayerAboveMaximumIsNotHarmedByAHeal(): void
    {
        global $session;

        $session['user']['hitpoints'] = 120;

        $this->activate(['regen' => 12]);

        self::assertSame(120, $this->hitpoints());
    }

    /**
     * A negative regen is damage over time, and is deliberately not clamped.
     *
     * The clamp above only caps a heal. Poison-shaped buffs rely on that:
     * battle.php checks the player's hitpoints immediately after this call and
     * ends the fight if they have reached zero, so the method is allowed to
     * take a player below zero and does not have to know about dying.
     */
    public function testANegativeRegenDamagesThePlayer(): void
    {
        $this->activate(['regen' => -25]);

        self::assertSame(35, $this->hitpoints());
    }

    public function testANegativeRegenMayTakeThePlayerBelowZero(): void
    {
        global $session;

        $session['user']['hitpoints'] = 10;

        $this->activate(['regen' => -25]);

        self::assertSame(-15, $this->hitpoints(), 'battle.php ends the fight on this, activateBuffs() does not clamp it');
    }

    public function testTheRegenOnlyRunsAtRoundStart(): void
    {
        $this->activate(['regen' => 12], 'offense');

        self::assertSame(60, $this->hitpoints(), 'a regen buff does not heal again on every attack');
    }

    public function testTheRegenOnlyRunsAgainstTheTargetedEnemy(): void
    {
        global $badguy;

        $badguy['istarget'] = false;

        $this->activate(['regen' => 12]);

        self::assertSame(60, $this->hitpoints());
    }

    /**
     * The buff's own message is what the player reads.
     *
     * This one was not passing before: the branch tested `effectgmsg` and then
     * printed `effectmsg`, so a buff carrying the correctly spelled key fell
     * through to the developer placeholder. modules/specialtymysticpower.php
     * sets `effectmsg` and nothing in the tree sets `effectgmsg`, which means
     * every mystic-power player regenerating in combat was shown "Tons of
     * damage, hosé" instead of "You regenerate for N health."
     */
    public function testTheBuffsOwnMessageIsShownWithTheAmountHealed(): void
    {
        $this->activate(['regen' => 12, 'effectmsg' => 'You regenerate for {damage} health.']);

        self::assertStringContainsString('You regenerate for 12 health.', Output::getInstance()->getRawOutput());
    }

    /**
     * And the amount it reports is the amount actually applied.
     *
     * Not the buff's nominal strength: the clamp above may have reduced it,
     * and a message that ignored the clamp would tell the player they healed
     * 12 when they healed 5.
     */
    public function testTheReportedAmountIsWhatWasActuallyHealed(): void
    {
        global $session;

        $session['user']['hitpoints'] = 95;

        $this->activate(['regen' => 12, 'effectmsg' => 'You regenerate for {damage} health.']);

        self::assertStringContainsString('You regenerate for 5 health.', Output::getInstance()->getRawOutput());
    }

    /**
     * Damage is reported as a positive number.
     */
    public function testANegativeRegenReportsItsMagnitude(): void
    {
        $this->activate(['regen' => -25, 'effectmsg' => 'The poison burns you for {damage}.']);

        self::assertStringContainsString('The poison burns you for 25.', Output::getInstance()->getRawOutput());
    }

    /**
     * Nothing to heal is its own message, not a heal of zero.
     */
    public function testTheNoDamageMessageIsShownWhenThereIsNothingToHeal(): void
    {
        global $session;

        $session['user']['hitpoints'] = 100;

        $this->activate(['regen' => 12, 'effectmsg' => 'You regenerate for {damage} health.', 'effectnodmgmsg' => 'You have no wounds to regenerate.']);

        $output = Output::getInstance()->getRawOutput();
        self::assertStringContainsString('You have no wounds to regenerate.', $output);
        self::assertStringNotContainsString('You regenerate for', $output);
        self::assertSame(100, $this->hitpoints());
    }

    /**
     * A buff with no message of its own still says something.
     *
     * The placeholders are poor text, but they are the documented fallback and
     * a buff author relies on the branch existing. Pinned as behaviour rather
     * than endorsed: if they are ever replaced with real wording, this is the
     * test that has to be updated, which is the point.
     */
    public function testABuffWithoutAMessageFallsBackToThePlaceholder(): void
    {
        $this->activate(['regen' => 12]);

        self::assertStringContainsString('Tons of damage', Output::getInstance()->getRawOutput());
    }

    public function testABuffWithoutAMessageFallsBackToThePlaceholderWhenNothingIsHealed(): void
    {
        global $session;

        $session['user']['hitpoints'] = 100;

        $this->activate(['regen' => 12]);

        self::assertStringContainsString('No damage', Output::getInstance()->getRawOutput());
    }

    /**
     * An aura extends the regeneration to the player's companions, at a third
     * of its strength.
     *
     * Nothing shipped with this game sets both `regen` and `aura`, so this
     * branch is reached only by third-party modules -- which is precisely why
     * it needs a test rather than an assumption. The arithmetic is the
     * contract those modules are written against.
     */
    public function testAnAuraHealsCompanionsForAThirdOfTheRegen(): void
    {
        global $companions;

        $companions = [
            'wolf' => ['name' => 'Wolf', 'hitpoints' => 50, 'maxhitpoints' => 100, 'cannotdie' => false],
        ];

        $this->activate(['regen' => 12, 'aura' => true, 'auramsg' => '{companion} recovers {damage}.']);

        self::assertSame(54, $companions['wolf']['hitpoints'], '12 / 3 = 4');
        self::assertStringContainsString('Wolf recovers 4.', Output::getInstance()->getRawOutput());
    }

    /**
     * Each companion's heal is clamped to its own maximum.
     */
    public function testTheCompanionHealIsClampedToItsOwnMaximum(): void
    {
        global $companions;

        $companions = [
            'wolf' => ['name' => 'Wolf', 'hitpoints' => 98, 'maxhitpoints' => 100, 'cannotdie' => false],
            'hawk' => ['name' => 'Hawk', 'hitpoints' => 10, 'maxhitpoints' => 100, 'cannotdie' => false],
        ];

        $this->activate(['regen' => 12, 'aura' => true, 'auramsg' => '{companion} recovers {damage}.']);

        self::assertSame(100, $companions['wolf']['hitpoints'], 'clamped to its own maximum, not the player\'s');
        self::assertSame(14, $companions['hawk']['hitpoints'], 'and the clamp on one does not reduce the other');
    }

    public function testACompanionAtFullHealthIsLeftAlone(): void
    {
        global $companions;

        $companions = [
            'wolf' => ['name' => 'Wolf', 'hitpoints' => 100, 'maxhitpoints' => 100, 'cannotdie' => false],
        ];

        $this->activate(['regen' => 12, 'aura' => true, 'auramsg' => '{companion} recovers {damage}.']);

        self::assertSame(100, $companions['wolf']['hitpoints']);
        self::assertStringNotContainsString('Wolf recovers', Output::getInstance()->getRawOutput());
    }

    public function testWithoutAnAuraTheCompanionsAreNotHealed(): void
    {
        global $companions;

        $companions = [
            'wolf' => ['name' => 'Wolf', 'hitpoints' => 50, 'maxhitpoints' => 100, 'cannotdie' => false],
        ];

        $this->activate(['regen' => 12]);

        self::assertSame(72, $this->hitpoints(), 'positive control: the player is healed');
        self::assertSame(50, $companions['wolf']['hitpoints']);
    }

    /**
     * An aura too weak to round up to a point heals nobody.
     *
     * round(2/3) is 1 and round(1/3) is 0, and at zero the whole companion
     * loop is skipped rather than run with no effect -- which matters because
     * the loop is where the aura message is printed. A regen of 1 must not
     * announce a heal of 0 to every companion in the party.
     */
    public function testAnAuraTooWeakToRoundToAPointHealsNobody(): void
    {
        global $companions;

        $companions = [
            'wolf' => ['name' => 'Wolf', 'hitpoints' => 50, 'maxhitpoints' => 100, 'cannotdie' => false],
        ];

        $this->activate(['regen' => 1, 'aura' => true, 'auramsg' => '{companion} recovers {damage}.']);

        self::assertSame(61, $this->hitpoints(), 'the player still gets the full point');
        self::assertSame(50, $companions['wolf']['hitpoints']);
        self::assertStringNotContainsString('Wolf recovers', Output::getInstance()->getRawOutput());
    }

    /**
     * A companion that is already down costs nothing to walk past.
     *
     * battle.php leaves a companion at zero hitpoints in the party rather than
     * removing it, so this is the ordinary state of a party mid-fight -- and
     * the allowlist condition reads `cannotdie` only once hitpoints have
     * reached zero, which is exactly then. Companions built without that key
     * (it is optional everywhere else) therefore produced an "Undefined array
     * key" on every single round for any party carrying an aura regen buff.
     *
     * Asserted with an error handler rather than left to PHPUnit's own notice
     * reporting, which does not fail a run: without the handler this test
     * passes whether the warning is raised or not, which is no test at all.
     */
    public function testADownedCompanionWithoutTheOptionalKeyRaisesNoWarning(): void
    {
        global $companions;

        $companions = [
            'wolf' => ['name' => 'Wolf', 'hitpoints' => 0, 'maxhitpoints' => 100],
        ];

        $raised = [];
        set_error_handler(static function (int $number, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });

        try {
            $this->activate(['regen' => 12, 'aura' => true, 'auramsg' => '{companion} recovers {damage}.']);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $raised, implode(' | ', $raised));
        self::assertSame(0, $companions['wolf']['hitpoints'], 'and it stays down: only cannotdie companions are revived');
    }

    /**
     * A negative aura damages companions, and the game keeps them.
     *
     * Documenting what the code does, not what it reads as if it does. Below
     * this point the method has a block that prints a companion's dying text
     * and drops it from the party -- and that block cannot run. It tests
     * `$companion['hitpoints']`, which is the by-value copy the foreach made
     * *before* the damage was written to `$companions[$name]`, so it always
     * sees the pre-damage value; and its one effect is to assign to
     * `$newcompanions`, which inside this method is an undeclared local that
     * nothing ever reads. battle.php has a global of that name, which is
     * presumably what the author meant.
     *
     * So the observable behaviour is: the companion takes the damage, keeps
     * whatever hitpoints that leaves it, and stays in the party. This test
     * says so. Left unfixed deliberately -- making the branch live would
     * change what third-party aura modules do, and that is a decision for the
     * project, not a side effect of writing a test. Reported alongside.
     */
    public function testANegativeAuraDamagesCompanionsWithoutRemovingThem(): void
    {
        global $companions;

        $companions = [
            'wolf' => ['name' => 'Wolf', 'hitpoints' => 3, 'maxhitpoints' => 100, 'cannotdie' => false, 'dyingtext' => 'The Wolf falls.'],
        ];

        $this->activate(['regen' => -12, 'aura' => true, 'auramsg' => '{companion} suffers {damage}.']);

        self::assertArrayHasKey('wolf', $companions, 'the companion stays in the party');
        self::assertSame(-1, $companions['wolf']['hitpoints'], '3 - 4, taken below zero and left there');
        self::assertStringNotContainsString('The Wolf falls.', Output::getInstance()->getRawOutput());
    }
}
