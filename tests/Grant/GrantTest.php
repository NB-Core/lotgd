<?php

declare(strict_types=1);

namespace Lotgd\Tests\Grant;

use Lotgd\Action;
use Lotgd\Action\ActionResult;
use Lotgd\Grant;
use Lotgd\Nav;
use Lotgd\Realtime;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Grants (docs/ReactiveCore.md, Phase 5) against the stub connection: what
 * issuing writes and announces, that a grant is used only inside a guarded
 * action, which locks its whole group and closes it with the action, and
 * what is refused.
 */
final class GrantTest extends TestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';
    private const KEY_LOOKUP = '/SELECT target_key FROM granted_navs WHERE id = :id$/';
    private const GROUP_LOCK = '/FROM granted_navs WHERE target_key = :key ORDER BY id FOR UPDATE/';

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
        Realtime::resetState();
        Grant::resetState();

        Settings::setInstance(new DummySettings(['grants_enabled' => 1, 'guarded_actions' => 1, 'realtime_enabled' => 1]));

        $GLOBALS['session'] = [
            'loggedin' => true,
            'allowednavs' => [],
            'bufflist' => [],
            'user' => ['acctid' => 1, 'login' => 'officer', 'allowednavs' => serialize([]),
                'bufflist' => serialize([]), 'alive' => 1, 'version' => 3],
        ];
        $GLOBALS['baseaccount'] = $GLOBALS['session']['user'];
        $this->connection->queryResultsByPattern = [
            '/SELECT version FROM accounts WHERE acctid = :acctid FOR UPDATE/' => [['version' => 3]],
            '/SELECT next_seq FROM relay_sequence/' => [['next_seq' => 1]],
            self::KEY_LOOKUP => [['target_key' => 'clan-applicant-9']],
        ];
    }

    protected function tearDown(): void
    {
        Action::resetState();
        Grant::resetState();
        Realtime::resetState();
        Database::resetDoctrineConnection();
        unset($GLOBALS['session'], $GLOBALS['baseaccount'], $_SERVER['REQUEST_METHOD']);
    }

    public function testIssuingWritesTheGrantItsLinkAndAnAnnouncementToItsPlayer(): void
    {
        $id = Nav::grant(2, 'clan.accept', ['applicant' => 9], 'clan-applicant-9', 'Accept Nine', 'clan.php?op=grant', 3600);

        self::assertSame(1, $id);
        $insert = $this->statement('INSERT INTO granted_navs');
        self::assertSame(2, $insert['params']['acctid']);
        self::assertSame(1, $insert['params']['granted_by'], 'The player who started it.');
        self::assertSame('clan-applicant-9', $insert['params']['target_key']);
        self::assertSame('{"applicant":9}', $insert['params']['target']);
        self::assertSame('clan.php?op=grant&grant=1', $this->statement('UPDATE granted_navs SET link')['params']['link']);
        $event = $this->statement('INSERT INTO events');
        self::assertSame('user-2', $event['params']['channel']);
        self::assertStringContainsString('"region":"grants"', (string) $event['params']['payload']);
    }

    public function testWithGrantsOffNothingIsIssued(): void
    {
        Settings::setInstance(new DummySettings(['grants_enabled' => 0, 'guarded_actions' => 1]));

        self::assertSame(0, Nav::grant(2, 'clan.accept', [], 'k', 'Text', 'clan.php?op=grant', 3600));
        self::assertSame([], $this->connection->executeStatements);
    }

    public function testWithoutGuardedActionsGrantsStayOff(): void
    {
        Settings::setInstance(new DummySettings(['grants_enabled' => 1, 'guarded_actions' => 0]));

        self::assertFalse(Grant::enabled());
    }

    public function testAGrantIsOnlyUsedInsideAGuardedAction(): void
    {
        $this->expectException(\LogicException::class);
        Grant::claim(5, 1, self::ID);
    }

    public function testUsingAGrantLocksItsGroupAndClosesItWithTheAction(): void
    {
        $this->group([
            $this->row(4, 2),
            $this->row(5, 1),
            $this->row(6, 3),
        ]);

        $result = Action::run('clan.accept', self::ID, ['grant' => 5], static function (): array {
            $grant = Grant::claim(5, 1, self::ID);

            return ['applicant' => $grant['target']['applicant']];
        });

        self::assertSame(ActionResult::DONE, $result->status);
        self::assertSame(['applicant' => 9], $result->data);
        self::assertSame(
            ['START TRANSACTION', 'version lock', 'key', 'group lock', 'close', 'event', 'event', 'event', 'log', 'COMMIT'],
            array_slice($this->steps(), 0, 10)
        );
        $close = $this->statement('UPDATE granted_navs SET used_at');
        self::assertSame('clan-applicant-9', $close['params']['key']);
        self::assertSame(self::ID, $close['params']['action_id']);
        $channels = array_map(
            static fn (array $s): string => (string) $s['params']['channel'],
            $this->statements('INSERT INTO events')
        );
        self::assertSame(['user-2', 'user-1', 'user-3'], $channels, 'Every player of the group sees the offer close.');
    }

    /**
     * @return iterable<string,array{0:array<string,mixed>,1:string}>
     */
    public static function refusals(): iterable
    {
        yield 'used by another officer' => [['used_at' => '2026-10-21 10:00:00'], 'grant-used'];
        yield 'expired' => [['expires_at' => '2000-01-01 00:00:00'], 'grant-expired'];
        yield 'another player\'s' => [['acctid' => 2], 'grant-missing'];
    }

    /**
     * @param array<string,mixed> $override
     */
    #[DataProvider('refusals')]
    public function testAGrantThatIsNotOpenForThisPlayerIsRefusedAndNothingCloses(array $override, string $reason): void
    {
        $this->group([$override + $this->row(5, 1)]);

        $result = Action::run('clan.accept', self::ID, ['grant' => 5], static function (): array {
            Grant::claim(5, 1, self::ID);

            return [];
        });

        self::assertSame(ActionResult::REJECTED, $result->status);
        self::assertSame($reason, $result->reason);
        self::assertNotContains('close', $this->steps());
        self::assertContains('ROLLBACK', $this->steps());
    }

    public function testAnUnknownGrantIsRefused(): void
    {
        unset($this->connection->queryResultsByPattern[self::KEY_LOOKUP]);

        $result = Action::run('clan.accept', self::ID, ['grant' => 5], static function (): array {
            Grant::claim(5, 1, self::ID);

            return [];
        });

        self::assertSame('grant-missing', $result->reason);
    }

    public function testAnActionThatFailsAfterUsingTheGrantRollsTheCloseBack(): void
    {
        $this->group([$this->row(5, 1)]);

        try {
            Action::run('clan.accept', self::ID, ['grant' => 5], static function (): array {
                Grant::claim(5, 1, self::ID);

                throw new \DomainException('nope');
            });
            self::fail('The error reaches the page.');
        } catch (\DomainException) {
        }

        $steps = $this->steps();
        self::assertSame('ROLLBACK', end($steps));
        self::assertNotContains('COMMIT', $steps, 'The close was never committed: the grant is open again.');
    }

    public function testVoidingClosesTheOpenGrantsOfTheGroup(): void
    {
        $this->connection->queryResultsByPattern['/WHERE target_key = :key AND used_at IS NULL$/'] = [
            ['id' => 4, 'acctid' => 2, 'used_at' => null],
            ['id' => 6, 'acctid' => 3, 'used_at' => null],
        ];

        Grant::voidTarget('clan-applicant-9');

        self::assertSame('void', $this->statement('UPDATE granted_navs SET used_at')['params']['action_id']);
        self::assertCount(2, $this->statements('INSERT INTO events'));
    }

    public function testVoidingANothingIsNothing(): void
    {
        Grant::voidTarget('clan-applicant-9');

        self::assertSame([], $this->connection->executeStatements);
    }

    public function testOnlyTheViewLinkOfAnOpenGrantIsOfferedAndOnlyForAGet(): void
    {
        $this->connection->queryResultsByPattern['/SELECT 1 FROM granted_navs/'] = [['1' => 1]];

        $_SERVER['REQUEST_METHOD'] = 'GET';
        self::assertTrue(Grant::isOfferedTo(1, 'clan.php?op=grant&grant=5'));
        self::assertSame('clan.php?op=grant&grant=5', $this->connection->lastFetchAllParams['link']);

        self::assertTrue(Grant::isOfferedTo(1, 'clan.php?op=grant&grant=5&c=12-101010'), 'A navigation link carries the counter.');
        self::assertSame('clan.php?op=grant&grant=5', $this->connection->lastFetchAllParams['link']);

        self::assertFalse(Grant::isOfferedTo(1, 'clan.php?op=membership'));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        self::assertFalse(Grant::isOfferedTo(1, 'clan.php?op=grant&grant=5'), 'Using it is a POST the page registers itself.');
    }

    public function testAGrantThatIsNotOpenIsNotOffered(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        self::assertFalse(Grant::isOfferedTo(1, 'clan.php?op=grant&grant=5'));
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function group(array $rows): void
    {
        $this->connection->queryResultsByPattern[self::GROUP_LOCK] = $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function row(int $id, int $acctid): array
    {
        return [
            'id' => $id,
            'acctid' => $acctid,
            'action' => 'clan.accept',
            'target' => '{"applicant":9}',
            'used_at' => null,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ];
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
            } elseif (str_contains($sql, 'FROM accounts WHERE acctid = :acctid FOR UPDATE')) {
                $steps[] = 'version lock';
            } elseif (preg_match(self::KEY_LOOKUP, $sql) === 1) {
                $steps[] = 'key';
            } elseif (preg_match(self::GROUP_LOCK, $sql) === 1) {
                $steps[] = 'group lock';
            } elseif (str_starts_with($sql, 'UPDATE granted_navs SET used_at')) {
                $steps[] = 'close';
            } elseif (str_starts_with($sql, 'INSERT INTO events')) {
                $steps[] = 'event';
            } elseif (str_starts_with($sql, 'INSERT INTO action_log')) {
                $steps[] = 'log';
            }
        }

        return $steps;
    }

    /**
     * @return list<array{sql:string,params:array<string,mixed>}>
     */
    private function statements(string $prefix): array
    {
        return array_values(array_filter(
            $this->connection->executeStatements,
            static fn (array $s): bool => str_starts_with($s['sql'], $prefix)
        ));
    }

    /**
     * @return array{sql:string,params:array<string,mixed>}
     */
    private function statement(string $prefix): array
    {
        return $this->statements($prefix)[0] ?? self::fail('No statement ' . $prefix);
    }
}
