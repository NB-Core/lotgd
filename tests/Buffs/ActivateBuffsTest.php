<?php

declare(strict_types=1);

namespace Lotgd\Tests\Buffs;

use Lotgd\Buffs;
use Lotgd\Output;
use Lotgd\Template;
use PHPUnit\Framework\TestCase;

/**
 * Buffs::activateBuffs() reduces the player's active buffs to the set of
 * modifiers a combat round is fought with. Its only existing test was a smoke
 * test that checked nothing threw.
 *
 * The property worth pinning is that modifiers *multiply*. Two buffs that each
 * halve incoming damage leave it at a quarter, not a half, and that is where
 * stacking exploits live -- a change from *= to = would look harmless and would
 * quietly rebalance every fight in the game.
 */
final class ActivateBuffsTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $badguy, $count;

        // A round message goes through Substitute, which reads the fighters'
        // names and gear off both sides. A thinner fixture still passes but
        // adds undefined-key warnings to every run.
        $session = [
            'user' => [
                'maxhitpoints' => 100,
                'hitpoints' => 100,
                'name' => 'Hero',
                'weapon' => 'Sword',
                'armor' => 'Mail',
                'sex' => 0,
            ],
            'bufflist' => [],
        ];
        $badguy = [
            'istarget' => false,
            'creaturename' => 'Goblin',
            'creatureweapon' => 'Club',
        ];
        $count = 0;
        Template::getInstance()->setTemplate([]);
    }

    protected function tearDown(): void
    {
        global $session, $badguy, $count;

        unset($session, $badguy, $count);
        Template::getInstance()->setTemplate([]);
    }

    /**
     * @param array<string, array<string, mixed>> $buffs
     * @return array<string, mixed>
     */
    private function activate(array $buffs, string $tag = 'offense'): array
    {
        global $session;

        foreach ($buffs as $name => $buff) {
            $session['bufflist'][$name] = $buff + ['schema' => false];
        }

        return Buffs::activateBuffs($tag);
    }

    public function testTwoBuffsOfTheSameKindMultiply(): void
    {
        $result = $this->activate([
            'blessing' => ['atkmod' => 1.5],
            'fervour' => ['atkmod' => 2.0],
        ]);

        self::assertSame(3.0, $result['atkmod'], '1.5 and 2.0 stack to 3.0, they do not replace');
    }

    public function testEachModifierAccumulatesIndependently(): void
    {
        $result = $this->activate([
            'ward' => ['defmod' => 2.0, 'badguyatkmod' => 0.5],
            'curse' => ['badguydmgmod' => 0.25, 'dmgmod' => 3.0],
        ]);

        self::assertSame(2.0, $result['defmod']);
        self::assertSame(0.5, $result['badguyatkmod']);
        self::assertSame(0.25, $result['badguydmgmod']);
        self::assertSame(3.0, $result['dmgmod']);
        self::assertSame(1, $result['atkmod'], 'an untouched modifier stays neutral, and stays an int until something multiplies it');
    }

    /**
     * An aura extends the player's attack, defence and damage modifiers to
     * their companions; a buff without one leaves the companions at 1.
     */
    public function testAnAuraExtendsTheModifierToCompanions(): void
    {
        $withAura = $this->activate([
            'banner' => ['atkmod' => 2.0, 'defmod' => 3.0, 'dmgmod' => 4.0, 'aura' => true],
        ]);

        self::assertSame(2.0, $withAura['compatkmod']);
        self::assertSame(3.0, $withAura['compdefmod']);
        self::assertSame(4.0, $withAura['compdmgmod']);
    }

    public function testWithoutAnAuraTheCompanionsAreUnaffected(): void
    {
        $result = $this->activate([
            'selfish' => ['atkmod' => 2.0, 'defmod' => 3.0, 'dmgmod' => 4.0],
        ]);

        self::assertSame(2.0, $result['atkmod'], 'positive control: the player does get it');
        self::assertSame(1, $result['compatkmod']);
        self::assertSame(1, $result['compdefmod']);
        self::assertSame(1, $result['compdmgmod']);
    }

    public function testASuspendedBuffContributesNothing(): void
    {
        $result = $this->activate([
            'active' => ['atkmod' => 2.0],
            'paused' => ['atkmod' => 5.0, 'suspended' => true],
        ]);

        self::assertSame(2.0, $result['atkmod'], 'only the active buff counts');
    }

    public function testInvulnerabilityLatchesOn(): void
    {
        $result = $this->activate([
            'mortal' => ['atkmod' => 2.0],
            'shielded' => ['invulnerable' => 1],
        ]);

        self::assertSame(1, $result['invulnerable']);
    }

    public function testAnUnsetInvulnerabilityLeavesTheDefaultAlone(): void
    {
        $result = $this->activate(['plain' => ['atkmod' => 2.0]]);

        self::assertSame(0, $result['invulnerable']);
    }

    /**
     * Lifetaps and damage shields are collected whole rather than reduced to a
     * number, because the round applies each one separately.
     */
    public function testLifetapsAndDamageShieldsAreCollected(): void
    {
        $result = $this->activate([
            'leech' => ['lifetap' => 0.5],
            'thorns' => ['damageshield' => 3],
        ]);

        self::assertCount(1, $result['lifetap']);
        self::assertSame(0.5, $result['lifetap'][0]['lifetap']);
        self::assertCount(1, $result['dmgshield']);
        self::assertSame(3, $result['dmgshield'][0]['damageshield']);
    }

    /**
     * The tag decides which buffs announce themselves, not which ones count.
     *
     * This is the least obvious thing in the method: the $activate flag built
     * from the tag guards only the round message and the 'used' marker, while
     * the modifier arithmetic below it runs for every buff that is not
     * suspended. A reader expecting 'defense' to exclude an attack buff would
     * be wrong, so it is worth a case of its own.
     */
    public function testTheTagDoesNotGateTheModifiers(): void
    {
        // The second activate() replaces the whole 'blessing' entry, so the
        // 'used' marker the first run set is gone and the two calls do not
        // interfere. No fixture reset is needed between them.
        $onOffense = $this->activate(['blessing' => ['atkmod' => 2.0]], 'offense');
        $onDefense = $this->activate(['blessing' => ['atkmod' => 2.0]], 'defense');

        self::assertSame(2.0, $onOffense['atkmod']);
        self::assertSame(2.0, $onDefense['atkmod'], 'the attack modifier applies on defence too');
    }

    /**
     * A buff that has announced itself is marked used, so the same round
     * message cannot be printed twice.
     */
    public function testAnActivatedBuffIsMarkedUsed(): void
    {
        global $session;

        $this->activate(['blessing' => ['atkmod' => 2.0, 'roundmsg' => 'The blessing holds.']], 'offense');

        self::assertSame(1, $session['bufflist']['blessing']['used']);
        self::assertStringContainsString('The blessing holds.', Output::getInstance()->getRawOutput());
    }

    public function testABuffAlreadyMarkedUsedDoesNotAnnounceItselfAgain(): void
    {
        $this->activate([
            'blessing' => ['atkmod' => 2.0, 'roundmsg' => 'The blessing holds.', 'used' => 1],
        ], 'offense');

        self::assertStringNotContainsString('The blessing holds.', Output::getInstance()->getRawOutput());
    }
}
