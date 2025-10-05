<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\AddNews;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DoctrineConnection;
use PHPUnit\Framework\TestCase;
use Lotgd\Translator;

final class AddNewsTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        \Lotgd\MySQL\Database::resetDoctrineConnection();
        $GLOBALS['session'] = ['user' => ['acctid' => 3]];
        Translator::getInstance()->setSchema('ns');
    }

    protected function tearDown(): void
    {
        Translator::getInstance()->setSchema(false);
        unset($GLOBALS['session']);
        \Lotgd\MySQL\Database::resetDoctrineConnection();
    }

    public function testAddForUserPersistsArgumentsWithSpecialCharacters(): void
    {
        /** @var DoctrineConnection $connection */
        $connection = \Lotgd\MySQL\Database::getDoctrineConnection();
        $news = 'Hero "Arthur" – 勇者';
        $argument = "Dragon's Lair";

        AddNews::addForUser(2, $news, $argument);

        $this->assertNotEmpty($connection->executeStatements);
        $statement = $connection->executeStatements[0];

        $this->assertSame(
            'INSERT INTO ' . \Lotgd\MySQL\Database::prefix('news')
            . ' (newstext, newsdate, accountid, arguments, tlschema) VALUES (:newstext, :newsdate, :accountid, :arguments, :tlschema)',
            $statement['sql']
        );
        $this->assertSame($news, $statement['params']['newstext']);
        $this->assertSame(serialize([$argument]), $statement['params']['arguments']);
        $this->assertSame(2, $statement['params']['accountid']);
        $this->assertSame('ns', $statement['params']['tlschema']);
    }

    public function testAddUsesSessionAccountId(): void
    {
        /** @var DoctrineConnection $connection */
        $connection = \Lotgd\MySQL\Database::getDoctrineConnection();

        AddNews::add('Hello');

        $this->assertNotEmpty($connection->executeStatements);
        $statement = $connection->executeStatements[0];

        $this->assertSame(3, $statement['params']['accountid']);
        $this->assertSame('', $statement['params']['arguments']);
        $this->assertSame('ns', $statement['params']['tlschema']);
    }
}
