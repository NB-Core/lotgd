<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\PlayerFunctions;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * The attack and defence breakdowns for an account other than the current one.
 *
 * Both methods are declared to return a string, but returned the int 0 when
 * the account was not found, which strict_types turns into a TypeError. And
 * the row they read for another account lacked the weapon, armour and level
 * columns the breakdown prints.
 */
final class PlayerFunctionsExplainedTest extends TestCase
{
    protected function setUp(): void
    {
        global $settings, $session;
        class_exists(Database::class);
        Database::$mockResults = [];
        $settings = new DummySettings([]);
        $session = ['user' => []];
    }

    protected function tearDown(): void
    {
        Database::$mockResults = [];
        unset($GLOBALS['settings'], $GLOBALS['session']);
    }

    public function testAMissingAccountHasNoAttackBreakdown(): void
    {
        Database::$mockResults[] = [];

        $this->assertSame('', PlayerFunctions::explainedGetPlayerAttack(404));
    }

    public function testAMissingAccountHasNoDefenseBreakdown(): void
    {
        Database::$mockResults[] = [];

        $this->assertSame('', PlayerFunctions::explainedGetPlayerDefense(404));
    }

    public function testAnotherAccountsAttackBreakdownShowsItsWeaponAndLevel(): void
    {
        Database::$mockResults[] = [[
            'strength' => 12, 'wisdom' => 6, 'intelligence' => 6, 'attack' => 30, 'weapondmg' => 15, 'level' => 6,
        ]];
        Database::$mockResults[] = [['dexterity' => 10, 'intelligence' => 6]];

        $explained = PlayerFunctions::explainedGetPlayerAttack(7);

        $this->assertStringContainsString('15 Weapon', $explained);
        $this->assertStringContainsString('5 Train', $explained);
        $this->assertStringContainsString('weapondmg,level', Database::getDoctrineConnection()->queries[0]);
    }

    public function testAnotherAccountsDefenseBreakdownShowsItsArmorAndLevel(): void
    {
        Database::$mockResults[] = [[
            'constitution' => 12, 'wisdom' => 6, 'defense' => 25, 'armordef' => 10, 'level' => 6,
        ]];
        Database::$mockResults[] = [['dexterity' => 10, 'intelligence' => 6]];

        $explained = PlayerFunctions::explainedGetPlayerDefense(7);

        $this->assertStringContainsString('10 Armor', $explained);
        $this->assertStringContainsString('5 Train', $explained);
        $this->assertStringContainsString('armordef,level', Database::getDoctrineConnection()->queries[0]);
    }
}
