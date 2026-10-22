<?php

declare(strict_types=1);

namespace Lotgd\Tests\Encounter;

use Lotgd\Action;
use Lotgd\Action\ActionResult;
use Lotgd\Encounter;
use Lotgd\Encounter\Duel;
use Lotgd\Encounter\RoundDuel;
use Lotgd\Grant;
use Lotgd\Realtime;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\ScriptedRandom;
use PHPUnit\Framework\TestCase;

/**
 * A live duel's steps against the stub connection (docs/ReactiveCore.md,
 * Phase 7): a choice, the second choice that resolves the round, a round
 * whose time ran out, giving up, and what the end pays -- nothing for a
 * friendly duel, the PvP stakes for a serious one.
 */
final class DuelTest extends TestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';
    private const LOCKED = '/FROM encounters WHERE id = :id FOR UPDATE/';
    private const ROUND = '/FROM encounter_rounds WHERE encounter_id = :id AND round = :round FOR UPDATE/';

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
        Encounter::resetState();
        Settings::setInstance(new DummySettings([
            'live_encounters' => 1, 'realtime_enabled' => 1, 'grants_enabled' => 1, 'guarded_actions' => 1,
            'duel_round_seconds' => 20, 'duel_max_rounds' => 30,
            'pvpattgain' => 10, 'pvpdeflose' => 5, 'pvpdefgain' => 10, 'pvpattlose' => 15,
        ]));

        // Player 5 is the opponent of player 2.
        $GLOBALS['session'] = [
            'loggedin' => true,
            'allowednavs' => [],
            'bufflist' => [],
            'user' => ['acctid' => 5, 'name' => 'Bo', 'login' => 'bo', 'allowednavs' => serialize([]),
                'bufflist' => serialize([]), 'alive' => 1, 'version' => 3, 'level' => 5, 'gold' => 100, 'experience' => 1000,
                'hitpoints' => 30],
        ];
        $GLOBALS['baseaccount'] = $GLOBALS['session']['user'];
        $this->connection->queryResultsByPattern = [
            '/SELECT version FROM accounts WHERE acctid = :acctid FOR UPDATE/' => [['version' => 3]],
            '/SELECT next_seq FROM relay_sequence/' => [['next_seq' => 1]],
        ];
        $this->encounter();
        $this->round(['deadline' => date('Y-m-d H:i:s', time() + 20)]);
    }

    protected function tearDown(): void
    {
        Duel::useRandom(null);
        Action::resetState();
        Grant::resetState();
        Realtime::resetState();
        Encounter::resetState();
        Database::resetDoctrineConnection();
        unset($GLOBALS['session'], $GLOBALS['baseaccount']);
    }

    public function testTheFirstChoiceIsStoredAndShownAsMadeButNotWhat(): void
    {
        $result = $this->guarded(static fn (): array => Duel::choose($GLOBALS['session']['user'], 1, 1, 'guard'));

        self::assertSame('chosen', $result->data['state']);
        $stored = $this->statement('UPDATE encounter_rounds SET opponent_choice');
        self::assertSame('guard', $stored['params']['choice']);
        self::assertNotSame([], $this->statements('UPDATE encounters SET changed_us'), 'The other page fetches the region again.');
        self::assertSame([], $this->statements('UPDATE encounter_rounds SET resolved_at'));
        foreach ($this->statements('INSERT INTO events') as $event) {
            self::assertStringNotContainsString('guard', (string) $event['params']['payload'], 'Announcements carry no choice.');
        }
    }

    public function testTheSecondChoiceResolvesTheRoundAndOpensTheNext(): void
    {
        $this->round(['challenger_choice' => 'attack', 'deadline' => date('Y-m-d H:i:s', time() + 20)]);
        Duel::useRandom(new ScriptedRandom([0, 5], [8, 3, 2, 6]));

        $result = $this->guarded(static fn (): array => Duel::choose($GLOBALS['session']['user'], 1, 1, 'attack'));

        self::assertSame('resolved', $result->data['state'], (string) $result->reason);
        self::assertNotSame([], $this->statements('UPDATE encounter_rounds SET resolved_at'));
        $data = json_decode((string) $this->statement('UPDATE encounters SET data')['params']['data'], true);
        self::assertSame(2, $data['round']);
        self::assertSame(2, $this->statement('INSERT INTO encounter_rounds')['params']['round']);
    }

    public function testAChoiceCannotBeChangedButRepeatingItIsFine(): void
    {
        $this->round(['opponent_choice' => 'guard', 'deadline' => date('Y-m-d H:i:s', time() + 20)]);

        self::assertSame('chosen', $this->guarded(static fn (): array => Duel::choose($GLOBALS['session']['user'], 1, 1, 'guard'))->data['state']);
        self::assertSame('duel-chosen', $this->guarded(static fn (): array => Duel::choose($GLOBALS['session']['user'], 1, 1, 'attack'))->reason);
    }

    public function testAChoiceForAnotherRoundOrSomeoneElsesDuelIsRefused(): void
    {
        self::assertSame('duel-round-over', $this->guarded(static fn (): array => Duel::choose($GLOBALS['session']['user'], 1, 2, 'attack'))->reason);
        self::assertSame('duel-choice', $this->guarded(static fn (): array => Duel::choose($GLOBALS['session']['user'], 1, 1, 'dance'))->reason);

        $this->encounter(['challenger' => 7, 'opponent' => 8]);
        self::assertSame('no-encounter', $this->guarded(static fn (): array => Duel::choose($GLOBALS['session']['user'], 1, 1, 'attack'))->reason);
    }

    public function testARoundIsResolvedOnlyOnceItsTimeIsUp(): void
    {
        self::assertSame('duel-not-yet', $this->guarded(static fn (): array => Duel::resolve($GLOBALS['session']['user'], 1, 1))->reason);

        $this->round(['opponent_choice' => 'guard', 'deadline' => date('Y-m-d H:i:s', time() - 1)]);
        Duel::useRandom(new ScriptedRandom([0, 5], [2, 3, 2, 6]));
        $result = $this->guarded(static fn (): array => Duel::resolve($GLOBALS['session']['user'], 1, 1));

        self::assertSame('resolved', $result->data['state'], (string) $result->reason);
        $round = json_decode((string) $this->statement('UPDATE encounter_rounds SET resolved_at')['params']['result'], true);
        self::assertSame([2], $round['auto'], 'The challenger did not choose and attacks.');
    }

    public function testARoundTheOtherPageResolvedFirstIsLeftAsItIs(): void
    {
        $this->round(['resolved_at' => date('Y-m-d H:i:s'), 'deadline' => date('Y-m-d H:i:s', time() - 1)]);

        $result = $this->guarded(static fn (): array => Duel::resolve($GLOBALS['session']['user'], 1, 1));

        self::assertSame('resolved', $result->data['state']);
        self::assertSame([], $this->statements('UPDATE encounter_rounds'));
    }

    public function testGivingUpInAFriendlyDuelEndsItAndChangesNoAccount(): void
    {
        $result = $this->guarded(static fn (): array => Duel::choose($GLOBALS['session']['user'], 1, 1, 'surrender'));

        self::assertSame('ended', $result->data['state'], (string) $result->reason);
        self::assertSame('ended', $this->statement('UPDATE encounters SET state')['params']['state']);
        self::assertNotSame([], $this->statements('DELETE FROM encounter_slots'));
        self::assertSame([], $this->statements('UPDATE accounts'));
        self::assertSame([], $this->statements('INSERT INTO mail'));
        self::assertSame(100, $GLOBALS['session']['user']['gold']);
        $data = json_decode((string) $this->statement('UPDATE encounters SET data')['params']['data'], true);
        self::assertSame(['winner' => 2, 'loser' => 5, 'reason' => 'surrender', 'rounds' => 1], $data['result']);
    }

    public function testASeriousDuelPaysTheWinnerAndTheLoserDies(): void
    {
        $this->encounter(['stakes' => 'serious']);
        $this->connection->queryResultsByPattern['/SELECT level, gold, experience FROM accounts WHERE acctid = :acctid FOR UPDATE/'] = [
            ['level' => 6, 'gold' => 200, 'experience' => 2000],
        ];

        // Player 5 (the opponent, this request) gives up: the challenger
        // wins as an attacker who slew their target.
        $result = $this->guarded(static fn (): array => Duel::choose($GLOBALS['session']['user'], 1, 1, 'surrender'));

        self::assertSame(ActionResult::DONE, $result->status, (string) $result->reason);
        $winner = $this->statement('UPDATE accounts SET gold');
        self::assertSame(2, $winner['params']['acctid']);
        self::assertSame(200 + (int) round(10 * 5 * log(100)), $winner['params']['gold']);
        self::assertStringContainsString('version = version + 1', $winner['sql']);
        self::assertStringNotContainsString('alive = 0', $winner['sql']);
        // This player lost: changed through the session, with the action's version check.
        self::assertSame(0, $GLOBALS['session']['user']['alive']);
        self::assertSame(0, $GLOBALS['session']['user']['gold']);
        self::assertSame(950, $GLOBALS['session']['user']['experience']);
        self::assertCount(2, $this->statements('INSERT INTO mail'));
    }

    public function testADuelOverIsNoLongerFought(): void
    {
        $this->encounter(['state' => 'ended']);

        self::assertSame('duel-over', $this->guarded(static fn (): array => Duel::choose($GLOBALS['session']['user'], 1, 1, 'attack'))->reason);
    }

    public function testTheDuelIsDrawnWithItsChoicesForThePlayerWhoseTurnItIs(): void
    {
        $this->connection->queryResultsByPattern['/FROM encounter_rounds WHERE encounter_id = :id AND round = :round$/'] = [
            ['round' => 1, 'challenger_choice' => 'attack', 'opponent_choice' => null, 'deadline' => date('Y-m-d H:i:s', time() + 15), 'resolved_at' => null],
        ];
        $row = $this->row([]);

        $html = Duel::html($row, 5);

        self::assertStringContainsString("data-lotgd-duel='1' data-lotgd-duel-round='1'", $html);
        self::assertStringContainsString("data-lotgd-duel-choice='guard'", $html);
        self::assertStringContainsString('has chosen', $html);
        self::assertStringNotContainsString('attack.', $html, 'Not what they chose.');
        self::assertStringNotContainsString('<script', $html);
    }

    /**
     * @param callable():array<string,mixed> $fn
     */
    private function guarded(callable $fn): ActionResult
    {
        return Action::run('duel.test', self::ID, [], $fn);
    }

    /**
     * @param array<string,mixed> $override
     */
    private function encounter(array $override = []): void
    {
        $this->connection->queryResultsByPattern[self::LOCKED] = [$this->row($override)];
    }

    /**
     * @param array<string,mixed> $override
     */
    private function round(array $override): void
    {
        $this->connection->queryResultsByPattern[self::ROUND] = [$override + [
            'round' => 1, 'challenger_choice' => null, 'opponent_choice' => null, 'deadline' => date('Y-m-d H:i:s'), 'resolved_at' => null,
        ]];
    }

    /**
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function row(array $override): array
    {
        $data = (new RoundDuel())->start([
            2 => ['name' => 'Ann', 'level' => 6, 'attack' => 10, 'defense' => 10, 'hitpoints' => 30],
            5 => ['name' => 'Bo', 'level' => 5, 'attack' => 10, 'defense' => 10, 'hitpoints' => 30],
        ]);

        return $override + [
            'id' => 1, 'kind' => 'duel', 'challenger' => 2, 'opponent' => 5, 'state' => 'accepted', 'stakes' => 'friendly',
            'data' => json_encode($data), 'grant_id' => 9, 'created' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + 1800), 'changed_us' => 1,
        ];
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
