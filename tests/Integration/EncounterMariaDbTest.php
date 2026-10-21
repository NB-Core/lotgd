<?php

declare(strict_types=1);

namespace Lotgd\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Live encounters against a real MySQL/MariaDB (docs/ReactiveCore.md,
 * Phase 7): two players challenging each other at the same moment, two
 * challengers for one opponent, an answer racing a withdrawal. What the
 * stub connection cannot show is the slot rows' locks: whoever comes second
 * waits, then finds the slot taken -- no deadlock, no player in two
 * encounters.
 *
 * Opt-in like GrantMariaDbTest: LOTGD_INTEGRATION_ROOT names an installed,
 * migrated copy of this tree; without it the test is skipped.
 */
final class EncounterMariaDbTest extends TestCase
{
    private const SETTINGS = ['guarded_actions', 'grants_enabled', 'realtime_enabled', 'live_encounters'];

    private string $root = '';
    private \PDO $db;
    /** @var list<int> */
    private array $accounts = [];
    private string $signals = '';
    /** @var array<string,string|null> */
    private array $settings = [];

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
        foreach (self::SETTINGS as $setting) {
            $old = $this->db->prepare('SELECT value FROM settings WHERE setting = ?');
            $old->execute([$setting]);
            $value = $old->fetchColumn();
            $this->settings[$setting] = $value === false ? null : (string) $value;
            $this->db->prepare("INSERT INTO settings (setting, value) VALUES (?, '1') ON DUPLICATE KEY UPDATE value = '1'")->execute([$setting]);
        }

