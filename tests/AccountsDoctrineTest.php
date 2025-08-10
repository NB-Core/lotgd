<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Accounts;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

final class AccountsDoctrineTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        if (!class_exists('Lotgd\\Doctrine\\Bootstrap', false)) {
            require_once __DIR__ . '/Stubs/DoctrineBootstrap.php';
        }
        \Lotgd\MySQL\Database::$doctrineConnection = null;
        \Lotgd\MySQL\Database::$instance = null;
        \Lotgd\Tests\Stubs\DoctrineBootstrap::$conn = null;

        $GLOBALS['session'] = [
            'loggedin'    => true,
            'allowednavs' => [],
            'bufflist'    => [],
            'user'        => [
                'acctid'     => 1,
                'login'      => 'tester',
                'allowednavs' => '',
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

    public function testSaveUserCastsBooleanFields(): void
    {
        $GLOBALS['session']['user']['alive'] = 1;
        Accounts::saveUser();
        $entity = Accounts::getAccountEntity();
        $this->assertTrue($entity->getAlive());
    }

    public function testSaveUserCastsIntegerFields(): void
    {
        $GLOBALS['session']['user']['level'] = '3';
        $GLOBALS['baseaccount']['level'] = 1;
        Accounts::saveUser();
        $entity = Accounts::getAccountEntity();
        $this->assertSame(3, $entity->getLevel());
    }

    public function testSaveUserCastsFloatStringToIntegerField(): void
    {
        $GLOBALS['session']['user']['level'] = '2.5';
        $GLOBALS['baseaccount']['level'] = 1;
        Accounts::saveUser();
        $entity = Accounts::getAccountEntity();
        $this->assertSame(2, $entity->getLevel());
    }

    public function testSaveUserCastsNonBooleanValues(): void
    {
        $GLOBALS['session']['user']['alive'] = '2';
        $GLOBALS['baseaccount']['alive'] = false;
        Accounts::saveUser();
        $entity = Accounts::getAccountEntity();
        $this->assertTrue($entity->getAlive());
    }

    public function testSaveUserCastsFloatFields(): void
    {
        $GLOBALS['session']['user']['gentime'] = '1.5';
        $GLOBALS['baseaccount']['gentime'] = 0;
        Accounts::saveUser();
        $entity = Accounts::getAccountEntity();
        $this->assertSame(1.5, $entity->getGentime());
    }

    public function testSaveUserHandlesMissingAllowednavs(): void
    {
        unset($GLOBALS['session']['allowednavs']);
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });

        try {
            Accounts::saveUser();
        } finally {
            restore_error_handler();
        }

        $entity = Accounts::getAccountEntity();
        $this->assertNotNull($entity);
    }
}
