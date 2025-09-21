<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use Lotgd\Installer\Installer;
use Lotgd\MySQL\Database;
use Lotgd\Output;
use Lotgd\Tests\Stubs\DbMysqli;
use PHPUnit\Framework\TestCase;

final class Stage4Test extends TestCase
{
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

        // Remove any existing dbconnect.php to ensure a clean state
        @unlink(dirname(__DIR__, 2) . '/dbconnect.php');

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

        // Clean up dbconnect.php created during the test run
        @unlink(dirname(__DIR__, 2) . '/dbconnect.php');

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
        $this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/dbconnect.php');

        $instance = Database::getInstance();
        $this->assertInstanceOf(DbMysqli::class, $instance);
        $this->assertNull(Database::$doctrineConnection);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
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
        $outputRef = new \ReflectionClass(Output::class);
        $outputProp = $outputRef->getProperty('instance');
        $outputProp->setAccessible(true);
        $outputProp->setValue(null, $output);

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

        $dbRef = new \ReflectionClass(Database::class);
        $instanceProp = $dbRef->getProperty('instance');
        $instanceProp->setAccessible(true);
        $instanceProp->setValue(null, $failingDb);

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
}
