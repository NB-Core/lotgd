<?php

declare(strict_types=1);

namespace Lotgd\Tests\Encounter;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Lotgd\Action;
use Lotgd\Action\ActionResult;
use Lotgd\Encounter;
use Lotgd\Grant;
use Lotgd\Realtime;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Live encounters (docs/ReactiveCore.md, Phase 7) against the stub
 * connection: a challenge to a player online, the answer, withdrawing and
 * leaving -- each a guarded action -- and what is refused.
 */
final class EncounterTest extends TestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';
    private const PLACE = '/SELECT p\.place FROM presence p/';
    private const OPPONENT_NAME = '/SELECT name FROM accounts WHERE acctid = :acctid AND locked = 0/';
    private const LOCKED = '/FROM encounters WHERE id = :id FOR UPDATE/';
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
        $this->useConnection(new DoctrineConnection());

        $GLOBALS['session'] = [
            'loggedin' => true,
            'allowednavs' => [],
            'bufflist' => [],
            'user' => ['acctid' => 5, 'name' => 'Five', 'login' => 'five', 'allowednavs' => serialize([]),
                'bufflist' => serialize([]), 'alive' => 1, 'version' => 3],
        ];
        $GLOBALS['baseaccount'] = $GLOBALS['session']['user'];
    }

    protected function tearDown(): void
    {
        Action::resetState();
        Grant::resetState();
        Realtime::resetState();
        Encounter::resetState();
        Database::resetDoctrineConnection();
        unset($GLOBALS['session'], $GLOBALS['baseaccount']);
    }

    public function testEncountersNeedTheirSettingRealtimeAndGrants(): void
    {
        self::assertTrue(Encounter::enabled());

        foreach (['live_encounters', 'realtime_enabled', 'grants_enabled', 'guarded_actions'] as $off) {
            Encounter::resetState();
            Grant::resetState();
            Realtime::resetState();
            Settings::setInstance(new DummySettings([$off => 0] + $this->settings()));
            self::assertFalse(Encounter::enabled(), $off);
        }
    }

    public function testAnEncounterChangesOnlyInsideAGuardedAction(): void
    {
        $this->expectException(\LogicException::class);
        Encounter::challenge($GLOBALS['session']['user'], 2);
    }

    public function testAChallengeTakesBothSlotsInIdOrderOffersTheOpponentAGrantAndAnnouncesIt(): void
    {
        $result = $this->guarded(static fn (): array => Encounter::challenge($GLOBALS['session']['user'], 2));

        self::assertSame(ActionResult::DONE, $result->status, (string) $result->reason);
        self::assertSame(['id' => 1, 'opponent' => 2, 'name' => 'Two'], $result->data);
        $encounter = $this->statement('INSERT INTO encounters');
        self::assertSame(['challenger' => 5, 'opponent' => 2, 'state' => 'pending'], array_intersect_key($encounter['params'], ['challenger' => 1, 'opponent' => 1, 'state' => 1]));
        self::assertSame(
            [2, 5],
            array_map(static fn (array $s): int => (int) $s['params']['acctid'], $this->statements('INSERT INTO encounter_slots')),
            'The lower id first, whoever challenges: crossing challenges queue on the same row.'
        );
        $grant = $this->statement('INSERT INTO granted_navs');
        self::assertSame(2, $grant['params']['acctid']);
        self::assertSame('encounter-1', $grant['params']['target_key']);
        self::assertSame('encounter.php?op=view&id=1&grant=1', $this->statement('UPDATE granted_navs SET link')['params']['link']);
        self::assertSame(1, $this->statement('UPDATE encounters SET grant_id')['params']['grant']);
        self::assertSame(['user-2', 'user-2', 'user-5', 'encounter-1'], $this->channels(), 'The grant, then both players and the encounter.');
        self::assertContains('COMMIT', $this->connection->queries);
    }

    /**
     * @return iterable<string,array{0:int,1:?string,2:string}>
     */
    public static function refusedChallenges(): iterable
    {
        yield 'oneself' => [5, 'village', 'no-opponent'];
        yield 'offline' => [2, null, 'opponent-away'];
        yield 'in the forest' => [2, 'forest', 'opponent-away'];
        yield 'among the shades' => [2, 'shades', 'opponent-away'];
    }

    #[DataProvider('refusedChallenges')]
    public function testAChallengeToSomeoneNotAroundIsRefusedAndWritesNothing(int $opponent, ?string $place, string $reason): void
    {
        $this->connection->queryResultsByPattern[self::PLACE] = $place === null ? [] : [['place' => $place]];

        $result = $this->guarded(static fn (): array => Encounter::challenge($GLOBALS['session']['user'], $opponent));

        self::assertSame(ActionResult::REJECTED, $result->status);
        self::assertSame($reason, $result->reason);
        self::assertSame([], $this->statements('INSERT INTO encounters'));
        self::assertContains('ROLLBACK', $this->connection->queries);
    }

    /**
     * @return iterable<string,array{0:int,1:string}>
     */
    public static function takenSlots(): iterable
    {
        yield 'the opponent is in an encounter' => [2, 'opponent-busy'];
        yield 'the challenger is' => [5, 'already-in-encounter'];
    }

    #[DataProvider('takenSlots')]
    public function testAPlayerAlreadyInAnEncounterCannotBeInASecond(int $taken, string $reason): void
    {
        $this->useConnection(new class ($taken) extends DoctrineConnection {
            public function __construct(private readonly int $taken)
            {
            }

            public function executeStatement(string $sql, array $params = [], array $types = []): int
            {
                if (str_starts_with($sql, 'INSERT INTO encounter_slots') && (int) $params['acctid'] === $this->taken) {
                    $this->executeStatements[] = ['sql' => $sql, 'params' => $params];
                    throw new UniqueConstraintViolationException(new class ('Duplicate entry') extends \Exception implements DriverException {
                        public function getSQLState(): ?string
                        {
                            return '23000';
                        }
                    }, null);
                }

                return parent::executeStatement($sql, $params, $types);
            }
        });

        $result = $this->guarded(static fn (): array => Encounter::challenge($GLOBALS['session']['user'], 2));

        self::assertSame(ActionResult::REJECTED, $result->status);
        self::assertSame($reason, $result->reason);
        self::assertSame([], $this->statements('INSERT INTO granted_navs'), 'Nothing offered: the whole challenge rolls back.');
        self::assertContains('ROLLBACK', $this->connection->queries);
    }

    public function testAStaleSlotIsFreedByItsOwnRowNotByARange(): void
    {
        $this->connection->queryResultsByPattern['/SELECT s\.acctid, s\.encounter_id FROM encounter_slots/'] = [['acctid' => 2, 'encounter_id' => 7]];

        $this->guarded(static fn (): array => Encounter::challenge($GLOBALS['session']['user'], 2));

        $delete = $this->statement('DELETE FROM encounter_slots');
        self::assertSame('DELETE FROM encounter_slots WHERE acctid = :acctid AND encounter_id = :id', $delete['sql']);
        self::assertSame(['acctid' => 2, 'id' => 7], $delete['params']);
    }

    public function testTheOpponentAcceptsUsingTheGrantAndBothKeepTheirSlots(): void
    {
        $this->encounter(['challenger' => 2, 'opponent' => 5]);

        $result = $this->guarded(static fn (): array => Encounter::answer($GLOBALS['session']['user'], 1, true));

        self::assertSame(ActionResult::DONE, $result->status, (string) $result->reason);
        self::assertSame('accepted', $result->data['state']);
        self::assertSame('encounter-1', $this->statement('UPDATE granted_navs SET used_at')['params']['key']);
        $update = $this->statement('UPDATE encounters SET state');
        self::assertSame('accepted', $update['params']['state']);
        self::assertArrayHasKey('expires', $update['params'], 'An accepted encounter has a time of its own.');
        self::assertSame([], $this->statements('DELETE FROM encounter_slots'));
        self::assertContains('encounter-1', $this->channels());
    }

    public function testDecliningFreesBothSlots(): void
    {
        $this->encounter(['challenger' => 2, 'opponent' => 5]);

        $result = $this->guarded(static fn (): array => Encounter::answer($GLOBALS['session']['user'], 1, false));

        self::assertSame('declined', $result->data['state']);
        self::assertSame(['id' => 1], $this->statement('DELETE FROM encounter_slots')['params']);
    }

    /**
     * @return iterable<string,array{0:array<string,mixed>,1:array<string,mixed>,2:string}>
     */
    public static function refusedAnswers(): iterable
    {
        yield 'someone else\'s challenge' => [['challenger' => 2, 'opponent' => 3], [], 'no-encounter'];
        yield 'withdrawn: the grant is closed' => [['challenger' => 2, 'opponent' => 5, 'state' => 'cancelled'], ['used_at' => '2026-10-21 10:00:00'], 'grant-used'];
        yield 'past its time' => [['challenger' => 2, 'opponent' => 5], ['expires_at' => '2000-01-01 00:00:00'], 'grant-expired'];
    }

    /**
     * @param array<string,mixed> $encounter
     * @param array<string,mixed> $grant
     */
    #[DataProvider('refusedAnswers')]
    public function testAnAnswerToAChallengeNotOpenToThePlayerIsRefused(array $encounter, array $grant, string $reason): void
    {
        $this->encounter($encounter, $grant);

        $result = $this->guarded(static fn (): array => Encounter::answer($GLOBALS['session']['user'], 1, true));

        self::assertSame(ActionResult::REJECTED, $result->status);
        self::assertSame($reason, $result->reason);
        self::assertSame([], $this->statements('UPDATE encounters SET state'));
    }

    public function testTheChallengerWithdrawsAndTheOfferCloses(): void
    {
        $this->encounter(['challenger' => 5, 'opponent' => 2]);
        $this->connection->queryResultsByPattern['/WHERE target_key = :key AND used_at IS NULL$/'] = [['id' => 1, 'acctid' => 2, 'used_at' => null]];

        $result = $this->guarded(static fn (): array => Encounter::cancel($GLOBALS['session']['user'], 1));

        self::assertSame('cancelled', $result->data['state']);
        self::assertSame('cancelled', $this->statement('UPDATE encounters SET state')['params']['state']);
        self::assertSame('void', $this->statement('UPDATE granted_navs SET used_at')['params']['action_id']);
        self::assertNotSame([], $this->statements('DELETE FROM encounter_slots'));
    }

    public function testOnlyTheChallengerWithdraws(): void
    {
        $this->encounter(['challenger' => 2, 'opponent' => 5]);

        self::assertSame('no-encounter', $this->guarded(static fn (): array => Encounter::cancel($GLOBALS['session']['user'], 1))->reason);
    }

    public function testEitherPlayerLeavesAnAcceptedEncounter(): void
    {
        $this->encounter(['challenger' => 2, 'opponent' => 5, 'state' => 'accepted']);

        $result = $this->guarded(static fn (): array => Encounter::leave($GLOBALS['session']['user'], 1));

        self::assertSame('ended', $result->data['state']);
        self::assertNotSame([], $this->statements('DELETE FROM encounter_slots'));
    }

    public function testAChallengeNotYetAnsweredCannotBeLeft(): void
    {
        $this->encounter(['challenger' => 2, 'opponent' => 5]);

        self::assertSame('encounter-pending', $this->guarded(static fn (): array => Encounter::leave($GLOBALS['session']['user'], 1))->reason);
    }

    public function testOnlyItsPlayersSeeAnEncounterAndAnOpenOnePastItsTimeShowsAsExpired(): void
    {
        $this->connection->queryResultsByPattern['/FROM encounters WHERE id = :id$/'] = [$this->row(['challenger' => 2, 'opponent' => 5, 'expires_at' => '2000-01-01 00:00:00'])];

        self::assertSame('expired', Encounter::find(1, 5)['state'] ?? null);
        self::assertSame('expired', Encounter::find(1, 2)['state'] ?? null);
        self::assertNull(Encounter::find(1, 3));
    }

    public function testTheRegionLinksToTheEncounterOnlyWhenThePageOffersIt(): void
    {
        $this->connection->queryResultsByPattern['/WHERE challenger = :acctid OR opponent = :acctid ORDER BY id DESC LIMIT 1/'] = [$this->row(['challenger' => 5, 'opponent' => 2, 'state' => 'accepted'])];
        $this->connection->queryResultsByPattern['/SELECT name FROM accounts WHERE acctid = :acctid$/'] = [['name' => 'Two']];

        self::assertStringNotContainsString('<a ', Encounter::regionHtml(5));

        $GLOBALS['session']['allowednavs'] = ['encounter.php?op=view&id=1&c=4-101010' => true, 'encounter.php?op=view&id=10&c=4-101010' => true];
        $html = Encounter::regionHtml(5);
        self::assertStringContainsString("href='encounter.php?op=view&amp;id=1&amp;c=4-101010'", $html);
        self::assertStringContainsString('accepted your challenge', $html);
    }

    /**
     * @param callable():array<string,mixed> $fn
     */
    private function guarded(callable $fn): ActionResult
    {
        return Action::run('encounter.test', self::ID, [], $fn);
    }

    private function useConnection(DoctrineConnection $connection): void
    {
        $this->connection = $connection;
        Database::setDoctrineConnection($connection);
        Action::resetState();
        Realtime::resetState();
        Grant::resetState();
        Encounter::resetState();
        Settings::setInstance(new DummySettings($this->settings()));
        $connection->queryResultsByPattern = [
            '/SELECT version FROM accounts WHERE acctid = :acctid FOR UPDATE/' => [['version' => 3]],
            '/SELECT next_seq FROM relay_sequence/' => [['next_seq' => 1]],
            self::OPPONENT_NAME => [['name' => 'Two']],
            self::PLACE => [['place' => 'village']],
        ];
    }

    /**
     * @return array<string,int>
     */
    private function settings(): array
    {
        return ['live_encounters' => 1, 'realtime_enabled' => 1, 'grants_enabled' => 1, 'guarded_actions' => 1, 'encounter_challenge_seconds' => 120];
    }

    /**
     * @param array<string,mixed> $encounter
     * @param array<string,mixed> $grant
     */
    private function encounter(array $encounter, array $grant = []): void
    {
        $this->connection->queryResultsByPattern[self::LOCKED] = [$this->row($encounter)];
        $this->connection->queryResultsByPattern['/SELECT target_key FROM granted_navs WHERE id = :id$/'] = [['target_key' => 'encounter-1']];
        $this->connection->queryResultsByPattern[self::GROUP_LOCK] = [$grant + [
            'id' => 9,
            'acctid' => (int) ($encounter['opponent'] ?? 5),
            'action' => 'encounter.answer',
            'target' => '{"encounter":1}',
            'used_at' => null,
            'expires_at' => date('Y-m-d H:i:s', time() + 60),
        ]];
        $this->connection->queryResultsByPattern['/SELECT name FROM accounts WHERE acctid = :acctid$/'] = [['name' => 'Two']];
    }

    /**
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function row(array $override): array
    {
        return $override + [
            'id' => 1,
            'kind' => 'duel',
            'challenger' => 2,
            'opponent' => 5,
            'state' => 'pending',
            'grant_id' => 9,
            'created' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + 60),
            'changed_us' => 1,
        ];
    }

    /**
     * @return list<string>
     */
    private function channels(): array
    {
        return array_map(static fn (array $s): string => (string) $s['params']['channel'], $this->statements('INSERT INTO events'));
    }

    /**
     * @return list<array{sql:string,params:array<string,mixed>}>
     */
    private function statements(string $prefix): array
    {
        return array_values(array_filter(
            $this->connection->executeStatements,
            static fn (array $s): bool => str_starts_with((string) $s['sql'], $prefix)
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
