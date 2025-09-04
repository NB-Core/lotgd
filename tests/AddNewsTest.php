<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\AddNews;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;
use Lotgd\Translator;

final class AddNewsTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);
        $GLOBALS['session'] = ['user' => ['acctid' => 3]];
        Translator::getInstance()->setSchema('ns');
        \Lotgd\MySQL\Database::$lastSql = '';
    }

    protected function tearDown(): void
    {
        Translator::getInstance()->setSchema(false);
        unset($GLOBALS['session']);
    }

    public function testAddForUserBuildsInsertSql(): void
    {
        AddNews::addForUser(2, 'Hi', 'x');
        $expectedArgs = addslashes(serialize(['x']));
        $this->assertStringContainsString('INSERT INTO news', \Lotgd\MySQL\Database::$lastSql);
        $this->assertStringContainsString("2,'$expectedArgs','ns')", \Lotgd\MySQL\Database::$lastSql);
    }

    public function testAddUsesSessionAccountId(): void
    {
        AddNews::add('Hello');
        $this->assertStringContainsString("3,'','ns')", \Lotgd\MySQL\Database::$lastSql);
    }
}
