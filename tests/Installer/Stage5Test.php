<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use Lotgd\Http;
use Lotgd\Installer\Installer;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Support\RootDbConnect;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class Stage5Test extends TestCase
{
    private DummySettings $settings;
    private ?RootDbConnect $dbconnect = null;

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2) . '/install/lib/Installer.php';

        class_exists(Database::class);
        Database::$mockResults = [];
        Database::$queries = [];
        Database::$instance = null;
        Database::$doctrineConnection = null;

        $this->settings = new DummySettings(['charset' => 'UTF-8']);
        Settings::setInstance($this->settings);
        $GLOBALS['settings'] = $this->settings;

        Output::getInstance();

        $this->dbconnect = RootDbConnect::takeOver();

        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        $session = [];
        $session['dbinfo'] = [
            'DB_HOST' => 'localhost',
            'DB_USER' => 'user',
            'DB_PASS' => 'pass',
            'DB_NAME' => 'lotgd',
            'DB_PREFIX' => '',
            'DB_USEDATACACHE' => false,
            'DB_DATACACHEPATH' => '',
        ];
        $session['sure i want to overwrite the tables'] = false;
        $logd_version        = '0.0.0';
        $recommended_modules = [];
        $noinstallnavs       = [];
        $stage               = 5;
        $DB_USEDATACACHE     = false;

        $_POST = [];
        $_GET  = [];
    }

    protected function tearDown(): void
    {
        Database::$mockResults = [];
        Database::$queries = [];
        Database::$instance = null;
        Database::$doctrineConnection = null;

        Settings::setInstance(null);
        unset($GLOBALS['settings']);

        $this->dbconnect?->restore();

        parent::tearDown();
    }

    public function testStage5DetectsConflictsAndFlagsUpgrade(): void
    {
        Database::$mockResults = [
            [
                ['Tables_in_lotgd' => 'lotgd_accounts'],
                ['Tables_in_lotgd' => 'custom_table'],
            ],
            [
                ['Grants for user@localhost' => 'GRANT ALL PRIVILEGES'],
            ],
        ];

        $_POST['DB_PREFIX'] = 'lotgd';
        $installer = new Installer();
        $installer->stage5();

        global $session;
        $this->assertSame('lotgd_', $session['dbinfo']['DB_PREFIX']);
        $this->assertFalse($session['dbinfo']['upgrade']);
        $this->assertSame(4, $session['stagecompleted']);

        $output = Output::getInstance()->getRawOutput();

        $this->assertStringContainsString('This looks like a fresh install', $output);
        $this->assertStringContainsString('There are table conflicts', $output);
        $this->assertStringContainsString('lotgd_accounts', $output);

        $this->assertNotEmpty(Database::$queries);
        $this->assertSame('SHOW TABLES', Database::$queries[0]);
    }

    public function testStage5ConfirmOverwriteSkipsPrompt(): void
    {
        Database::$mockResults = [
            [
                ['Tables_in_lotgd' => 'lotgd_accounts'],
                ['Tables_in_lotgd' => 'custom_table'],
            ],
            [
                ['Grants for user@localhost' => 'GRANT ALL PRIVILEGES'],
            ],
        ];

        global $session;
        $session['stagecompleted'] = 5;
        $_GET['op'] = 'confirm_overwrite';
        $_POST['DB_PREFIX'] = 'lotgd';

        $this->assertSame('confirm_overwrite', Http::get('op'));

        $installer = new Installer();
        $installer->stage5();

        $this->assertTrue($session['sure i want to overwrite the tables']);
        $this->assertSame(5, $session['stagecompleted']);

        $output = Output::getInstance()->getRawOutput();

        $this->assertStringNotContainsString('installer.php?stage=5&op=confirm_overwrite', $output);
    }

    public function testStage5UpgradePath(): void
    {
        global $session;

        $session['stagecompleted'] = 5;
        Database::$mockResults = [];
        $_GET['type'] = 'upgrade';

        $installer = new Installer();
        $installer->stage5();

        $this->assertTrue($session['dbinfo']['upgrade']);
        $this->assertSame(5, $session['stagecompleted']);

        $output = Output::getInstance()->getRawOutput();

        $this->assertStringContainsString('This looks like a game upgrade', $output);
        $this->assertStringNotContainsString('installer.php?stage=5&op=confirm_overwrite', $output);
    }

    public function testStage5RetainsDetectedPrefixAndUpdatesTableMetadata(): void
    {
        global $session;

        $session['dbinfo']['DB_PREFIX'] = 'lotgd';

        Database::$mockResults = [
            [
                ['Tables_in_lotgd' => 'lotgd_accounts'],
                ['Tables_in_lotgd' => 'lotgd_doctrine_migration_versions'],
                ['Tables_in_lotgd' => 'custom_table'],
            ],
            [
                ['Grants for user@localhost' => 'GRANT ALL PRIVILEGES'],
            ],
        ];

        $installer = new Installer();
        $installer->stage5();

        $this->assertSame('lotgd_', $session['dbinfo']['DB_PREFIX']);
        $this->assertTrue($session['dbinfo']['has_migration_metadata']);
        $this->assertSame(['lotgd_accounts'], $session['dbinfo']['existing_logd_tables']);
        $this->assertContains('lotgd_doctrine_migration_versions', $session['dbinfo']['existing_tables']);
    }

    public function testStage5PrefillsPrefixFromDbconnectFile(): void
    {
        global $session;

        $this->dbconnect->write("<?php\nreturn ['DB_PREFIX' => 'lotgd'];\n");

        Database::$mockResults = [
            [
                ['Tables_in_lotgd' => 'lotgd_accounts'],
            ],
            [
                ['Grants for user@localhost' => 'GRANT ALL PRIVILEGES'],
            ],
        ];

        $installer = new Installer();
        $installer->stage5();

        $this->assertSame('lotgd_', $session['dbinfo']['DB_PREFIX']);
        $this->assertSame(['lotgd_accounts'], $session['dbinfo']['existing_logd_tables']);
    }
}