        $this->signals = sys_get_temp_dir() . '/lotgd-encounter-' . bin2hex(random_bytes(4));
        mkdir($this->signals);
    }

    protected function tearDown(): void
    {
        if ($this->accounts !== []) {
            $ids = implode(',', $this->accounts);
            $this->db->exec("DELETE FROM encounter_slots WHERE acctid IN ($ids)");
            $this->db->exec("DELETE FROM encounters WHERE challenger IN ($ids) OR opponent IN ($ids)");
            $this->db->exec("DELETE FROM presence WHERE acctid IN ($ids)");
            $this->db->exec("DELETE FROM action_log WHERE acctid IN ($ids)");
            $this->db->exec("DELETE FROM granted_navs WHERE acctid IN ($ids)");
            $this->db->exec("DELETE FROM accounts WHERE acctid IN ($ids)");
        }
        foreach ($this->settings as $setting => $value) {
            if ($value === null) {
                $this->db->prepare('DELETE FROM settings WHERE setting = ?')->execute([$setting]);
            } else {
                $this->db->prepare('UPDATE settings SET value = ? WHERE setting = ?')->execute([$value, $setting]);
            }
        }
        if ($this->signals !== '' && is_dir($this->signals)) {
            array_map('unlink', (array) glob($this->signals . '/*'));
            rmdir($this->signals);
        }
    }

    public function testTwoPlayersChallengingEachOtherAtOnceOpenOneEncounter(): void
    {
        [$ann, $bob] = [$this->player('ann'), $this->player('bob')];

        $answers = $this->together(
            ['acctid' => $ann, 'op' => 'challenge', 'target' => $bob, 'hold' => 0.5],
            ['acctid' => $bob, 'op' => 'challenge', 'target' => $ann, 'hold' => 0.5]
        );

        $statuses = array_column($answers, 'status');
        sort($statuses);
        self::assertSame(['done', 'rejected'], $statuses, (string) json_encode($answers));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM encounters WHERE challenger IN ($ann, $bob)"));
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM encounter_slots WHERE acctid IN ($ann, $bob)"));
        self::assertSame(1, $this->scalar("SELECT COUNT(DISTINCT encounter_id) FROM encounter_slots WHERE acctid IN ($ann, $bob)"));
    }

    public function testTwoChallengersForOneOpponentOpenOneEncounter(): void
    {
        [$ann, $bob, $cid] = [$this->player('ann'), $this->player('bob'), $this->player('cid')];

        $answers = $this->together(
            ['acctid' => $ann, 'op' => 'challenge', 'target' => $cid, 'hold' => 0.5],
            ['acctid' => $bob, 'op' => 'challenge', 'target' => $cid, 'hold' => 0.5]
        );

        $statuses = array_column($answers, 'status');
        sort($statuses);
        self::assertSame(['done', 'rejected'], $statuses, (string) json_encode($answers));
        self::assertContains('opponent-busy', array_column($answers, 'reason'));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM encounters WHERE opponent = $cid"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM granted_navs WHERE acctid = $cid AND used_at IS NULL"), 'One offer.');
    }

    public function testAnAnswerRacingAWithdrawalDecidesOnce(): void
    {
        [$ann, $bob] = [$this->player('ann'), $this->player('bob')];
        $challenge = $this->finish($this->start(['acctid' => $ann, 'op' => 'challenge', 'target' => $bob]));
        self::assertSame('done', $challenge['status'], (string) json_encode($challenge));
        $id = $challenge['id'];

        $answers = $this->together(
            ['acctid' => $bob, 'op' => 'answer', 'id' => $id, 'accept' => true, 'hold' => 0.5],
            ['acctid' => $ann, 'op' => 'cancel', 'id' => $id, 'hold' => 0.5]
        );

        $statuses = array_column($answers, 'status');
        sort($statuses);
        self::assertSame(['done', 'rejected'], $statuses, (string) json_encode($answers));
        $state = (string) $this->db->query("SELECT state FROM encounters WHERE id = $id")->fetchColumn();
        self::assertContains($state, ['accepted', 'cancelled']);
        self::assertSame($state === 'accepted' ? 2 : 0, $this->scalar("SELECT COUNT(*) FROM encounter_slots WHERE encounter_id = $id"));
    }

    public function testAChallengeToSomeoneInTheForestIsRefused(): void
    {
        [$ann, $bob] = [$this->player('ann'), $this->player('bob', 'forest')];

        $answer = $this->finish($this->start(['acctid' => $ann, 'op' => 'challenge', 'target' => $bob]));

        self::assertSame(['status' => 'rejected', 'reason' => 'opponent-away', 'id' => 0], $answer);
    }

    private function player(string $name, string $place = 'village'): int
    {
        $row = $this->db->query('SELECT * FROM accounts ORDER BY acctid LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'The installation needs at least one account to copy.');
        unset($row['acctid']);
        $login = $name . bin2hex(random_bytes(3));
        $row = ['login' => $login, 'name' => $login, 'version' => 0, 'locked' => 0, 'loggedin' => 1,
            'laston' => date('Y-m-d H:i:s'), 'emailaddress' => $login . '@example.invalid'] + $row;
        $columns = array_keys($row);
        $statement = $this->db->prepare(
            'INSERT INTO accounts (`' . implode('`, `', $columns) . '`) VALUES (:' . implode(', :', $columns) . ')'
        );
        $statement->execute($row);
        $acctid = (int) $this->db->lastInsertId();
        $this->accounts[] = $acctid;
        $this->db->prepare('INSERT INTO presence (acctid, place, seen, changed_us) VALUES (?, ?, NOW(), 1)')->execute([$acctid, $place]);

        return $acctid;
    }

    private function scalar(string $sql): int
    {
        return (int) $this->db->query($sql)->fetchColumn();
    }

    /**
     * @param array<string,mixed> ...$specs
     * @return list<array{status:string,reason:string,id:int}>
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
        touch($this->signals . '/go');

        return array_map(fn (array $process): array => $this->finish($process), $processes);
    }

    /**
     * @param array<string,mixed> $spec
     * @return array{handle:resource,stdout:resource,stderr:resource}
     */
    private function start(array $spec): array
    {
        foreach (['loaded', 'go'] as $file) {
            if (isset($spec[$file])) {
                $spec[$file] = $this->signals . '/' . $spec[$file];
            }
        }
        $spec += ['root' => $this->root];

        $handle = proc_open(
            [PHP_BINARY, __DIR__ . '/encounter_worker.php', json_encode($spec, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($handle);

        return ['handle' => $handle, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    /**
     * @param array{handle:resource,stdout:resource,stderr:resource} $process
     * @return array{status:string,reason:string,id:int}
     */
    private function finish(array $process): array
    {
        $out = (string) stream_get_contents($process['stdout']);
        $err = (string) stream_get_contents($process['stderr']);
        proc_close($process['handle']);

        $answer = json_decode(trim($out), true);

        return is_array($answer) ? $answer : ['status' => 'died', 'reason' => trim($out . "\n" . $err), 'id' => 0];
    }

    private function waitFor(string $file): void
    {
        $until = microtime(true) + 30;
        while (!file_exists($this->signals . '/' . $file)) {
            self::assertLessThan($until, microtime(true), "A worker never got to $file.");
            usleep(10000);
        }
    }
}
