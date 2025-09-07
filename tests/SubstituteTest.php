<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Substitute;
use PHPUnit\Framework\TestCase;

final class SubstituteTest extends TestCase
{
    protected function setUp(): void
    {
        global $session, $badguy;
        $session = ['user' => [
            'name'   => 'Hero',
            'sex'    => SEX_MALE,
            'weapon' => 'Sword',
            'armor'  => 'Leather',
        ]];
        $badguy = [
            'creaturename'  => 'Goblin',
            'creatureweapon' => 'Club',
            'diddamage' => 0,
        ];
    }

    public function testApplyReturnsGoodguyWeapon(): void
    {
        $this->assertSame('Sword', Substitute::apply('{goodguyweapon}'));
    }

    public function testApplyWithExtraPlaceholders(): void
    {
        $extra = ['{foo}', '{bar}'];
        $extrarep = ['magic', 'force'];
        $result = Substitute::apply('{goodguyweapon} uses {foo} with {bar}', $extra, $extrarep);
        $this->assertSame('Sword uses magic with force', $result);
    }

    public function testApplyArrayReturnsFormatAndValues(): void
    {
        $extra = ['{foo}'];
        $extrarep = ['energy'];
        $array = Substitute::applyArray('Attack with {goodguyweapon} and {foo}.', $extra, $extrarep);
        $this->assertSame('Attack with %s and %s.', $array[0]);
        $this->assertSame('Sword', $array[1]);
        $this->assertSame('energy', $array[2]);
        $formatted = vsprintf($array[0], array_slice($array, 1));
        $this->assertSame(Substitute::apply('Attack with {goodguyweapon} and {foo}.', $extra, $extrarep), $formatted);
    }
}
