<?php

declare(strict_types=1);

namespace Lotgd\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guarded actions against a real MySQL/MariaDB, with real concurrency: what
 * the stub connection cannot show -- row locks, a process killed inside its
 * transaction, two connections racing for one action id.
 *
 * Opt-in. Point LOTGD_INTEGRATION_ROOT at an installed copy of this tree (its
 * dbconnect.php names a disposable MySQL or MariaDB database, migrated); the
 * test adds its own accounts and removes them again. Without it the test is
 * skipped, so CI does not need a database. See docs/ReactiveCore.md.
 */
final class GuardedActionMariaDbTest extends TestCase
{
    private string $root = '';
    private \PDO $db;
    /** @var list<int> */
    private array $accounts = [];
    private string $signals = '';

    protected function setUp(): void
    {
        $root = getenv('LOTGD_INTEGRATION_ROOT');
        if (!is_string($root) || $root === '' || !is_file($root . '/dbconnect.php')) {
            self::markTestSkipped('LOTGD_INTEGRATION_ROOT is not set to an installed game.');
        }
        $this->root = (string) realpath($root);

        $config = require $this->root . '/dbconnect.php';
        [$host, $port] = array_pad(explode(':', (string) $config['DB_HOST'], 2), 2, '3306');
        $this->db = new \PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $config['DB_NAME']),
            (string) $config['DB_USER'],
            (string) $config['DB_PASS'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $this->signals = sys_get_temp_dir() . '/lotgd-guarded-' . bin2hex(random_bytes(4));
        mkdir($this->signals);
    }

    protected function tearDown(): void
    {
        if ($this->accounts !== []) {
            $ids = implode(',', $this->accounts);
            $this->db->exec("DELETE FROM action_log WHERE acctid IN ($ids)");
            $this->db->exec("DELETE FROM accounts WHERE acctid IN ($ids)");
        }
        if ($this->signals !== '' && is_dir($this->signals)) {
            array_map('unlink', (array) glob($this->signals . '/*'));
            rmdir($this->signals);
        }
    }

    public function testTwoSendersCreditingOneRecipientAtOnceBothArrive(): void
    {
        $bob = $this->account('bob', gold: 0, bank: 100);
        $ann = $this->account('ann', gold: 50);
        $cid = $this->account('cid', gold: 50);

        $answers = $this->together(
            ['acctid' => $ann, 'booking' => 'transfer', 'to' => $bob, 'amount' => 10, 'hold' => 0.5],
            ['acctid' => $cid, 'booking' => 'transfer', 'to' => $bob, 'amount' => 15, 'hold' => 0.5]
        );

        self::assertSame(['done', 'done'], array_column($answers, 'status'), json_encode($answers));
        self::assertSame(125, $this->column($bob, 'goldinbank'));
        self::assertSame(2, $this->column($bob, 'version'), 'Each credit moves the recipient on.');
        self::assertSame(40, $this->column($ann, 'gold'));
        self::assertSame(35, $this->column($cid, 'gold'));
    }

    public function testTwoPlayersCreditingEachOtherAtOnceLoseNoGold(): void
    {
        // Each holds their own row and wants the other's: a real deadlock.
        // The database rolls one back; that player sees a conflict, never an
        // error, and no gold appears or vanishes.
        $ann = $this->account('ann', gold: 50, bank: 0);
        $bob = $this->account('bob', gold: 50, bank: 0);

        $answers = $this->together(
            ['acctid' => $ann, 'booking' => 'transfer', 'to' => $bob, 'amount' => 10, 'hold' => 0.3, 'holdBefore' => true],
            ['acctid' => $bob, 'booking' => 'transfer', 'to' => $ann, 'amount' => 10, 'hold' => 0.3, 'holdBefore' => true]
        );

        foreach ($answers as $answer) {
            self::assertContains($answer['status'], ['done', 'conflict'], json_encode($answers));
        }
        self::assertContains('done', array_column($answers, 'status'), json_encode($answers));
        $total = $this->column($ann, 'gold') + $this->column($ann, 'goldinbank') + $this->column($bob, 'gold') + $this->column($bob, 'goldinbank');
        self::assertSame(100, $total);
    }

