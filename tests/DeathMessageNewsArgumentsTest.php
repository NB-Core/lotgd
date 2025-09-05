<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\AddNews;
use Lotgd\DeathMessage;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;
use Lotgd\Translator;

final class DeathMessageNewsArgumentsTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        Translator::getInstance()->setSchema('ns');
        Database::$lastSql = '';
        $GLOBALS['session'] = ['user' => [
            'acctid' => 3,
            'sex'    => 0,
            'weapon' => 'sword',
            'armor'  => 'armor',
            'name'   => 'Hero',
        ]];
        $GLOBALS['badguy'] = ['creatureweapon' => 'claws', 'creaturename' => 'Dragon'];
    }

    protected function tearDown(): void
    {
        Translator::getInstance()->setSchema(false);
        unset($GLOBALS['session'], $GLOBALS['badguy']);
    }

    public function testNewsArgumentsContainOnlyTemplateValues(): void
    {
        $where = 'in the forest';
        $deathmessage = DeathMessage::selectArray(true, ['{where}'], [$where]);

        AddNews::add('%s`n%s', $deathmessage['deathmessage'], 'taunt');

        $this->assertTrue(
            preg_match("/VALUES \('.*','.*',\d+,'([^']*)','ns'\)/", Database::$lastSql, $matches) === 1
        );
        $args = unserialize(stripslashes($matches[1]));

        $this->assertCount(2, $args); // deathmessage array and taunt
        $deathArgs = $args[0];
        $this->assertSame(['`^Hero`^', 'Dragon'], array_slice($deathArgs, 3));
    }
}
