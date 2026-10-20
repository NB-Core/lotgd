<?php

declare(strict_types=1);

namespace Lotgd\Tests\Action;

use Lotgd\Accounts;
use Lotgd\Action;
use Lotgd\Action\ActionAborted;
use Lotgd\Action\ActionResult;
use Lotgd\Modules;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use PHPUnit\Framework\TestCase;

/**
 * Action::run(), against the stub connection: the order of docs/ReactiveCore.md
 * (Phase 3), and what is left behind when an action does not go through.
 */
final class ActionTest extends TestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';
    private const LOG_LOOKUP = '/FROM action_log WHERE acctid = :acctid AND action_id = :action_id$/';
    private const VERSION_LOCK = '/SELECT version FROM accounts WHERE acctid = :acctid FOR UPDATE/';
    private const CHECKED_UPDATE = '/AND version = :expected_version/';

    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        if (!class_exists('Lotgd\\Doctrine\\Bootstrap', false)) {
            require_once dirname(__DIR__) . '/Stubs/DoctrineBootstrap.php';
        }
        \Lotgd\MySQL\Database::$doctrineConnection = null;
        \Lotgd\MySQL\Database::$instance = null;
        \Lotgd\Tests\Stubs\DoctrineBootstrap::$conn = null;
        Action::resetState();
        Accounts::setAccountEntity(null);

        $GLOBALS['session'] = [
            'loggedin'    => true,
            'allowednavs' => [],
            'bufflist'    => [],
            'user'        => [
                'acctid'      => 1,
                'login'       => 'tester',
                'name'        => 'Tester',
                'gold'        => 100,
                'goldinbank'  => 0,
                'allowednavs' => serialize([]),
                'bufflist'    => serialize([]),
                'alive'       => 1,
                'version'     => 3,
            ],
        ];
        $GLOBALS['baseaccount'] = $GLOBALS['session']['user'];
        $GLOBALS['companions'] = [];
        $this->connection()->queryResultsByPattern = [self::VERSION_LOCK => [['version' => 3]]];
    }

    protected function tearDown(): void
    {
        Action::resetState();
        Accounts::setAccountEntity(null);
        unset($GLOBALS['session'], $GLOBALS['baseaccount'], $GLOBALS['companions']);
    }

    public function testAnActionRunsInOneTransactionInTheAgreedOrder(): void
    {
        $result = Action::run('bank.deposit', self::ID, ['amount' => 40], static function (): array {
            $GLOBALS['session']['user']['gold'] -= 40;
            $GLOBALS['session']['user']['goldinbank'] += 40;

            return ['amount' => 40];
        });

        self::assertSame(ActionResult::DONE, $result->status);
        self::assertSame(['amount' => 40], $result->data);
        self::assertSame(
            ['START TRANSACTION', 'version lock', 'action_log lookup', 'checked update', 'log insert', 'COMMIT'],
            $this->steps()
        );
        self::assertSame(4, $GLOBALS['session']['user']['version'], 'A change to the game state moves the version on.');
        self::assertSame(4, $GLOBALS['baseaccount']['version']);
        self::assertSame(60, $GLOBALS['baseaccount']['gold'], 'The base follows what was written, so the page end writes nothing twice.');
        self::assertFalse(Action::isRunning());
    }

    public function testTheCheckedUpdateNamesTheVersionThePageWasReadWith(): void
    {
        Action::run('bank.deposit', self::ID, [], static function (): array {
            $GLOBALS['session']['user']['gold'] = 1;

            return [];
        });

        $conn = $this->connection();
        $update = array_values(array_filter(
            $conn->executeStatements,
            static fn ($statement): bool => preg_match(self::CHECKED_UPDATE, (string) ($statement['sql'] ?? '')) === 1
        ));
        self::assertCount(1, $update);
        self::assertSame(3, $update[0]['params']['expected_version']);
        self::assertSame(4, $update[0]['params']['version']);
        self::assertSame(1, $update[0]['params']['gold']);
    }

    public function testFollowUpsRunOnlyAfterTheCommit(): void
    {
        $conn = $this->connection();
        $seen = null;
        Action::run('bank.transfer', self::ID, [], static function () use ($conn, &$seen): array {
            Action::afterCommit(static function () use ($conn, &$seen): void {
                $seen = end($conn->queries);
            });

            return [];
        });

        self::assertSame('COMMIT', $seen);
    }

    public function testTheSameFormAgainIsAnsweredFromTheLogWithoutRunning(): void
    {
        $this->connection()->queryResultsByPattern = [self::LOG_LOOKUP => [[
            'action_name' => 'bank.deposit',
            'params_hash' => Action::paramsHash('bank.deposit', ['amount' => 40]),
            'result' => json_encode(['amount' => 40]),
        ]]] + $this->connection()->queryResultsByPattern;

        $ran = false;
        $result = Action::run('bank.deposit', self::ID, ['amount' => 40], static function () use (&$ran): array {
            $ran = true;

            return [];
        });

        self::assertSame(ActionResult::REPLAYED, $result->status);
        self::assertSame(['amount' => 40], $result->data);
        self::assertTrue($result->succeeded());
        self::assertFalse($ran);
        self::assertSame(['START TRANSACTION', 'version lock', 'action_log lookup', 'ROLLBACK'], $this->steps());
    }

    public function testAFormAlreadyBookedIsAnsweredEvenFromAStalePage(): void
    {
        // The booking itself moved the version on; the resent form still
        // carries the page it was shown on.
        $this->connection()->queryResultsByPattern = [
            self::VERSION_LOCK => [['version' => 4]],
            self::LOG_LOOKUP => [[
                'action_name' => 'bank.deposit',
                'params_hash' => Action::paramsHash('bank.deposit', []),
                'result' => '{"amount":40}',
            ]],
        ];

        $result = Action::run('bank.deposit', self::ID, [], static fn (): array => []);

        self::assertSame(ActionResult::REPLAYED, $result->status);
    }

    public function testADeadlockIsAConflictNotAnError(): void
    {
        $result = Action::run('bank.transfer', self::ID, [], static function (): array {
            $GLOBALS['session']['user']['gold'] = 0;

            throw new class ('Deadlock found when trying to get lock') extends \RuntimeException implements \Doctrine\DBAL\Exception\RetryableException {
            };
        });

        self::assertSame(ActionResult::CONFLICT, $result->status);
        self::assertSame(100, $GLOBALS['session']['user']['gold']);
        self::assertContains('ROLLBACK', $this->connection()->queries);
    }

    public function testTheSameIdWithOtherParametersIsRefused(): void
    {
        $this->connection()->queryResultsByPattern = [self::LOG_LOOKUP => [[
            'action_name' => 'bank.deposit',
            'params_hash' => Action::paramsHash('bank.deposit', ['amount' => 40]),
            'result' => '{}',
        ]]] + $this->connection()->queryResultsByPattern;

        $result = Action::run('bank.deposit', self::ID, ['amount' => 41], static fn (): array => []);

        self::assertSame(ActionResult::REJECTED, $result->status);
        self::assertSame('action-id-reused', $result->reason);
    }

    public function testAMissingIdIsRefusedBeforeAnythingHappens(): void
    {
        $result = Action::run('bank.deposit', 'not-an-id', [], static fn (): array => []);

        self::assertSame(ActionResult::REJECTED, $result->status);
        self::assertSame('missing-action-id', $result->reason);
        self::assertSame([], $this->steps());
    }

    public function testAnAccountChangedSinceThePageWasReadIsAConflictAndNothingRuns(): void
    {
        $this->connection()->queryResultsByPattern = [self::VERSION_LOCK => [['version' => 5]]];

        $ran = false;
        $result = Action::run('bank.deposit', self::ID, [], static function () use (&$ran): array {
            $ran = true;

            return [];
        });

        self::assertSame(ActionResult::CONFLICT, $result->status);
        self::assertFalse($ran);
        self::assertSame(['START TRANSACTION', 'version lock', 'action_log lookup', 'ROLLBACK'], $this->steps());
    }

    public function testALostCheckedUpdateRollsBackAndRestoresTheSession(): void
    {
        $this->connection()->statementResultsByPattern = [self::CHECKED_UPDATE => 0];
        $before = $GLOBALS['session']['user'];
        $ranAfter = false;

        $result = Action::run('bank.deposit', self::ID, [], static function () use (&$ranAfter): array {
            $GLOBALS['session']['user']['gold'] = 0;
            Action::afterCommit(static function () use (&$ranAfter): void {
                $ranAfter = true;
            });

            return [];
        });

        self::assertSame(ActionResult::CONFLICT, $result->status);
        self::assertSame($before, $GLOBALS['session']['user']);
        self::assertSame(3, $GLOBALS['baseaccount']['version']);
        self::assertFalse($ranAfter, 'A follow-up of an action that did not commit must not run.');
        self::assertNotContains('log insert', $this->steps());
        self::assertContains('ROLLBACK', $this->connection()->queries);
    }

    public function testAFailingActionLeavesNothingBehindAndTheNextOneSaves(): void
    {
        $before = $GLOBALS['session']['user'];
        $baseBefore = $GLOBALS['baseaccount'];

        try {
            Action::run('bank.deposit', self::ID, [], static function (): array {
                $GLOBALS['session']['user']['gold'] = 0;
                Accounts::persist(true);

                throw new \DomainException('the ledger caught fire');
            });
            self::fail('The failure must reach the caller.');
        } catch (\DomainException $error) {
            self::assertSame('the ledger caught fire', $error->getMessage());
        }

        self::assertSame($before, $GLOBALS['session']['user']);
        self::assertSame($baseBefore, $GLOBALS['baseaccount']);
        self::assertNull(Accounts::getAccountEntity());
        self::assertContains('ROLLBACK', $this->connection()->queries);
        self::assertContains('CLEAR ENTITY MANAGER', $this->connection()->queries);
        self::assertNotContains('log insert', $this->steps());
        self::assertFalse(Action::isRunning());

        // The same id may run again, and it writes what it changes.
        $this->connection()->queries = [];
        $this->connection()->executeStatements = [];
        $result = Action::run('bank.deposit', self::ID, [], static function (): array {
            $GLOBALS['session']['user']['gold'] = 7;

            return [];
        });

        self::assertSame(ActionResult::DONE, $result->status);
        self::assertSame(['START TRANSACTION', 'version lock', 'action_log lookup', 'checked update', 'log insert', 'COMMIT'], $this->steps());
        self::assertSame(7, $GLOBALS['baseaccount']['gold']);
    }

    public function testAnActionThatAbortsIsRefusedWithItsReason(): void
    {
        $result = Action::run('bank.transfer', self::ID, [], static function (): array {
            $GLOBALS['session']['user']['gold'] = 0;

            throw new ActionAborted('recipient-changed');
        });

        self::assertSame(ActionResult::REJECTED, $result->status);
        self::assertSame('recipient-changed', $result->reason);
        self::assertSame(100, $GLOBALS['session']['user']['gold']);
        self::assertContains('ROLLBACK', $this->connection()->queries);
    }

    public function testPersistingTwiceInOneActionWritesOnce(): void
    {
        Action::run('bank.deposit', self::ID, [], static function (): array {
            $GLOBALS['session']['user']['gold'] = 1;
            Accounts::persist(true);

            return [];
        });

        self::assertSame(['START TRANSACTION', 'version lock', 'action_log lookup', 'checked update', 'log insert', 'COMMIT'], $this->steps());
    }

    public function testActionsDoNotNest(): void
    {
        $this->expectException(\LogicException::class);

        Action::run('outer', self::ID, [], static fn (): array => Action::run('inner', self::ID, [], static fn (): array => [])->data);
    }

    public function testAnActionDoesNotJoinATransactionSomebodyElseOpened(): void
    {
        $this->connection()->beginTransaction();

        $this->expectException(\LogicException::class);
        Action::run('bank.deposit', self::ID, [], static fn (): array => []);
    }

    public function testATableLockInsideAnActionIsRefusedBeforeItCommitsTheAction(): void
    {
        $conn = $this->connection();

        try {
            Action::run('bank.deposit', self::ID, [], static function (): array {
                Modules::semAcquire();

                return [];
            });
            self::fail('LOCK TABLES would have committed the action.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('implicitly', $error->getMessage());
        }

        self::assertSame([], preg_grep('/LOCK TABLES/', $conn->queries));
        self::assertContains('ROLLBACK', $conn->queries);
    }

    public function testOutsideAnActionAFollowUpRunsAtOnce(): void
    {
        $ran = false;
        Action::afterCommit(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertTrue($ran);
    }

    public function testAnIdIsThirtyTwoHexDigitsAndTheFieldCarriesIt(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', Action::newId());
        self::assertNotSame(Action::newId(), Action::newId());
        self::assertMatchesRegularExpression("/^<input type='hidden' name='action_id' value='[0-9a-f]{32}'>$/", Action::idField());
    }

    public function testTheParameterHashDoesNotDependOnKeyOrder(): void
    {
        self::assertSame(
            Action::paramsHash('bank.transfer', ['amount' => 5, 'to' => 'bob']),
            Action::paramsHash('bank.transfer', ['to' => 'bob', 'amount' => 5])
        );
        self::assertNotSame(
            Action::paramsHash('bank.transfer', ['amount' => 5]),
            Action::paramsHash('bank.deposit', ['amount' => 5])
        );
    }

    private function connection(): \Lotgd\Tests\Stubs\DoctrineConnection
    {
        $conn = \Lotgd\MySQL\Database::getDoctrineConnection();
        self::assertInstanceOf(\Lotgd\Tests\Stubs\DoctrineConnection::class, $conn);

        return $conn;
    }

    /**
     * The transaction's steps, named, in the order the connection saw them.
     *
     * @return list<string>
     */
    private function steps(): array
    {
        $steps = [];
        foreach ($this->connection()->queries as $sql) {
            $sql = (string) $sql;
            if ($sql === 'START TRANSACTION' || $sql === 'COMMIT' || $sql === 'ROLLBACK') {
                $steps[] = $sql;
            } elseif (preg_match(self::LOG_LOOKUP, $sql) === 1) {
                $steps[] = 'action_log lookup';
            } elseif (preg_match(self::VERSION_LOCK, $sql) === 1) {
                $steps[] = 'version lock';
            } elseif (preg_match(self::CHECKED_UPDATE, $sql) === 1) {
                $steps[] = 'checked update';
            } elseif (str_starts_with($sql, 'INSERT INTO action_log')) {
                $steps[] = 'log insert';
            }
        }

        return $steps;
    }
}