    public function testARecipientsStalePageCannotBookOverATransfer(): void
    {
        $bob = $this->account('bob', gold: 20, bank: 100);
        $ann = $this->account('ann', gold: 50);

        // Bob's page is read, then Ann's transfer lands, then Bob deposits.
        $stale = $this->start(['acctid' => $bob, 'booking' => 'deposit', 'amount' => 5, 'loaded' => 'bob-loaded', 'go' => 'go']);
        $this->waitFor('bob-loaded');
        $transfer = $this->finish($this->start(['acctid' => $ann, 'booking' => 'transfer', 'to' => $bob, 'amount' => 10]));
        $this->signal('go');
        $deposit = $this->finish($stale);

        self::assertSame('done', $transfer['status'], json_encode($transfer));
        self::assertSame('conflict', $deposit['status'], json_encode($deposit));
        self::assertSame(110, $this->column($bob, 'goldinbank'), 'The transfer stays booked.');
        self::assertSame(20, $this->column($bob, 'gold'));
    }

    public function testWithoutAGuardTheSameStalePageLosesTheTransfer(): void
    {
        // The control for the test above: today's page-end save of a page
        // read before the transfer writes its old balance over the credit.
        $bob = $this->account('bob', gold: 20, bank: 100);
        $ann = $this->account('ann', gold: 50);

        $stale = $this->start(['acctid' => $bob, 'mode' => 'classic', 'booking' => 'deposit', 'amount' => 5, 'loaded' => 'bob-loaded', 'go' => 'go']);
        $this->waitFor('bob-loaded');
        $this->finish($this->start(['acctid' => $ann, 'booking' => 'transfer', 'to' => $bob, 'amount' => 10]));
        $this->signal('go');
        $this->finish($stale);

        self::assertSame(105, $this->column($bob, 'goldinbank'), 'The ten gold Ann sent are gone.');
    }

    public function testAnActionKilledInsideItsTransactionLeavesNothingAndItsRetryBooksOnce(): void
    {
        $dan = $this->account('dan', gold: 100, bank: 0);
        $id = bin2hex(random_bytes(16));

        $process = $this->start(['acctid' => $dan, 'booking' => 'deposit', 'amount' => 30, 'action' => $id, 'hold' => 30, 'holding' => 'holding']);
        $this->waitFor('holding');
        $status = proc_get_status($process['handle']);
        posix_kill($status['pid'], SIGKILL);
        $this->finish($process);

        self::assertSame(100, $this->column($dan, 'gold'), 'The written but uncommitted deposit is rolled back.');
        self::assertSame(0, $this->logRows($dan), 'No log row: the same form may run again.');

        $retry = $this->finish($this->start(['acctid' => $dan, 'booking' => 'deposit', 'amount' => 30, 'action' => $id]));
        $again = $this->finish($this->start(['acctid' => $dan, 'booking' => 'deposit', 'amount' => 30, 'action' => $id]));

        self::assertSame('done', $retry['status'], json_encode($retry));
        self::assertSame('replayed', $again['status'], json_encode($again));
        self::assertSame(70, $this->column($dan, 'gold'));
        self::assertSame(30, $this->column($dan, 'goldinbank'));
        self::assertSame(1, $this->logRows($dan));
    }

