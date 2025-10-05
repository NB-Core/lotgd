<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\AddNews;
use Lotgd\DeathMessage;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DoctrineConnection;
use PHPUnit\Framework\TestCase;
use Lotgd\Translator;

final class DeathMessageNewsArgumentsTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        \Lotgd\MySQL\Database::resetDoctrineConnection();
        Translator::getInstance()->setSchema('ns');
        Database::$lastSql = '';
        Database::$mockResults = [[[
            'deathmessage' => "`5\"`6{goodguyname}'s mother wears combat boots`5\", screams {badguyname}.",
            'taunt'        => 'taunt',
        ]]];
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
        \Lotgd\MySQL\Database::resetDoctrineConnection();
        Database::$mockResults = [];
    }

    public function testNewsArgumentsContainOnlyTemplateValues(): void
    {
        /** @var DoctrineConnection $connection */
        $connection = \Lotgd\MySQL\Database::getDoctrineConnection();
        $where = 'in the forest';
        $deathmessage = DeathMessage::selectArray(true, ['{where}'], [$where]);

        AddNews::add('%s`n%s', $deathmessage['deathmessage'], 'taunt');

        $this->assertNotEmpty($connection->executeStatements);
        $statement = $connection->executeStatements[0];

        $args = unserialize($statement['params']['arguments']);

        $this->assertCount(2, $args); // deathmessage array and taunt
        $deathArgs = $args[0];
        $this->assertSame(['`^Hero`^', 'Dragon'], array_slice($deathArgs, 3));
    }
}
