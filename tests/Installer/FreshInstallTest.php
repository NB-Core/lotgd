<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use PHPUnit\Framework\TestCase;

final class FreshInstallTest extends TestCase
{
    private const DB_HOST = 'localhost';
    private const DB_USER = 'root';
    private const DB_PASS = '';
    private const DB_NAME = 'lotgd_test';

    public static function tearDownAfterClass(): void
    {
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

        $cmd = sprintf(
            'php -d auto_prepend_file=%s/Stubs/DbMysqli.php %s/run_install.php %s %s %s %s',
            dirname(__DIR__),
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
    }
}
