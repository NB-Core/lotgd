<?php

declare(strict_types=1);

namespace Lotgd\Installer;

if (! function_exists(__NAMESPACE__ . '\\header')) {
    function header(string $header, bool $replace = true, int $http_response_code = 0): void
    {
        $GLOBALS['installer_headers'][] = $header;
    }
}

namespace Lotgd\Tests\Installer;

use Lotgd\Installer\Installer;
use Lotgd\Output;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class Stage8Test extends TestCase
{
    protected function setUp(): void
    {
        class_exists(Database::class);

        global $session, $output, $settings;

        Output::getInstance();

        $session  = [];
        $_SESSION = &$session;
        $output   = Output::getInstance();
        $settings = null;

        $_POST = [];
        $_SERVER['SCRIPT_NAME'] = 'test.php';

        header_remove();

        $GLOBALS['installer_headers'] = [];

        $GLOBALS['module_status'] = ['uninstalledmodules' => []];

        global $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;

        $logd_version        = '0.0.0';
        $recommended_modules = [];
        $noinstallnavs       = [];
        $stage               = 8;
        $DB_USEDATACACHE     = false;

        $session['dbinfo'] = ['upgrade' => false];
        $session['overridememorylimit'] = false;
        $session['skipmodules'] = false;
    }

    public function testStage8DisplaysModuleSelectionWithoutSubmission(): void
    {
        $installer = new Installer();

        global $stage;
        $stage = 7;
        $installer->stage7();

        $stage = 8;
        $installer->stage8();

        $this->assertSame(7, $_SESSION['stagecompleted']);

        $output = Output::getInstance()->getRawOutput();

        $this->assertStringContainsString('Manage Modules', $output);
        $this->assertStringContainsString('Perform a clean install.', $output);

        $this->assertSame([], $this->getRedirectHeaders());
    }

    public function testStage8StoresSelectedModulesAndRedirects(): void
    {
        $_POST['modulesok'] = '1';
        $_POST['modules'] = [
            'lotgdmodule' => 'install,activate',
            'anothermodule' => 'uninstall',
        ];

        $installer = new Installer();

        $installer->stage8();

        $this->assertSame($_POST['modules'], $_SESSION['moduleoperations']);
        $this->assertSame(8, $_SESSION['stagecompleted']);

        $this->assertContains('Location: installer.php?stage=9', $this->getRedirectHeaders());
    }

    public function testStage8KeepsExistingModuleOperationsWithoutRedirect(): void
    {
        $_SESSION['moduleoperations'] = ['somemodule' => 'install'];

        $installer = new Installer();

        $installer->stage8();

        $this->assertSame(['somemodule' => 'install'], $_SESSION['moduleoperations']);
        $this->assertSame(8, $_SESSION['stagecompleted']);

        $this->assertSame([], $this->getRedirectHeaders());
    }

    /**
     * @return list<string>
     */
    private function getRedirectHeaders(): array
    {
        $headers = $GLOBALS['installer_headers'] ?? [];

        return array_values(array_filter($headers, static fn (string $header): bool => str_starts_with($header, 'Location:')));
    }
}
