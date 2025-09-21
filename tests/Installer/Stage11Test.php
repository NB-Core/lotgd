<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use Lotgd\Installer\Installer;
use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class Stage11Test extends TestCase
{
    private string $root;
    private string $installerPath;
    private string $installerBackup;
    private DummySettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2) . '/install/lib/Installer.php';

        $this->root            = dirname(__DIR__, 2);
        $this->installerPath   = $this->root . '/installer.php';
        $this->installerBackup = $this->installerPath . '.bak';

        if (file_exists($this->installerBackup)) {
            unlink($this->installerBackup);
        }
        if (file_exists($this->installerPath)) {
            rename($this->installerPath, $this->installerBackup);
        }
        file_put_contents($this->installerPath, "<?php // test installer\n");

        $this->settings = new DummySettings(['charset' => 'UTF-8']);
        Settings::setInstance($this->settings);
        $GLOBALS['settings'] = $this->settings;

        Output::getInstance();

        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        $_SESSION           = [];
        $session            =& $_SESSION;
        $session['user']    = ['loggedin' => false, 'restorepage' => 'village.php'];
        $session['allowednavs'] = [];
        $logd_version        = '0.0.0';
        $recommended_modules = [];
        $noinstallnavs       = [];
        $stage               = 11;
        $DB_USEDATACACHE     = false;

        $_POST = [];
        $_GET  = [];

        Nav::clearNav();
    }

    protected function tearDown(): void
    {
        Nav::clearNav();

        if (file_exists($this->installerPath)) {
            unlink($this->installerPath);
        }
        if (file_exists($this->installerBackup)) {
            rename($this->installerBackup, $this->installerPath);
        }

        Settings::setInstance(null);
        unset($GLOBALS['settings']);

        parent::tearDown();
    }

    public function testStage11AddsContinueNavigationForLoggedInUser(): void
    {
        global $session;
        $session['user'] = ['loggedin' => true, 'restorepage' => 'village.php'];
        $session['allowednavs'] = [];
        Nav::clearNav();

        $installer = new Installer();
        $installer->stage11();

        $items = $this->getNavItems();
        $links = array_column($items, 1);
        $this->assertContains('village.php', $links);

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('installer.php file still exists', $output);
    }

    public function testStage11AddsLoginNavigationForLoggedOutUser(): void
    {
        global $session;
        $session['user'] = ['loggedin' => false, 'restorepage' => 'village.php'];
        $session['allowednavs'] = [];
        Nav::clearNav();

        $installer = new Installer();
        $installer->stage11();

        $items = $this->getNavItems();
        $links = array_column($items, 1);
        $this->assertContains('./', $links);

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('installer.php file still exists', $output);
    }

    public function testStage11DeletesInstallerFileWhenRequested(): void
    {
        global $session;
        $session['user'] = ['loggedin' => false, 'restorepage' => 'village.php'];
        $session['allowednavs'] = [];
        Nav::clearNav();

        $_POST['delete_installer'] = '1';

        $installer = new Installer();
        $installer->stage11();

        $this->assertFileDoesNotExist($this->installerPath);

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('Installer file installer.php removed', $output);
    }

    /**
     * @return array<int, array{0:mixed,1:string}>
     */
    private function getNavItems(): array
    {
        $property = new \ReflectionProperty(Nav::class, 'sections');
        $property->setAccessible(true);
        /** @var array<string, \Lotgd\Nav\NavigationSection> $sections */
        $sections = $property->getValue();

        $items = [];
        foreach ($sections as $section) {
            foreach ($section->getItems() as $item) {
                $items[] = [$item->text, $item->link];
            }
        }

        return $items;
    }
}
