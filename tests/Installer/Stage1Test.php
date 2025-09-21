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
final class Stage1Test extends TestCase
{
    private string $licensePath;
    private string $licenseBackup;
    private DummySettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2) . '/install/lib/Installer.php';

        $root               = dirname(__DIR__, 2);
        $this->licensePath  = $root . '/LICENSE.txt';
        $this->licenseBackup = $this->licensePath . '.bak';

        if (file_exists($this->licenseBackup)) {
            rename($this->licenseBackup, $this->licensePath);
        }

        $this->settings = new DummySettings(['charset' => 'UTF-8']);
        Settings::setInstance($this->settings);
        $GLOBALS['settings'] = $this->settings;

        Output::getInstance();

        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        $session             = ['stagecompleted' => 0];
        $logd_version        = '0.0.0';
        $recommended_modules = [];
        $noinstallnavs       = [];
        $stage               = 1;
        $DB_USEDATACACHE     = false;
    }

    protected function tearDown(): void
    {
        if (file_exists($this->licenseBackup) && ! file_exists($this->licensePath)) {
            rename($this->licenseBackup, $this->licensePath);
        }

        Settings::setInstance(null);
        unset($GLOBALS['settings']);

        parent::tearDown();
    }

    public function testStage1StaysOnCurrentStageWhenLicenseMissing(): void
    {
        $this->assertFileExists($this->licensePath);
        $this->assertTrue(rename($this->licensePath, $this->licenseBackup));

        global $session, $stage;
        $session['stagecompleted'] = 0;
        $stage                     = 1;

        $installer = new Installer();
        $installer->stage1();

        $output = Output::getInstance()->getRawOutput();

        $this->assertStringContainsString('license file (LICENSE.txt) could not be found', $output);
        $this->assertSame(1, $stage);
        $this->assertSame(0, $session['stagecompleted']);
    }

    public function testStage1DisplaysLicenseWhenFilePresent(): void
    {
        $this->assertFileExists($this->licensePath);

        global $session, $stage;
        $session['stagecompleted'] = 0;
        $stage                     = 1;

        $installer = new Installer();
        $installer->stage1();

        $output = Output::getInstance()->getRawOutput();

        $this->assertStringContainsString('Plain Text', $output);
        $this->assertStringNotContainsString('has been modified', $output);
        $this->assertSame(1, $stage);
        $this->assertSame(0, $session['stagecompleted']);
    }

    public function testStage1RejectsModifiedLicense(): void
    {
        $this->assertFileExists($this->licensePath);
        $this->assertTrue(rename($this->licensePath, $this->licenseBackup));
        $this->assertNotFalse(file_put_contents($this->licensePath, 'Fake license contents'));

        try {
            global $session, $stage;
            $session['stagecompleted'] = 0;
            $stage                     = 1;

            $installer = new Installer();
            $installer->stage1();

            $output = Output::getInstance()->getRawOutput();

            $this->assertStringContainsString('has been modified', $output);
            $this->assertSame(-1, $stage);
            $this->assertSame(-1, $session['stagecompleted']);
        } finally {
            if (file_exists($this->licensePath)) {
                unlink($this->licensePath);
            }

            if (file_exists($this->licenseBackup)) {
                rename($this->licenseBackup, $this->licensePath);
            }
        }
    }
}
