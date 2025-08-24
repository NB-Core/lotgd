<?php

declare(strict_types=1);

namespace {
    if (!function_exists('db_prefix')) {
        function db_prefix(string $name): string
        {
            return $name;
        }
    }
}

namespace Lotgd\Tests\Installer {

use Lotgd\Installer\Installer;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Output;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class Stage7Test extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);

        global $session, $output, $settings;
        $session   = [];
        $output    = new Output();
        $settings  = null;
        $_POST     = [];
        $_SERVER['SCRIPT_NAME'] = 'test.php';
    }

    public function testStage7UsesDefaultVillageName(): void
    {
        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE, $settings;

        $session['dbinfo']['upgrade'] = true;
        $logd_version      = '0.0.0';
        $recommended_modules = [];
        $noinstallnavs     = [];
        $stage             = 7;
        $DB_USEDATACACHE   = false;
        $settings          = null;

        $installer = new Installer();

        $installer->stage7();

        require __DIR__ . '/../../install/data/installer_sqlstatements.php';
        $this->assertSame('Degolburg', $defaultVillage);
    }
}

}
