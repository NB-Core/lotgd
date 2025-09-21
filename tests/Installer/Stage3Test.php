<?php

declare(strict_types=1);

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
final class Stage3Test extends TestCase
{
    /** @var array<string, string|null> */
    private array $envBackup = [];
    /** @var array<string, string|null> */
    private array $envArrayBackup = [];
    private DummySettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2) . '/install/lib/Installer.php';

        $this->settings = new DummySettings(['charset' => 'UTF-8']);
        Settings::setInstance($this->settings);
        $GLOBALS['settings'] = $this->settings;

        Output::getInstance();

        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        $session             = [];
        $logd_version        = '0.0.0';
        $recommended_modules = [];
        $noinstallnavs       = [];
        $stage               = 3;
        $DB_USEDATACACHE     = false;

        $keys = [
            'MYSQL_HOST',
            'MYSQL_USER',
            'MYSQL_PASSWORD',
            'MYSQL_DATABASE',
            'MYSQL_USEDATACACHE',
            'MYSQL_DATACACHEPATH',
        ];
        foreach ($keys as $key) {
            $value = getenv($key);
            $this->envBackup[$key] = $value === false ? null : $value;
            $this->envArrayBackup[$key] = $_ENV[$key] ?? null;
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        foreach ($this->envArrayBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        Settings::setInstance(null);
        unset($GLOBALS['settings']);

        parent::tearDown();
    }

    public function testStage3PopulatesSessionFromDockerEnvironment(): void
    {
        $env = [
            'MYSQL_HOST'          => 'docker-db',
            'MYSQL_USER'          => 'docker-user',
            'MYSQL_PASSWORD'      => 'secret',
            'MYSQL_DATABASE'      => 'lotgd',
            'MYSQL_USEDATACACHE'  => '1',
            'MYSQL_DATACACHEPATH' => '/tmp/cache',
        ];
        foreach ($env as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }

        $installer = new Installer();
        $installer->stage3();

        global $session;
        $this->assertSame('docker-db', $session['dbinfo']['DB_HOST']);
        $this->assertSame('docker-user', $session['dbinfo']['DB_USER']);
        $this->assertSame('secret', $session['dbinfo']['DB_PASS']);
        $this->assertSame('lotgd', $session['dbinfo']['DB_NAME']);
        $this->assertTrue($session['dbinfo']['DB_USEDATACACHE']);
        $this->assertSame('/tmp/cache', $session['dbinfo']['DB_DATACACHEPATH']);

        $output = Output::getInstance()->getRawOutput();

        $this->assertStringContainsString("name='DB_HOST'", $output);
        $this->assertStringContainsString("name='DB_NAME'", $output);
        $this->assertStringContainsString("name='DB_USER'", $output);
        $this->assertStringContainsString('Docker setup', $output);
    }
}
