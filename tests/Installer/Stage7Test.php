<?php

declare(strict_types=1);

namespace {
    if (! function_exists('db_prefix')) {
        function db_prefix(string $name): string
        {
            return $name;
        }
    }
}

namespace Lotgd\Installer {

    if (! function_exists(__NAMESPACE__ . '\\header')) {
        function header(string $header, bool $replace = true, int $http_response_code = 0): void
        {
            $GLOBALS['installer_headers'][] = $header;
        }
    }
}

namespace Lotgd\Tests\Installer {

    use Lotgd\Installer\Installer;
    use Lotgd\Output;
    use Lotgd\Settings;
    use Lotgd\Tests\Stubs\Database;
    use Lotgd\Tests\Stubs\DummySettings;
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     */
    final class Stage7Test extends TestCase
    {
        private string $dbconnectPath;

        protected function setUp(): void
        {
            class_exists(Database::class);

            global $session, $output, $settings;

            $session  = [];
            $_SESSION = &$session;
            $output   = Output::getInstance();
            $settings = null;

            $this->dbconnectPath = dirname(__DIR__, 2) . '/dbconnect.php';
            if (file_exists($this->dbconnectPath)) {
                unlink($this->dbconnectPath);
            }

            Database::$settings_table = [];
            Database::$settings_extended_table = [];
            Database::$mockResults = [];
            Database::$queries = [];
            Database::$instance = null;
            Database::$doctrineConnection = null;
            Settings::setInstance(null);
            unset($GLOBALS['settings']);

            $_POST = [];
            $_SERVER['SCRIPT_NAME'] = 'test.php';

            header_remove();

            $GLOBALS['installer_headers'] = [];

            global $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;

            $logd_version        = '0.0.0';
            $recommended_modules = [];
            $noinstallnavs       = [];
            $stage               = 7;
            $DB_USEDATACACHE     = false;
        }

        protected function tearDown(): void
        {
            if (isset($this->dbconnectPath) && file_exists($this->dbconnectPath)) {
                unlink($this->dbconnectPath);
            }

            Settings::setInstance(null);
            unset($GLOBALS['settings']);

            Database::$mockResults = [];
            Database::$queries = [];
            Database::$instance = null;
            Database::$doctrineConnection = null;

            parent::tearDown();
        }

        public function testStage7RendersConfirmationWhenNoSelectionMade(): void
        {
            $installer = new Installer();

            $installer->stage7();

            $this->assertSame(6, $_SESSION['stagecompleted']);

            $output = Output::getInstance()->getRawOutput();

            $this->assertStringContainsString('Confirmation', $output);
            $this->assertStringContainsString('Perform a clean install.', $output);
            $this->assertStringNotContainsString("value='upgrade'", $output);
            $this->assertStringContainsString('No existing LoGD data or Doctrine migration metadata was detected', $output);
        }

        public function testCleanInstallDoesNotReportDetectedVersion(): void
        {
            $installer = new Installer();

            $installer->stage7();

            $output = Output::getInstance()->getRawOutput();

            $this->assertStringNotContainsString('Detected database version', $output);
            $this->assertStringContainsString('No existing LoGD data or Doctrine migration metadata was detected', $output);
        }

        public function testStage7HandlesUpgradeSelectionFromPostData(): void
        {
            $_POST['type']    = 'upgrade';
            $_POST['version'] = '1.0.0';

            $_SESSION['dbinfo'] = [
                'existing_tables' => ['logd_accounts'],
                'existing_logd_tables' => ['logd_accounts'],
            ];

            $installer = new Installer();

            $installer->stage7();

            $this->assertTrue($_SESSION['dbinfo']['upgrade']);
            $this->assertSame('1.0.0', $_SESSION['fromversion']);
            $this->assertSame(7, $_SESSION['stagecompleted']);

            $output = Output::getInstance()->getRawOutput();

            $this->assertStringNotContainsString('Confirmation', $output);
            $this->assertStringNotContainsString('Perform a clean install.', $output);
            $this->assertContains('Location: installer.php?stage=8', $this->getRedirectHeaders());
        }

        public function testStage7FallsBackToDefaultVersionWhenPostValueIsInvalid(): void
        {
            $_POST['type']    = 'upgrade';
            $_POST['version'] = ['1.0.0'];

            $_SESSION['dbinfo'] = [
                'existing_tables' => ['logd_accounts'],
                'existing_logd_tables' => ['logd_accounts'],
            ];

            $installer = new Installer();

            $installer->stage7();

            $this->assertTrue($_SESSION['dbinfo']['upgrade']);
            $this->assertSame('2.0.0', $_SESSION['fromversion']);
            $this->assertSame(7, $_SESSION['stagecompleted']);
            $this->assertContains('Location: installer.php?stage=8', $this->getRedirectHeaders());
        }

