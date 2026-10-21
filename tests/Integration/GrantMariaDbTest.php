<?php

declare(strict_types=1);

namespace Lotgd\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Grants against a real MySQL/MariaDB (docs/ReactiveCore.md, Phase 5): two
 * officers accepting the same applicant at the same moment, an accepting
 * action that fails, an expired grant, an applicant who withdrew. What the
 * stub connection cannot show is the row locks on the grant's group.
 *
 * Opt-in like GuardedActionMariaDbTest: LOTGD_INTEGRATION_ROOT names an
 * installed, migrated copy of this tree; without it the test is skipped.
 */
final class GrantMariaDbTest extends TestCase
{
    private string $root = '';
    private \PDO $db;
    /** @var list<int> */
    private array $accounts = [];
    private int $clan = 0;
    private string $key = '';
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
        foreach (['guarded_actions', 'grants_enabled'] as $setting) {
            $old = $this->db->prepare('SELECT value FROM settings WHERE setting = ?');
            $old->execute([$setting]);
            $value = $old->fetchColumn();
            $this->settings[$setting] = $value === false ? null : (string) $value;
            $this->db->prepare("INSERT INTO settings (setting, value) VALUES (?, '1') ON DUPLICATE KEY UPDATE value = '1'")->execute([$setting]);
        }

        $this->db->exec("INSERT INTO clans (clanname, clanshort, clanmotd, clandesc, customsay) VALUES ('Grant Test', 'GT', '', '', '')");
        $this->clan = (int) $this->db->lastInsertId();

