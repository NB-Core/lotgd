<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Accounts;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\VersionConflict;
use PHPUnit\Framework\TestCase;

/**
 * Accounts::persist(), the write half of saveUser(): which changes move
 * accounts.version on, and what the version-checked write does.
 */
final class AccountsPersistTest extends TestCase
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
        Accounts::setAccountEntity(null);

        $GLOBALS['session'] = [
            'loggedin'    => true,
            'allowednavs' => [],
            'bufflist'    => [],
            'user'        => [
                'acctid'      => 1,
                'login'       => 'tester',
                'gold'        => 100,
                'allowednavs' => serialize([]),
                'bufflist'    => serialize([]),
                'alive'       => 1,
                'laston'      => '2026-10-20 10:00:00',
                'version'     => 3,
            ],
        ];
        $GLOBALS['baseaccount'] = $GLOBALS['session']['user'];
    }

    protected function tearDown(): void
    {
        Accounts::setAccountEntity(null);
        unset($GLOBALS['session'], $GLOBALS['baseaccount']);
    }

    public function testBookkeepingAloneDoesNotMoveTheVersion(): void
    {
        $GLOBALS['session']['allowednavs'] = ['village.php' => true];
        $GLOBALS['session']['user']['laston'] = '2026-10-20 10:05:00';
        $GLOBALS['session']['user']['restorepage'] = 'village.php';

        Accounts::persist();

        self::assertSame(3, $GLOBALS['session']['user']['version'], 'Seeing a page is not a change another page must notice.');
        self::assertSame(3, $GLOBALS['baseaccount']['version']);
    }

    public function testAChangeToTheGameStateMovesTheVersionOnOnce(): void
    {
        $GLOBALS['session']['user']['gold'] = 40;

        Accounts::persist();

        self::assertSame(4, $GLOBALS['session']['user']['version']);
        self::assertSame(4, Accounts::getAccountEntity()->getVersion());
        self::assertSame(4, $GLOBALS['baseaccount']['version']);

        // Written already: the page end has nothing left to move on.
        Accounts::persist();
        self::assertSame(4, $GLOBALS['session']['user']['version']);
    }

    public function testAnAccountWithoutTheColumnIsSavedAsBefore(): void
    {
        unset($GLOBALS['session']['user']['version'], $GLOBALS['baseaccount']['version']);
        $GLOBALS['session']['user']['gold'] = 40;

        Accounts::persist();

        self::assertArrayNotHasKey('version', $GLOBALS['session']['user']);
        self::assertSame(40, Accounts::getAccountEntity()->getGold());
    }

    public function testTheCheckedWriteThrowsWhenTheVersionMovedOn(): void
    {
        $conn = \Lotgd\MySQL\Database::getDoctrineConnection();
        $conn->statementResultsByPattern = ['/AND version = :expected_version/' => 0];
        $GLOBALS['session']['user']['gold'] = 40;

        $this->expectException(VersionConflict::class);
        Accounts::persist(true);
    }

    public function testTheCheckedWriteLeavesOutWhatTheAccountDoesNotMap(): void
    {
        $conn = \Lotgd\MySQL\Database::getDoctrineConnection();
        $GLOBALS['session']['user']['gold'] = 40;
        $GLOBALS['session']['user']['some_module_scratch'] = 'x';

        Accounts::persist(true);

        $update = end($conn->executeStatements);
        self::assertStringContainsString('gold = :gold', $update['sql']);
        self::assertStringNotContainsString('some_module_scratch', $update['sql']);
        self::assertSame(3, $update['params']['expected_version']);
    }
}