        public function testUpgradeScenarioDisplaysDetectedVersionMessage(): void
        {
            $settings = new DummySettings([
                'charset' => 'UTF-8',
                'installer_version' => '1.1.1',
            ]);
            Settings::setInstance($settings);
            $GLOBALS['settings'] = $settings;

            $_SESSION['dbinfo'] = [
                'existing_tables' => ['logd_accounts'],
                'existing_logd_tables' => ['logd_accounts'],
            ];

            $installer = new Installer();

            $installer->stage7();

            $output = Output::getInstance()->getRawOutput();

            $this->assertStringContainsString('Detected database version', $output);
            $this->assertStringContainsString('1.1.1', $output);
            $this->assertStringContainsString('The installer will upgrade your database.', $output);
        }

        public function testStage7SkipsLegacyDropdownWhenDoctrineMetadataExists(): void
        {
            $_SESSION['dbinfo'] = [
                'has_migration_metadata' => true,
            ];

            $installer = new Installer();

            $installer->stage7();

            $output = Output::getInstance()->getRawOutput();

            $this->assertTrue($_SESSION['dbinfo']['upgrade']);
            $this->assertSame('2.0.0', $_SESSION['fromversion']);
            $this->assertSame(6, $_SESSION['stagecompleted']);
            $this->assertStringContainsString('Doctrine migration metadata detected', $output);
            $this->assertStringContainsString('Perform an upgrade using Doctrine migrations only.', $output);
            $this->assertStringNotContainsString("<select name='version'>", $output);
            $this->assertStringContainsString('Doctrine already tracks your schema history', $output);
        }

        public function testStage7RespectsUpgradeFlagFromStage5(): void
        {
            $_SESSION['dbinfo'] = [
                'upgrade' => true,
                'existing_tables' => [],
                'existing_logd_tables' => [],
            ];

            $installer = new Installer();

            $installer->stage7();

            $output = Output::getInstance()->getRawOutput();

            $this->assertTrue($_SESSION['dbinfo']['upgrade']);
            $this->assertSame('2.0.0', $_SESSION['fromversion']);
            $this->assertStringContainsString("value='upgrade' name='type' checked", $output);
            $this->assertStringContainsString("<select name='version'>", $output);
            $this->assertStringContainsString('The installer was instructed to upgrade during the database check', $output);
            $this->assertStringContainsString('You requested an upgrade in the previous step, so the installer keeps the upgrade option selected', $output);
            $this->assertStringContainsString('<option value="2.0.0" selected>2.0.0+ (automatic migrations)</option>', $output);
        }

        public function testStage7DefaultsToUpgradeWhenLogdTablesDetected(): void
        {
            $_SESSION['dbinfo'] = [
                'upgrade' => false,
                'existing_tables' => ['logd_accounts'],
                'existing_logd_tables' => ['logd_accounts'],
            ];

            $installer = new Installer();

            $installer->stage7();

            $output = Output::getInstance()->getRawOutput();

            $this->assertTrue($_SESSION['dbinfo']['upgrade']);
            $this->assertSame('2.0.0', $_SESSION['fromversion']);
            $this->assertStringContainsString("value='upgrade' name='type' checked", $output);
            $this->assertStringContainsString("<select name='version'>", $output);
            $this->assertStringContainsString('Existing LoGD tables were detected; choose the version you are upgrading from', $output);
            $this->assertStringContainsString('Because existing LoGD tables were found, the upgrade option is pre-selected', $output);
        }

        public function testStage7DefaultsToUpgradeWithPrefixedTables(): void
        {
            $_SESSION['dbinfo'] = [
                'upgrade' => false,
                'existing_tables' => ['lotgd_accounts'],
                'existing_logd_tables' => ['lotgd_accounts'],
                'has_migration_metadata' => false,
                'DB_PREFIX' => 'lotgd_',
            ];

            $installer = new Installer();

            $installer->stage7();

            $output = Output::getInstance()->getRawOutput();

            $this->assertTrue($_SESSION['dbinfo']['upgrade']);
            $this->assertSame('2.0.0', $_SESSION['fromversion']);
            $this->assertStringContainsString("value='upgrade' name='type' checked", $output);
            $this->assertStringContainsString("<select name='version'>", $output);
        }

        public function testStage7KeepsCleanInstallDefaultWhenOnlyUnrelatedTablesDetected(): void
        {
            $_SESSION['dbinfo'] = [
                'upgrade' => false,
                'existing_tables' => ['something_else'],
                'existing_logd_tables' => [],
            ];

            $installer = new Installer();

            $installer->stage7();

            $output = Output::getInstance()->getRawOutput();

            $this->assertFalse($_SESSION['dbinfo']['upgrade']);
            $this->assertStringContainsString("value='install' name='type' checked", $output);
            $this->assertStringNotContainsString("<select name='version'>", $output);
            $this->assertStringNotContainsString("value='upgrade'", $output);
            $this->assertStringContainsString('The database already contains tables, but none match the expected LoGD schema', $output);
        }