    public function testTheSameFormSentFromTwoConnectionsAtOnceBooksOnce(): void
    {
        $eve = $this->account('eve', gold: 100, bank: 0);
        $id = bin2hex(random_bytes(16));

        $answers = $this->together(
            ['acctid' => $eve, 'booking' => 'deposit', 'amount' => 30, 'action' => $id, 'hold' => 0.5],
            ['acctid' => $eve, 'booking' => 'deposit', 'amount' => 30, 'action' => $id, 'hold' => 0.5]
        );

        $statuses = array_column($answers, 'status');
        sort($statuses);
        self::assertSame(['done', 'replayed'], $statuses, json_encode($answers));
        self::assertSame(70, $this->column($eve, 'gold'));
        self::assertSame(30, $this->column($eve, 'goldinbank'));
    }

    /**
     * A test account, copied from the first account of the installation.
     */
    private function account(string $name, int $gold = 0, int $bank = 0): int
    {
        $row = $this->db->query('SELECT * FROM accounts ORDER BY acctid LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'The installation needs at least one account to copy.');
        unset($row['acctid']);
        $login = $name . bin2hex(random_bytes(3));
        $row = ['login' => $login, 'name' => $login, 'gold' => $gold, 'goldinbank' => $bank, 'version' => 0,
            'transferredtoday' => 0, 'locked' => 0, 'emailaddress' => $login . '@example.invalid'] + $row;
        $columns = array_keys($row);
        $statement = $this->db->prepare(
            'INSERT INTO accounts (`' . implode('`, `', $columns) . '`) VALUES (:' . implode(', :', $columns) . ')'
        );
        $statement->execute($row);
        $acctid = (int) $this->db->lastInsertId();
        $this->accounts[] = $acctid;

        return $acctid;
    }

    private function column(int $acctid, string $column): int
    {
        $statement = $this->db->prepare("SELECT `$column` FROM accounts WHERE acctid = ?");
        $statement->execute([$acctid]);

        return (int) $statement->fetchColumn();
    }

    private function logRows(int $acctid): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM action_log WHERE acctid = ?');
        $statement->execute([$acctid]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Start every worker, let them read their accounts, then let them all book at once.
     *
     * @param array<string,mixed> ...$specs
     * @return list<array{status:string,reason:string,data:array<string,mixed>}>
     */
    private function together(array ...$specs): array
    {
        $processes = [];
        foreach ($specs as $index => $spec) {
            $processes[] = $this->start($spec + ['loaded' => "loaded-$index", 'go' => 'go']);
        }
        foreach (array_keys($specs) as $index) {
            $this->waitFor("loaded-$index");
        }
        $this->signal('go');

        return array_map(fn (array $process): array => $this->finish($process), $processes);
    }

    /**
     * @param array<string,mixed> $spec
     * @return array{handle:resource,stdout:resource}
     */
    private function start(array $spec): array
    {
        foreach (['loaded', 'go', 'holding'] as $file) {
            if (isset($spec[$file])) {
                $spec[$file] = $this->signals . '/' . $spec[$file];
            }
        }
        $spec += ['root' => $this->root, 'mode' => 'guarded', 'action' => bin2hex(random_bytes(16)), 'to' => 0];

        $handle = proc_open(
            [PHP_BINARY, __DIR__ . '/guarded_action_worker.php', json_encode($spec, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($handle);

        return ['handle' => $handle, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    /**
     * @param array{handle:resource,stdout:resource,stderr:resource} $process
     * @return array{status:string,reason:string,data:array<string,mixed>}
     */
    private function finish(array $process): array
    {
        $out = (string) stream_get_contents($process['stdout']);
        $err = (string) stream_get_contents($process['stderr']);
        proc_close($process['handle']);

        $answer = json_decode(trim($out), true);

        return is_array($answer) ? $answer : ['status' => 'died', 'reason' => trim($out . "\n" . $err), 'data' => []];
    }

    private function waitFor(string $file): void
    {
        $until = microtime(true) + 30;
        while (!file_exists($this->signals . '/' . $file)) {
            self::assertLessThan($until, microtime(true), "A worker never got to $file.");
            usleep(10000);
        }
    }

    private function signal(string $file): void
    {
        touch($this->signals . '/' . $file);
    }
}
