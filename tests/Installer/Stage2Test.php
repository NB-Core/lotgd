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
final class Stage2Test extends TestCase
{
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
        $stage               = 2;
        $DB_USEDATACACHE     = false;
    }

    protected function tearDown(): void
    {
        Settings::setInstance(null);
        unset($GLOBALS['settings']);

        parent::tearDown();
    }

    public function testStage2OutputsLicenseAcceptanceMessage(): void
    {
        $installer = new Installer();
        $installer->stage2();

        $output = Output::getInstance()->getRawOutput();

        $this->assertStringContainsString('By continuing with this installation', $output);
        $this->assertStringContainsString('License Agreement', $output);
    }
}
