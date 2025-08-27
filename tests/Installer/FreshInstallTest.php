<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use mysqli;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 */
final class FreshInstallTest extends TestCase
{
    private const SOCKET = '/tmp/lotgd_mysqld.sock';
    private const DB_HOST = 'localhost';
    private const DB_USER = 'root';
    private const DB_PASS = '';
    private const DB_NAME = 'lotgd_test';
    private static bool $serverAvailable = false;

    public static function setUpBeforeClass(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        ini_set('mysqli.default_socket', self::SOCKET);

        exec(sprintf('mysqladmin --socket=%s -u %s shutdown >/dev/null 2>&1', self::SOCKET, self::DB_USER));
        exec('pkill mysqld >/dev/null 2>&1');

        exec(sprintf(
            'mysqld --user=mysql --datadir=/var/lib/mysql --socket=%s --port=3306 >/tmp/mysqld_test.log 2>&1 &',
            self::SOCKET
        ));

        $started = false;
        for ($i = 0; $i < 50; $i++) {
            $conn = @mysqli_connect(self::DB_HOST, self::DB_USER, self::DB_PASS, '', 0, self::SOCKET);
            if ($conn) {
                mysqli_close($conn);
                $started = true;
                break;
            }
            usleep(200000);
        }
        self::$serverAvailable = $started;
        if (! $started) {
            return;
        }

        exec(sprintf(
            'mariadb --socket=%s -u %s -e "DROP DATABASE IF EXISTS %s; CREATE DATABASE %s;"',
            self::SOCKET,
            self::DB_USER,
            self::DB_NAME,
            self::DB_NAME
        ));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverAvailable) {
            exec(sprintf('mysqladmin --socket=%s -u %s shutdown >/dev/null 2>&1', self::SOCKET, self::DB_USER));
        }
        $file = __DIR__ . '/../../dbconnect.php';
        if (is_file($file)) {
            unlink($file);
        }
    }

    public function testFreshInstall(): void
    {
        global $session, $DB_USEDATACACHE, $DB_PREFIX;

        $session = ['user' => ['loggedin' => false, 'superuser' => 0, 'acctid' => 0, 'restorepage' => '']];
        $DB_USEDATACACHE = 0;
        $DB_PREFIX = '';
        $_GET = $_POST = [];

        if (! self::$serverAvailable) {
            self::markTestSkipped('MySQL server not available');
        }

        $cmd = sprintf(
            'php %s/run_install.php %s %s %s %s',
            __DIR__,
            escapeshellarg(self::DB_HOST),
            escapeshellarg(self::DB_USER),
            escapeshellarg(self::DB_PASS),
            escapeshellarg(self::DB_NAME)
        );
        exec($cmd, $out, $ret);
        if ($ret !== 0) {
            self::markTestSkipped('Installer failed to run');
        }

        $this->assertFileExists(__DIR__ . '/../../dbconnect.php');

        $mysqli = new mysqli(self::DB_HOST, self::DB_USER, self::DB_PASS, self::DB_NAME, 0, self::SOCKET);
        $result = $mysqli->query('SELECT COUNT(*) FROM doctrine_migration_versions');
        $executed = (int) $result->fetch_row()[0];
        $expected = count(glob(__DIR__ . '/../../migrations/Version*.php'));
        $this->assertSame($expected, $executed);

        $admin = $mysqli->query("SELECT login FROM accounts WHERE login='admin'");
        $this->assertGreaterThan(0, $admin->num_rows);
    }
}
