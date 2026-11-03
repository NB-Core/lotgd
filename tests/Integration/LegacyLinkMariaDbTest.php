<?php

declare(strict_types=1);

namespace Lotgd\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Database::releaseLegacyLink() against a real MySQL/MariaDB: the
 * server-sent event stream closes the legacy mysqli link so that it holds
 * one connection, not two (docs/RealtimeHosting.md). The link has to be
 * really gone on the server, and code that still uses it afterwards gets it
 * back, on the same database and character set.
 *
 * Opt-in like RealtimeMariaDbTest: LOTGD_INTEGRATION_ROOT names an installed
 * copy of this tree. The worker runs in a process of its own because the
 * test bootstrap replaces DbMysqli with a stub.
 */
final class LegacyLinkMariaDbTest extends TestCase
{
    private string $root = '';
    private \PDO $db;
    private string $dir = '';

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
        $this->dir = sys_get_temp_dir() . '/lotgd-legacy-link-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            array_map('unlink', (array) glob($this->dir . '/*'));
            rmdir($this->dir);
        }
    }

    public function testAReleasedLinkIsClosedOnTheServerAndOpensAgainWhenUsed(): void
    {
        $spec = ['root' => $this->root, 'released' => $this->dir . '/released', 'wait' => $this->dir . '/go'];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/legacy_link_worker.php', json_encode($spec, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);

        $until = microtime(true) + 30;
        while (!is_file($spec['released']) && microtime(true) < $until) {
            usleep(10000);
        }
        $before = (int) file_get_contents($spec['released']);
        self::assertGreaterThan(0, $before, 'The worker reached the release.');
        // The server lets a closed connection go at once, or very nearly.
        $open = 1;
        for ($i = 0; $i < 50 && $open > 0; $i++) {
            $statement = $this->db->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = ?');
            $statement->execute([$before]);
            $open = (int) $statement->fetchColumn();
            if ($open > 0) {
                usleep(20000);
            }
        }
        self::assertSame(0, $open, 'The released link is closed on the server.');

        touch($spec['wait']);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        $answer = json_decode($out, true);
        self::assertIsArray($answer, $out . $err);

        self::assertTrue($answer['released'], 'The link is gone in PHP too.');
        self::assertSame("it\\'s", $answer['escaped'], 'Escaping after the release works.');
        self::assertGreaterThan(0, $answer['after'], 'On a link opened again.');
        self::assertNotSame($before, $answer['after'], 'On a new connection.');
        self::assertSame('utf8mb4', $answer['charset'], 'With the character set it had.');
        $config = require $this->root . '/dbconnect.php';
        self::assertSame((string) $config['DB_NAME'], $answer['database'], 'On the database it had.');
    }
}
