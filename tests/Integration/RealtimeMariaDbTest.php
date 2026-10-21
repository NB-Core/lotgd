<?php

declare(strict_types=1);

namespace Lotgd\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The realtime relay against a real MySQL/MariaDB (docs/ReactiveCore.md,
 * Phase 4, acceptance criteria 5 and 7): commits in the "wrong" order, relays
 * running at once, a relay killed with the sequence locked.
 *
 * Opt-in like GuardedActionMariaDbTest: LOTGD_INTEGRATION_ROOT names an
 * installed, migrated copy of this tree on a disposable database. The test
 * uses channels of its own and removes their rows again.
 */
final class RealtimeMariaDbTest extends TestCase
{
    private string $root = '';
    private \PDO $db;
    private string $signals = '';
    private string $channel = '';

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
        $this->db->exec("INSERT INTO settings (setting, value) VALUES ('realtime_enabled', '1') ON DUPLICATE KEY UPDATE value = '1'");
        // Leave nothing pending from elsewhere, so this test's numbers are its own.
        $this->work(['mode' => 'drain']);

        $this->channel = 'test-' . bin2hex(random_bytes(4));
        $this->signals = sys_get_temp_dir() . '/lotgd-realtime-' . bin2hex(random_bytes(4));
        mkdir($this->signals);
    }

    protected function tearDown(): void
    {
        if ($this->channel !== '') {
            $this->db->prepare('DELETE FROM events WHERE channel LIKE ?')->execute([$this->channel . '%']);
            $this->db->prepare('DELETE FROM event_deliveries WHERE channel LIKE ?')->execute([$this->channel . '%']);
        }
        if ($this->signals !== '' && is_dir($this->signals)) {
            array_map('unlink', (array) glob($this->signals . '/*'));
            rmdir($this->signals);
        }
    }

    public function testAnEventCommittedLateIsStillDeliveredAndNoNumberIsSkippedOrRepeated(): void
    {
        $start = $this->nextSeq();

        // Event "10" is written first and held open; event "11" commits and
        // relays meanwhile; then "10" commits and the next relay takes it.
        $held = $this->start(['mode' => 'hold-event', 'channel' => $this->channel . '-a', 'holding' => 'holding', 'go' => 'go']);
        $this->waitFor('holding');
        $notify = $this->finish($this->start(['mode' => 'notify', 'channel' => $this->channel . '-b']));
        self::assertTrue($notify['done'] ?? false, json_encode($notify));
        self::assertLessThan(5, $notify['seconds'], 'The relay does not wait for a transaction that is still open.');

        $this->signal('go');
        $this->finish($held);
        $this->work(['mode' => 'drain']);

        $rows = $this->deliveries();
        self::assertSame([$this->channel . '-b', $this->channel . '-a'], array_column($rows, 'channel'), 'Delivered in the order relayed, the late commit included.');
        self::assertSame([$start, $start + 1], array_map('intval', array_column($rows, 'seq')));
        self::assertSame(0, $this->pending());
    }

    public function testRelaysRunningAtOnceHandOutEachNumberOnce(): void
    {
        $start = $this->nextSeq();
        $insert = $this->db->prepare("INSERT INTO events (event_id, channel, type, payload, created) VALUES (?, ?, 'mail', '{}', NOW())");
        for ($i = 0; $i < 60; $i++) {
            $insert->execute([bin2hex(random_bytes(16)), $this->channel . '-' . ($i % 3)]);
        }

        $workers = [];
        for ($w = 0; $w < 3; $w++) {
            $workers[] = $this->start(['mode' => 'drain', 'go' => 'go']);
        }
        $this->signal('go');
        $relayed = array_sum(array_map(fn (array $p): int => (int) ($this->finish($p)['relayed'] ?? -1000), $workers));

        $rows = $this->deliveries();
        self::assertSame(60, $relayed, 'Every event relayed exactly once across the relays.');
        self::assertSame(range($start, $start + 59), array_map('intval', array_column($rows, 'seq')), 'Consecutive, no gaps, no repeats.');
        self::assertCount(60, array_unique(array_column($rows, 'event_id')));
        self::assertSame($start + 60, $this->nextSeq());
    }

    public function testARelayKilledWithTheSequenceLockedLosesNothing(): void
    {
        $start = $this->nextSeq();
        $this->db->prepare("INSERT INTO events (event_id, channel, type, payload, created) VALUES (?, ?, 'mail', '{}', NOW())")
            ->execute([bin2hex(random_bytes(16)), $this->channel]);

        $crash = $this->start(['mode' => 'crash-relay', 'holding' => 'holding']);
        $this->waitFor('holding');
        posix_kill(proc_get_status($crash['handle'])['pid'], SIGKILL);
        $this->finish($crash);

        self::assertSame([], $this->deliveries(), 'The killed relay\'s delivery rolled back with it.');
        self::assertSame(1, $this->pending());

        $this->work(['mode' => 'drain']);
        $rows = $this->deliveries();
        self::assertCount(1, $rows);
        self::assertSame($start, (int) $rows[0]['seq'], 'The number the killed relay had taken is handed out again.');
    }

    public function testAnEventAfterThePageReadItsCursorComesAfterTheCursor(): void
    {
        $cursor = (int) $this->db->query('SELECT COALESCE(MAX(seq), 0) FROM event_deliveries')->fetchColumn();

        $this->finish($this->start(['mode' => 'notify', 'channel' => $this->channel]));

        $rows = $this->deliveries();
        self::assertCount(1, $rows);
        self::assertGreaterThan($cursor, (int) $rows[0]['seq']);
    }

    /**
     * @return list<array<string,string>>
     */
    private function deliveries(): array
    {
        $statement = $this->db->prepare('SELECT seq, event_id, channel FROM event_deliveries WHERE channel LIKE ? ORDER BY seq');
        $statement->execute([$this->channel . '%']);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function pending(): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM events WHERE channel LIKE ? AND relayed_at IS NULL');
        $statement->execute([$this->channel . '%']);

        return (int) $statement->fetchColumn();
    }

    private function nextSeq(): int
    {
        return (int) $this->db->query('SELECT next_seq FROM relay_sequence WHERE id = 1')->fetchColumn();
    }

    /**
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    private function work(array $spec): array
    {
        return $this->finish($this->start($spec));
    }

    /**
     * @param array<string,mixed> $spec
     * @return array{handle:resource,stdout:resource,stderr:resource}
     */
    private function start(array $spec): array
    {
        foreach (['holding', 'go'] as $file) {
            if (isset($spec[$file])) {
                $spec[$file] = $this->signals . '/' . $spec[$file];
            }
        }
        $spec += ['root' => $this->root, 'channel' => $this->channel];
        $handle = proc_open(
            [PHP_BINARY, __DIR__ . '/realtime_worker.php', json_encode($spec, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($handle);

        return ['handle' => $handle, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    /**
     * @param array{handle:resource,stdout:resource,stderr:resource} $process
     * @return array<string,mixed>
     */
    private function finish(array $process): array
    {
        $out = (string) stream_get_contents($process['stdout']);
        $err = (string) stream_get_contents($process['stderr']);
        proc_close($process['handle']);
        $answer = json_decode(trim($out), true);

        return is_array($answer) ? $answer : ['error' => trim($out . "\n" . $err)];
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
