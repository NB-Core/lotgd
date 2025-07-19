<?php

declare(strict_types=1);



namespace {
    use PHPUnit\Framework\TestCase;
    use Lotgd\AddNews;

    require_once __DIR__ . '/../config/constants.php';

    final class AddNewsTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['session'] = ['user' => ['acctid' => 3]];
            \Lotgd\Translator::tlschema('ns');
            \Lotgd\MySQL\Database::$lastSql = '';
        }

        protected function tearDown(): void
        {
            \Lotgd\Translator::tlschema(false);
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
}
