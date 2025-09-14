<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use Lotgd\Installer\Installer;
use Lotgd\Output;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class Stage8Test extends TestCase
{
    protected function setUp(): void
    {
        global $session, $output, $settings;

        $session   = [];
        $output    = new Output();
        $settings  = null;
        $_POST     = [];
        $_SERVER['SCRIPT_NAME'] = 'test.php';
        $GLOBALS['module_status'] = ['uninstalledmodules' => []];
    }

    public function testStage8RunsWithoutRecommendedModules(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;

        $session['dbinfo']['upgrade'] = false;
        $logd_version       = '0.0.0';
        $recommended_modules = null;
        $noinstallnavs      = [];
        $stage              = 8;
        $DB_USEDATACACHE    = false;

        set_error_handler(function ($severity, $message, $file, $line) {
            if ($severity === E_WARNING) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }

            return false;
        });

        $installer = new Installer();
        $installer->stage8();

        restore_error_handler();

        $this->assertTrue(true); // No warnings were raised
    }
}