        public function testStage7HidesUpgradeCardWhenLogdTablesMissing(): void
        {
            $_SESSION['dbinfo'] = [
                'existing_tables' => ['unrelated_table'],
                'existing_logd_tables' => [],
            ];

            $installer = new Installer();

            $installer->stage7();

            $output = Output::getInstance()->getRawOutput();

            $this->assertSame('-1', $_SESSION['fromversion']);
            $this->assertFalse($_SESSION['dbinfo']['upgrade']);
            $this->assertStringNotContainsString('Perform an upgrade.', $output);
            $this->assertStringNotContainsString("value='upgrade'", $output);
        }

        public function testStage7IncludesDetectedVersionEvenWhenMissingFromLegacyMap(): void
        {
            $settings = Settings::getInstance();
            $settings->saveSetting('installer_version', '2.0.1');

            $_SESSION['dbinfo'] = [
                'upgrade' => false,
                'existing_tables' => ['logd_accounts'],
                'existing_logd_tables' => ['logd_accounts'],
            ];

            $installer = new Installer();

            $installer->stage7();

            $output = Output::getInstance()->getRawOutput();

            $this->assertTrue($_SESSION['dbinfo']['upgrade']);
            $this->assertSame('2.0.1', $_SESSION['fromversion']);
            $this->assertStringContainsString('<option value="2.0.1" selected>2.0.1</option>', $output);
        }

        public function testStage7ShowsUpgradeDropdownWhenLogdTablesPresent(): void
        {
            $_SESSION['dbinfo'] = [
                'existing_tables' => ['logd_accounts'],
                'existing_logd_tables' => ['logd_accounts'],
            ];

            $installer = new Installer();

            $installer->stage7();

            $output = Output::getInstance()->getRawOutput();

            $this->assertTrue($_SESSION['dbinfo']['upgrade']);
            $this->assertStringContainsString('Perform an upgrade.', $output);
            $this->assertStringContainsString("<select name='version'>", $output);
        }

        public function testStage7AfterStage5WithExistingDbconnectDefaultsToUpgrade(): void
        {
            $config = [
                'DB_HOST' => 'localhost',
                'DB_USER' => 'legacy_user',
                'DB_PASS' => 'legacy_pass',
                'DB_NAME' => 'lotgd',
                'DB_PREFIX' => 'lotgd_',
            ];

            file_put_contents(
                $this->dbconnectPath,
                "<?php\nreturn " . var_export($config, true) . ";\n"
            );

            Database::$mockResults = [
                [
                    ['Tables_in_lotgd' => 'lotgd_accounts'],
                ],
                [
                    ['Grants for user@localhost' => 'GRANT ALL PRIVILEGES'],
                ],
            ];

            $settings = new DummySettings(['charset' => 'UTF-8']);
            Settings::setInstance($settings);
            $GLOBALS['settings'] = $settings;

            global $session, $stage, $logd_version, $recommended_modules, $noinstallnavs, $DB_USEDATACACHE;

            $session['dbinfo'] = [
                'DB_HOST' => $config['DB_HOST'],
                'DB_USER' => $config['DB_USER'],
                'DB_PASS' => $config['DB_PASS'],
                'DB_NAME' => $config['DB_NAME'],
                'DB_PREFIX' => $config['DB_PREFIX'],
                'DB_USEDATACACHE' => false,
                'DB_DATACACHEPATH' => '',
            ];
            $_SESSION['dbinfo'] = $session['dbinfo'];
            $session['sure i want to overwrite the tables'] = false;
            $logd_version = '0.0.0';
            $recommended_modules = [];
            $noinstallnavs = [];
            $stage = 5;
            $DB_USEDATACACHE = false;

            $installer = new Installer();
            $installer->stage5();

            $mysqli = Database::getInstance();
            $this->assertSame([
                $config['DB_HOST'],
                $config['DB_USER'],
                $config['DB_PASS'],
            ], $mysqli->connectArgs);
            $this->assertContains('SHOW TABLES', Database::$queries);
            $this->assertSame(['lotgd_accounts'], $_SESSION['dbinfo']['existing_logd_tables']);
            $this->assertArrayHasKey('has_migration_metadata', $_SESSION['dbinfo']);
            $this->assertFalse($_SESSION['dbinfo']['has_migration_metadata']);

            $stage = 7;
            $_POST = [];
            $_GET = [];

            $installer->stage7();

            $output = Output::getInstance()->getRawOutput();

            $this->assertTrue($_SESSION['dbinfo']['upgrade']);
            $this->assertSame('2.0.0', $_SESSION['fromversion']);
            $this->assertSame(6, $_SESSION['stagecompleted']);
            $this->assertStringContainsString("value='upgrade' name='type' checked", $output);
            $this->assertStringContainsString("<select name='version'>", $output);
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
}
