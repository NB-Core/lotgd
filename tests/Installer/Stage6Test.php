<?php

declare(strict_types=1);

namespace Lotgd\Installer;

if (! function_exists(__NAMESPACE__ . '\\fopen')) {
    function fopen(string $filename, string $mode)
    {
        if (\Lotgd\Tests\Installer\Stage6Test::$simulateWriteFailure && $filename === 'dbconnect.php' && str_contains($mode, 'w')) {
            return false;
        }

        return \fopen($filename, $mode);
    }
}

namespace Lotgd\Tests\Installer;

use Lotgd\Installer\Installer;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class Stage6Test extends TestCase
{
    public static bool $simulateWriteFailure = false;
    private string $root;
    private string $dbconnectPath;
    private string $dbconnectBackup;
    private DummySettings $settings;
    private string $originalCwd;

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2) . '/install/lib/Installer.php';

        $this->root           = dirname(__DIR__, 2);
        $this->dbconnectPath  = $this->root . '/dbconnect.php';
        $this->dbconnectBackup = $this->dbconnectPath . '.bak';

        if (file_exists($this->dbconnectBackup)) {
            unlink($this->dbconnectBackup);
        }
        if (file_exists($this->dbconnectPath)) {
            rename($this->dbconnectPath, $this->dbconnectBackup);
        }

        $this->settings = new DummySettings([
            'charset' => 'UTF-8',
            'installer_version' => '1.0.0',
        ]);
        Settings::setInstance($this->settings);
        $GLOBALS['settings'] = $this->settings;

        Output::getInstance();

        self::$simulateWriteFailure = false;

        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        $session             = [];
        $logd_version        = '0.0.0';
        $recommended_modules = [];
        $noinstallnavs       = [];
        $stage               = 6;
        $DB_USEDATACACHE     = false;

        $_POST = [];

        $this->originalCwd = getcwd();
        chdir($this->root);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);

        if (is_dir($this->dbconnectPath)) {
            rmdir($this->dbconnectPath);
        } elseif (file_exists($this->dbconnectPath)) {
            unlink($this->dbconnectPath);
        }
        if (file_exists($this->dbconnectBackup)) {
            rename($this->dbconnectBackup, $this->dbconnectPath);
        }

        Settings::setInstance(null);
        unset($GLOBALS['settings']);

        parent::tearDown();
    }

    public function testStage6WritesDbconnectFileWhenWritable(): void
    {
        global $session;
        $session['dbinfo'] = [
            'DB_HOST' => 'localhost',
            'DB_USER' => 'installer',
            'DB_PASS' => 'password',
            'DB_NAME' => 'lotgd',
            'DB_PREFIX' => 'lotgd_',
            'DB_USEDATACACHE' => true,
            'DB_DATACACHEPATH' => '/data/cache',
        ];

        $installer = new Installer();
        $installer->stage6();

        $this->assertFileExists($this->dbconnectPath);

        $contents = file_get_contents($this->dbconnectPath);
        $this->assertMatchesRegularExpression('/^<\?php\n\/\/This file automatically created by installer\\.php on .+\nreturn \[\n/s', $contents);
        $this->assertStringContainsString("'DB_HOST' => 'localhost'", $contents);
        $this->assertStringContainsString("'DB_USER' => 'installer'", $contents);
        $this->assertStringContainsString("'DB_PASS' => 'password'", $contents);
        $this->assertStringContainsString("'DB_NAME' => 'lotgd'", $contents);
        $this->assertStringContainsString("'DB_PREFIX' => 'lotgd_'", $contents);
        $this->assertStringContainsString("'DB_USEDATACACHE' => 1", $contents);
        $this->assertStringContainsString("'DB_DATACACHEPATH' => '/data/cache'", $contents);

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('I was able to write your dbconnect.php file', $output);
    }

    public function testStage6GuidesManualCreationWhenFileCannotBeWritten(): void
    {
        global $session;
        $session['dbinfo'] = [
            'DB_HOST' => 'localhost',
            'DB_USER' => 'installer',
            'DB_PASS' => 'password',
            'DB_NAME' => 'lotgd',
            'DB_PREFIX' => '',
            'DB_USEDATACACHE' => false,
            'DB_DATACACHEPATH' => '',
        ];

        self::$simulateWriteFailure = true;

        try {
            $installer = new Installer();
            $installer->stage6();
        } finally {
            self::$simulateWriteFailure = false;
        }

        global $session;

        $this->assertSame(5, $session['stagecompleted'] ?? null);

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('You will have to create this file yourself', $output);
        $this->assertStringContainsString('The contents of this file should be as follows', $output);
    }

    public function testStage6MigratesLegacyDbconnectFile(): void
    {
        global $session;
        $session['dbinfo'] = [
            'DB_HOST' => 'legacy',
            'DB_USER' => 'upgrader',
            'DB_PASS' => 'oldpass',
            'DB_NAME' => 'classic',
            'DB_PREFIX' => 'legacy_',
            'DB_USEDATACACHE' => true,
            'DB_DATACACHEPATH' => '/legacy/cache',
        ];

        $legacy = <<<'PHP'
<?php
$DB_HOST = 'legacy';
$DB_USER = 'upgrader';
$DB_PASS = 'oldpass';
$DB_NAME = 'classic';
$DB_PREFIX = 'legacy_';
$DB_USEDATACACHE = 1;
$DB_DATACACHEPATH = '/legacy/cache';
PHP;
        file_put_contents($this->dbconnectPath, $legacy);

        $installer = new Installer();
        $installer->stage6();

        $this->assertFileExists($this->dbconnectPath);

        $contents = file_get_contents($this->dbconnectPath);
        $this->assertStringContainsString("return [", $contents);
        $this->assertStringContainsString("'DB_HOST' => 'legacy'", $contents);
        $this->assertStringContainsString("'DB_USER' => 'upgrader'", $contents);
        $this->assertStringContainsString("'DB_PASS' => 'oldpass'", $contents);
        $this->assertStringContainsString("'DB_NAME' => 'classic'", $contents);
        $this->assertStringContainsString("'DB_PREFIX' => 'legacy_'", $contents);
        $this->assertStringContainsString("'DB_USEDATACACHE' => 1", $contents);
        $this->assertStringContainsString("'DB_DATACACHEPATH' => '/legacy/cache'", $contents);

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('Success', $output);
        $this->assertStringContainsString('You are ready for the next step', $output);

        $this->assertNotSame(5, $session['stagecompleted'] ?? null);
    }

    public function testStage6SkipsConversionForModernDbconnectFileWhenInstallerVersionMissing(): void
    {
        global $session;

        $session['dbinfo'] = [
            'DB_HOST' => 'modern-host',
            'DB_USER' => 'modern-user',
            'DB_PASS' => 'modern-pass',
            'DB_NAME' => 'modern-name',
            'DB_PREFIX' => 'modern_',
            'DB_USEDATACACHE' => true,
            'DB_DATACACHEPATH' => '/modern/cache',
        ];

        $installer = new Installer();
        $installer->stage6();

        $this->assertFileExists($this->dbconnectPath);

        $originalContents = file_get_contents($this->dbconnectPath);
        $originalConfig   = require $this->dbconnectPath;

        $this->settings = new DummySettings([
            'charset' => 'UTF-8',
        ]);
        Settings::setInstance($this->settings);
        $GLOBALS['settings'] = $this->settings;

        $reinstaller = new Installer();
        $reinstaller->stage6();

        $this->assertFileExists($this->dbconnectPath);
        $this->assertSame($originalContents, file_get_contents($this->dbconnectPath));
        $this->assertSame($originalConfig, require $this->dbconnectPath);
    }
}