        $this->signals = sys_get_temp_dir() . '/lotgd-grant-' . bin2hex(random_bytes(4));
        mkdir($this->signals);
    }

    protected function tearDown(): void
    {
        if ($this->accounts !== []) {
            $ids = implode(',', $this->accounts);
            $this->db->exec("DELETE FROM action_log WHERE acctid IN ($ids)");
            $this->db->exec("DELETE FROM mail WHERE msgto IN ($ids)");
            $this->db->exec("DELETE FROM granted_navs WHERE acctid IN ($ids)");
            $this->db->exec("DELETE FROM accounts WHERE acctid IN ($ids)");
        }
        if ($this->clan > 0) {
            $this->db->exec('DELETE FROM clans WHERE clanid = ' . $this->clan);
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

    public function testTwoOfficersAcceptingAtOnceDecideOnce(): void
    {
        [$ann, $bob, $nine] = [$this->account('ann', 20), $this->account('bob', 20), $this->account('nine', 0)];
        [$annGrant, $bobGrant] = [$this->grant($ann, $nine), $this->grant($bob, $nine)];

        $answers = $this->together(
            ['acctid' => $ann, 'grant' => $annGrant, 'hold' => 0.5],
            ['acctid' => $bob, 'grant' => $bobGrant, 'hold' => 0.5]
        );

        $statuses = array_column($answers, 'status');
        sort($statuses);
        self::assertSame(['done', 'rejected'], $statuses, (string) json_encode($answers));
        self::assertContains('grant-used', array_column($answers, 'reason'));
        self::assertSame(10, $this->column($nine, 'clanrank'), 'A member, once.');
        self::assertSame(0, $this->openGrants($nine));
        self::assertSame(1, $this->mails($nine), 'One acceptance, one mail.');
    }

    public function testAnAcceptanceThatFailsLeavesTheGrantsOpen(): void
    {
        [$ann, $bob, $nine] = [$this->account('ann', 20), $this->account('bob', 20), $this->account('nine', 0)];
        $annGrant = $this->grant($ann, $nine);
        $this->grant($bob, $nine);

        $answer = $this->finish($this->start(['acctid' => $ann, 'grant' => $annGrant, 'fail' => true]));

        self::assertSame('error', $answer['status'], (string) json_encode($answer));
        self::assertSame(0, $this->column($nine, 'clanrank'));
        self::assertSame(2, $this->openGrants($nine), 'Both officers can still decide.');
        self::assertSame(0, $this->mails($nine));

        $retry = $this->finish($this->start(['acctid' => $ann, 'grant' => $annGrant]));
        self::assertSame('done', $retry['status'], (string) json_encode($retry));
    }

    public function testAnExpiredGrantIsRefused(): void
    {
        [$ann, $nine] = [$this->account('ann', 20), $this->account('nine', 0)];
        $grant = $this->grant($ann, $nine, '-1 hour');

        $answer = $this->finish($this->start(['acctid' => $ann, 'grant' => $grant]));

        self::assertSame(['status' => 'rejected', 'reason' => 'grant-expired'], $answer);
        self::assertSame(0, $this->column($nine, 'clanrank'));
    }

    public function testAnApplicantWhoWithdrewIsNotAccepted(): void
    {
        [$ann, $nine] = [$this->account('ann', 20), $this->account('nine', 0)];
        $grant = $this->grant($ann, $nine);
        $this->db->exec('UPDATE accounts SET clanid = 0 WHERE acctid = ' . $nine);

        $answer = $this->finish($this->start(['acctid' => $ann, 'grant' => $grant]));

        self::assertSame(['status' => 'rejected', 'reason' => 'applicant-gone'], $answer);
        self::assertSame(1, $this->openGrants($nine), 'Rolled back with the action.');
    }

    private function account(string $name, int $rank): int
    {
        $row = $this->db->query('SELECT * FROM accounts ORDER BY acctid LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'The installation needs at least one account to copy.');
        unset($row['acctid']);
        $login = $name . bin2hex(random_bytes(3));
        $row = ['login' => $login, 'name' => $login, 'version' => 0, 'clanid' => $this->clan, 'clanrank' => $rank,
            'locked' => 0, 'emailaddress' => $login . '@example.invalid'] + $row;
        $columns = array_keys($row);
        $statement = $this->db->prepare(
            'INSERT INTO accounts (`' . implode('`, `', $columns) . '`) VALUES (:' . implode(', :', $columns) . ')'
        );
        $statement->execute($row);
        $acctid = (int) $this->db->lastInsertId();
        $this->accounts[] = $acctid;

        return $acctid;
    }

    private function grant(int $officer, int $applicant, string $expires = '+1 day'): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO granted_navs (acctid, granted_by, action, target, target_key, text, link, created, expires_at, changed_us)'
            . " VALUES (?, ?, 'clan.accept', ?, ?, 'Accept', '', NOW(), ?, 1)"
        );
        $statement->execute([
            $officer,
            $applicant,
            json_encode(['applicant' => $applicant, 'clan' => $this->clan]),
            'clan-applicant-' . $applicant,
            date('Y-m-d H:i:s', (int) strtotime($expires)),
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function column(int $acctid, string $column): int
    {
        $statement = $this->db->prepare("SELECT `$column` FROM accounts WHERE acctid = ?");
        $statement->execute([$acctid]);

        return (int) $statement->fetchColumn();
    }

    private function openGrants(int $applicant): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM granted_navs WHERE target_key = ? AND used_at IS NULL');
        $statement->execute(['clan-applicant-' . $applicant]);

        return (int) $statement->fetchColumn();
    }

    private function mails(int $acctid): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM mail WHERE msgto = ?');
        $statement->execute([$acctid]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string,mixed> ...$specs
     * @return list<array{status:string,reason:string}>
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
            [PHP_BINARY, __DIR__ . '/grant_worker.php', json_encode($spec, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($handle);

        return ['handle' => $handle, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    /**
     * @param array{handle:resource,stdout:resource,stderr:resource} $process
     * @return array{status:string,reason:string}
     */
    private function finish(array $process): array
    {
        $out = (string) stream_get_contents($process['stdout']);
        $err = (string) stream_get_contents($process['stderr']);
        proc_close($process['handle']);

        $answer = json_decode(trim($out), true);

        return is_array($answer) ? $answer : ['status' => 'died', 'reason' => trim($out . "\n" . $err)];
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
