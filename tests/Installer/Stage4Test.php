<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use Lotgd\Installer\Installer;
use Lotgd\MySQL\Database;
use Lotgd\Output;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Support\RootDbConnect;
use PHPUnit\Framework\TestCase;

final class Stage4Test extends TestCase
{
    private ?RootDbConnect $dbconnect = null;
    private string $configDir;
    private string $configBackup;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure stub classes are loaded and reset Database state
        class_exists(DbMysqli::class);
        require_once __DIR__ . '/../Stubs/DoctrineBootstrap.php';
        \Lotgd\Tests\Stubs\DoctrineBootstrap::$conn = null;
        Database::$instance = null;
        Database::$doctrineConnection = null;

        // Borrowed rather than deleted: in a checkout that is also an
        // installed game this is the database configuration, and `@unlink`
        // both destroyed it and hid any reason the delete failed.
        $this->dbconnect = RootDbConnect::takeOver();

        // Swap config directory with an empty one
        $this->configDir = dirname(__DIR__, 2) . '/config';
        $this->configBackup = $this->configDir . '_backup';
        if (is_dir($this->configBackup)) {
            $this->removeDir($this->configBackup);
        }
        rename($this->configDir, $this->configBackup);
        mkdir($this->configDir);
    }

    protected function tearDown(): void
    {
        // Restore original config directory
        if (is_dir($this->configDir)) {
            rmdir($this->configDir);
        }
        if (is_dir($this->configBackup)) {
            rename($this->configBackup, $this->configDir);
        }

        // Puts back whatever was there, including nothing.
        $this->dbconnect?->restore();

        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = "$dir/$file";
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testStage4CompletesWithoutDbconnect(): void
    {
        global $session;
        $session = [
            'dbinfo' => [
                'DB_HOST' => 'localhost',
                'DB_USER' => 'user',
                'DB_PASS' => 'pass',
                'DB_NAME' => 'lotgd',
                'DB_USEDATACACHE' => false,
                'DB_DATACACHEPATH' => '',
            ],
        ];

        require_once dirname(__DIR__, 2) . '/install/lib/Installer.php';
        $installer = new Installer();
        $installer->runStage(4);

        $this->assertTrue(defined('DB_INSTALLER_STAGE4'));
        $this->assertFileDoesNotExist(RootDbConnect::path());

        $instance = Database::getInstance();
        $this->assertInstanceOf(DbMysqli::class, $instance);
        $this->assertNull(Database::$doctrineConnection);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testStage4ReportsConnectionFailure(): void
    {
        global $session;
        $session = [
            'dbinfo' => [
                'DB_HOST' => 'localhost',
                'DB_USER' => 'user',
                'DB_PASS' => 'pass',
                'DB_NAME' => 'lotgd',
                'DB_USEDATACACHE' => false,
                'DB_DATACACHEPATH' => '',
            ],
            'stagecompleted' => 3,
        ];

        $output = Output::getInstance();
        Output::setInstance($output);

        $errorMessage = 'Access denied';
        $failingDb = new class($errorMessage) extends DbMysqli {
            public function __construct(private string $errorMessage)
            {
            }

            public function connect(string $h, string $u, string $p): bool
            {
                echo $this->errorMessage;
                return false;
            }

            public function error(): string
            {
                return $this->errorMessage;
            }
        };

        Database::setInstance($failingDb);

        require_once dirname(__DIR__, 2) . '/install/lib/Installer.php';

        ob_start();
        $installer = new Installer();
        $installer->runStage(4);
        ob_end_clean();

        $this->assertFalse(defined('DB_INSTALLER_STAGE4'));
        $this->assertSame(3, $session['stagecompleted']);

        $rawOutput = $output->getRawOutput();
        $this->assertStringContainsString("Blast!  I wasn't able to connect", $rawOutput);
        $this->assertStringContainsString($errorMessage, $rawOutput);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testStage4WarnsWhenDatacacheIsInsideTheGameDirectory(): void
    {
        $cacheDir = dirname(__DIR__, 2) . '/stage4-cache-' . uniqid();
        mkdir($cacheDir, 0700);
        try {
            $rawOutput = $this->runStage4WithDatacache($cacheDir);
        } finally {
            rmdir($cacheDir);
        }

        $this->assertStringContainsString('datacache directory is inside the web root', $rawOutput);
        $this->assertTrue(defined('DB_INSTALLER_STAGE4'));
        $this->assertStringContainsString("You've passed all the tests", $rawOutput);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testStage4DoesNotWarnWhenDatacacheIsOutsideTheGameDirectory(): void
    {
        $cacheDir = sys_get_temp_dir() . '/lotgd-stage4-cache-' . uniqid();
        mkdir($cacheDir, 0700);
        try {
            $rawOutput = $this->runStage4WithDatacache($cacheDir);
        } finally {
            rmdir($cacheDir);
        }

        // Guards the positive test: the check ran and passed here, so the
        // warning's absence is a decision, not a skipped branch.
        $this->assertStringContainsString('Checking datacache', $rawOutput);
        $this->assertStringNotContainsString('inside the web root', $rawOutput);
    }

    private function runStage4WithDatacache(string $cacheDir): string
    {
        global $session;
        $session = [
            'dbinfo' => [
                'DB_HOST' => 'localhost',
                'DB_USER' => 'user',
                'DB_PASS' => 'pass',
                'DB_NAME' => 'lotgd',
                'DB_USEDATACACHE' => true,
                'DB_DATACACHEPATH' => $cacheDir,
            ],
        ];
        // A CLI run has no document root; only the game directory counts.
        unset($_SERVER['DOCUMENT_ROOT']);

        $output = Output::getInstance();

        require_once dirname(__DIR__, 2) . '/install/lib/Installer.php';
        ob_start();
        $installer = new Installer();
        $installer->runStage(4);
        ob_end_clean();

        return $output->getRawOutput();
    }
}
