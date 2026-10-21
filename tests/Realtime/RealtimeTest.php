<?php

declare(strict_types=1);

namespace Lotgd\Tests\Realtime;

use Lotgd\Action;
use Lotgd\Realtime;
use Lotgd\Realtime\Relay;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * The outbox rules of docs/ReactiveCore.md, Phase 4, against the stub
 * connection: where an event may be written, that it commits or rolls back
 * with its change, and what the relay does in which order.
 */
final class RealtimeTest extends TestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';

    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        if (!class_exists('Lotgd\\Doctrine\\Bootstrap', false)) {
            require_once dirname(__DIR__) . '/Stubs/DoctrineBootstrap.php';
        }
        \Lotgd\MySQL\Database::$doctrineConnection = null;
        \Lotgd\Tests\Stubs\DoctrineBootstrap::$conn = null;
        $this->connection = new DoctrineConnection();
        Database::setDoctrineConnection($this->connection);
        Action::resetState();

        $settings = new DummySettings(['realtime_enabled' => 1]);
        Settings::setInstance($settings);

        $GLOBALS['session'] = [
            'loggedin' => true,
            'allowednavs' => [],
            'bufflist' => [],
            'user' => ['acctid' => 1, 'login' => 'tester', 'gold' => 5, 'allowednavs' => serialize([]),
                'bufflist' => serialize([]), 'alive' => 1, 'version' => 3],
        ];
        $GLOBALS['baseaccount'] = $GLOBALS['session']['user'];
        $this->connection->queryResultsByPattern = [
            '/SELECT version FROM accounts WHERE acctid = :acctid FOR UPDATE/' => [['version' => 3]],
            '/SELECT next_seq FROM relay_sequence/' => [['next_seq' => 7]],
        ];
    }

    protected function tearDown(): void
    {
        Action::resetState();
        Database::resetDoctrineConnection();
        unset($GLOBALS['session'], $GLOBALS['baseaccount']);
    }

    public function testAnEventAboutAGameChangeBelongsInsideAnAction(): void
    {
        $this->expectException(\LogicException::class);
        Realtime::publish('user-2', 'bank', ['region' => 'stats', 'version' => 1]);
    }

    public function testANotificationRefusesToJoinSomebodyElsesTransaction(): void
    {
        $this->connection->beginTransaction();

        $this->expectException(\LogicException::class);
        Realtime::notify('user-2', 'mail', ['region' => 'mail', 'version' => 1]);
    }

    public function testAnAnnouncementCommitsWithTheTransactionThatHoldsItsChange(): void
    {
        $this->connection->beginTransaction();
        Realtime::announce('user-2', 'mail', ['region' => 'mail', 'version' => 1]);

        self::assertSame(['START TRANSACTION', 'event'], $this->steps(), 'Written into the open transaction, relayed later.');
    }

    public function testAnEventIsWrittenInTheActionAndRelayedAfterItCommits(): void
    {
        $this->connection->queryResultsByPattern['/FROM events WHERE relayed_at IS NULL/'] = [
            ['id' => 1, 'event_id' => str_repeat('e', 32), 'channel' => 'user-2', 'type' => 'bank', 'payload' => '{"region":"stats","version":4}'],
        ];

        Action::run('bank.transfer', self::ID, [], static function (): array {
            Realtime::publish('user-2', 'bank', ['region' => 'stats', 'version' => 4]);

            return [];
        });

        self::assertSame(
            ['START TRANSACTION', 'event', 'COMMIT', 'START TRANSACTION', 'sequence lock', 'pending', 'delivery', 'relayed', 'sequence', 'COMMIT'],
            $this->steps()
        );
        $delivery = $this->statement('INSERT INTO event_deliveries');
        self::assertSame(7, $delivery['params']['seq'], 'The first number the sequence row holds.');
        self::assertSame('user-2', $delivery['params']['channel']);
        self::assertSame(8, $this->statement('UPDATE relay_sequence')['params']['next']);
    }

    public function testAnActionThatRollsBackLeavesNoEventAndRelaysNothing(): void
    {
        try {
            Action::run('bank.transfer', self::ID, [], static function (): array {
                Realtime::publish('user-2', 'bank', ['region' => 'stats', 'version' => 4]);

                throw new \DomainException('nope');
            });
        } catch (\DomainException) {
        }

        self::assertSame(['START TRANSACTION', 'event', 'ROLLBACK'], $this->steps());
    }

    public function testTheRelayHandsOutConsecutiveNumbersAndOnlyThen(): void
    {
        $this->connection->queryResultsByPattern['/FROM events WHERE relayed_at IS NULL/'] = [
            ['id' => 4, 'event_id' => str_repeat('a', 32), 'channel' => 'user-2', 'type' => 'mail', 'payload' => '{}'],
            ['id' => 5, 'event_id' => str_repeat('b', 32), 'channel' => 'user-3', 'type' => 'mail', 'payload' => '{}'],
        ];

        self::assertSame(2, Relay::run());

        $seqs = array_map(
            static fn (array $s): int => $s['params']['seq'],
            array_values(array_filter($this->connection->executeStatements, static fn (array $s): bool => str_starts_with($s['sql'], 'INSERT INTO event_deliveries')))
        );
        self::assertSame([7, 8], $seqs);
        self::assertSame(9, $this->statement('UPDATE relay_sequence')['params']['next']);
    }

    public function testARelayWithNothingToDoDoesNotMoveTheSequence(): void
    {
        self::assertSame(0, Relay::run());
        self::assertSame(['START TRANSACTION', 'sequence lock', 'pending', 'COMMIT'], $this->steps());
    }

    public function testTheRelayNeverRunsInsideAnAction(): void
    {
        $this->expectException(\LogicException::class);
        Action::run('bank.transfer', self::ID, [], static fn (): array => ['n' => Relay::run()]);
    }

    public function testWithTheSettingOffNothingIsWritten(): void
    {
        Settings::setInstance(new DummySettings(['realtime_enabled' => 0]));

        Realtime::notify('user-2', 'mail', ['region' => 'mail', 'version' => 1]);
        Realtime::announce('user-2', 'mail', ['region' => 'mail', 'version' => 1]);

        self::assertSame([], $this->steps());
    }

    /**
     * @return list<string>
     */
    private function steps(): array
    {
        $steps = [];
        foreach ($this->connection->queries as $sql) {
            $sql = (string) $sql;
            if (in_array($sql, ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true)) {
                $steps[] = $sql;
            } elseif (str_starts_with($sql, 'INSERT INTO events')) {
                $steps[] = 'event';
            } elseif (str_contains($sql, 'FROM relay_sequence WHERE id = 1 FOR UPDATE')) {
                $steps[] = 'sequence lock';
            } elseif (str_contains($sql, 'FROM events WHERE relayed_at IS NULL')) {
                $steps[] = 'pending';
            } elseif (str_starts_with($sql, 'INSERT INTO event_deliveries')) {
                $steps[] = 'delivery';
            } elseif (str_starts_with($sql, 'UPDATE events SET relayed_at')) {
                $steps[] = 'relayed';
            } elseif (str_starts_with($sql, 'UPDATE relay_sequence')) {
                $steps[] = 'sequence';
            }
        }

        return $steps;
    }

    /**
     * @return array{sql:string,params:array<string,mixed>}
     */
    private function statement(string $prefix): array
    {
        foreach ($this->connection->executeStatements as $statement) {
            if (str_starts_with($statement['sql'], $prefix)) {
                return $statement;
            }
        }
        self::fail('No statement ' . $prefix);
    }
}
