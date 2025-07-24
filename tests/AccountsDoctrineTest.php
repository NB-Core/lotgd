<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Accounts;
use Lotgd\Tests\Stubs\DbMysqli;
use PHPUnit\Framework\TestCase;

final class AccountsDoctrineTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
        if (!class_exists('Lotgd\\Doctrine\\Bootstrap', false)) {
            require_once __DIR__ . '/Stubs/DoctrineBootstrap.php';
        }

        $GLOBALS['session'] = [
            'loggedin'    => true,
            'allowednavs' => [],
            'bufflist'    => [],
            'user'        => [
                'acctid'     => 1,
                'login'      => 'tester',
                'allowednavs'=> '',
                'bufflist'   => '',
                'alive'      => true,
            ],
        ];
        $GLOBALS['baseaccount'] = $GLOBALS['session']['user'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['session'], $GLOBALS['baseaccount']);
    }

    public function testSaveUserUsesDoctrineFlush(): void
    {
        Accounts::saveUser();
        $conn = \Lotgd\MySQL\Database::getDoctrineConnection();
        $this->assertNotEmpty($conn->queries);
        $mysqli = \Lotgd\MySQL\Database::getInstance();
        $this->assertSame([], $mysqli->queries);
    }
}
