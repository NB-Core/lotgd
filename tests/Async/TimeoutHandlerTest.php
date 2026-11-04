<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async;

use Lotgd\Async\Handler\Timeout;
use Lotgd\Security\Csrf;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;

/**
 * The session panel's server side: the time left in data `session`, and
 * "Stay logged in", which writes and so checks the async token itself.
 */
#[PreserveGlobalState(false)]
final class TimeoutHandlerTest extends TestCase
{
    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        $this->connection = new DoctrineConnection();
        Database::setDoctrineConnection($this->connection);
        Settings::setInstance(new DummySettings(['LOGINTIMEOUT' => 900]));
        Timeout::getInstance()->setStartTimeoutShowSeconds(300);
        Timeout::getInstance()->setNeverTimeoutIfBrowserOpen(false);
        $GLOBALS['session'] = ['user' => ['acctid' => 7, 'loggedin' => 1, 'laston' => date('Y-m-d H:i:s', time() - 700)]];
        unset($_SERVER['HTTP_X_LOTGD_CSRF']);
        ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'lotgd-timeout-log'));
    }

    protected function tearDown(): void
    {
        Database::resetDoctrineConnection();
        @unlink((string) ini_get('error_log'));
        ini_restore('error_log');
        unset($GLOBALS['session'], $_SERVER['HTTP_X_LOTGD_CSRF']);
    }

    public function testTheStatusIsTheTimeLeftAndNoLongerAnAssign(): void
    {
        $response = Timeout::getInstance()->timeoutStatus(true);

        self::assertSame([], $response->getCommands(), 'There is no #notify any more.');
        self::assertEqualsWithDelta(200, $response->getData()['session']['remaining'], 2);
        self::assertSame([], $this->connection->executeStatements, 'Asking writes nothing.');
    }

    public function testAnExpiredSessionHasNoTimeLeft(): void
    {
        $GLOBALS['session'] = ['loggedin' => false];

        self::assertSame(['remaining' => 0], Timeout::getInstance()->timeoutStatus(true)->getData()['session']);
    }

    public function testWithoutAPlayerOrTheSignsOfATimeoutThereIsNothingToSay(): void
    {
        $GLOBALS['session'] = [];

        self::assertSame([], Timeout::getInstance()->timeoutStatus(true)->getData());
    }

    public function testWhenTheOpenBrowserKeepsTheSessionThePollCountsAsActivity(): void
    {
        Timeout::getInstance()->setNeverTimeoutIfBrowserOpen(true);

        $remaining = Timeout::getInstance()->timeoutStatus(true)->getData()['session']['remaining'];

        self::assertEqualsWithDelta(900, $remaining, 2);
        self::assertCount(1, $this->connection->executeStatements);
    }

    public function testStayingLoggedInWithoutTheTokenWritesNothing(): void
    {
        Csrf::token(Csrf::SCOPE_ASYNC);
        $_SERVER['HTTP_X_LOTGD_CSRF'] = 'not-the-token';

        $answer = Timeout::getInstance()->keepAlive()->getData();

        self::assertEqualsWithDelta(200, $answer['session']['remaining'], 2);
        self::assertSame([], $this->connection->executeStatements);
    }

    public function testStayingLoggedInCountsAsActivity(): void
    {
        $_SERVER['HTTP_X_LOTGD_CSRF'] = Csrf::token(Csrf::SCOPE_ASYNC);

        $answer = Timeout::getInstance()->keepAlive()->getData();

        self::assertEqualsWithDelta(900, $answer['session']['remaining'], 2);
        self::assertCount(1, $this->connection->executeStatements);
        $statement = $this->connection->executeStatements[0];
        self::assertStringContainsString('SET laston = :laston WHERE acctid = :acctid', $statement['sql']);
        self::assertSame(7, $statement['params']['acctid']);
    }

    public function testALoggedOutPlayerCannotStayLoggedIn(): void
    {
        $GLOBALS['session'] = ['loggedin' => false];
        $_SERVER['HTTP_X_LOTGD_CSRF'] = Csrf::token(Csrf::SCOPE_ASYNC);

        self::assertSame(['remaining' => 0], Timeout::getInstance()->keepAlive()->getData()['session']);
        self::assertSame([], $this->connection->executeStatements);
    }
}
