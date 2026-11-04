<?php

declare(strict_types=1);

namespace Lotgd\Tests\Encounter;

use Lotgd\Action;
use Lotgd\Async\Handler\Duel;
use Lotgd\Encounter;
use Lotgd\Grant;
use Lotgd\Realtime;
use Lotgd\Security\Csrf;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;

/**
 * The duel's async moves write, so they check the async token themselves,
 * whatever csrf_mode says, and run nothing without it.
 */
#[PreserveGlobalState(false)]
final class DuelHandlerTest extends TestCase
{
    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        $this->connection = new DoctrineConnection();
        Database::setDoctrineConnection($this->connection);
        Action::resetState();
        Realtime::resetState();
        Grant::resetState();
        Encounter::resetState();
        Settings::setInstance(new DummySettings(['live_encounters' => 1, 'realtime_enabled' => 1, 'grants_enabled' => 1, 'guarded_actions' => 1]));
        $GLOBALS['session'] = ['loggedin' => true, 'user' => ['acctid' => 5, 'loggedin' => 1, 'version' => 3]];
        unset($_SERVER['HTTP_X_LOTGD_CSRF']);
        $_POST = [];
        // The refusal's log line, kept out of the test output.
        ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'lotgd-duel-log'));
    }

    protected function tearDown(): void
    {
        Database::resetDoctrineConnection();
        @unlink((string) ini_get('error_log'));
        ini_restore('error_log');
        unset($GLOBALS['session'], $_SERVER['HTTP_X_LOTGD_CSRF']);
    }

    public function testAMoveWithoutTheTokenRunsNothing(): void
    {
        Csrf::token(Csrf::SCOPE_ASYNC);
        $_SERVER['HTTP_X_LOTGD_CSRF'] = 'not-the-token';

        $answer = (new Duel())->choose(1, 1, 'attack')->getData();

        self::assertSame(['status' => 'rejected', 'reason' => 'csrf_invalid'], $answer['duel']);
        self::assertNotContains('START TRANSACTION', $this->connection->queries);
    }

    public function testWithEncountersOffThereIsNoDuel(): void
    {
        Settings::setInstance(new DummySettings(['live_encounters' => 0]));
        $_SERVER['HTTP_X_LOTGD_CSRF'] = Csrf::token(Csrf::SCOPE_ASYNC);

        self::assertSame(['status' => 'rejected', 'reason' => 'disabled'], (new Duel())->resolve(1, 1)->getData()['duel']);
    }

    public function testAMoveWithTheTokenRunsAsAGuardedAction(): void
    {
        $_SERVER['HTTP_X_LOTGD_CSRF'] = Csrf::token(Csrf::SCOPE_ASYNC);
        $this->connection->queryResultsByPattern = [
            '/SELECT version FROM accounts WHERE acctid = :acctid FOR UPDATE/' => [['version' => 3]],
        ];
        $GLOBALS['baseaccount'] = $GLOBALS['session']['user'];

        $answer = (new Duel())->choose(1, 1, 'attack')->getData();

        // No such encounter in the stub: refused inside the action, rolled back.
        self::assertSame(['status' => 'rejected', 'reason' => 'no-encounter'], $answer['duel']);
        self::assertContains('START TRANSACTION', $this->connection->queries);
        self::assertContains('ROLLBACK', $this->connection->queries);
    }
}
