<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

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
final class Stage10Test extends TestCase
{
    private DummySettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2) . '/install/lib/Installer.php';

        class_exists(Database::class);
        Database::$mockResults = [];
        Database::$queries = [];
        Database::$affected_rows = 0;

        $this->settings = new DummySettings(['charset' => 'UTF-8']);
        Settings::setInstance($this->settings);
        $GLOBALS['settings'] = $this->settings;

        Output::getInstance();

        global $session, $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;
        $session             = [];
        $logd_version        = '0.0.0';
        $recommended_modules = [];
        $noinstallnavs       = [];
        $stage               = 10;
        $DB_USEDATACACHE     = false;

        $_POST = [];
        $_GET  = [];
    }

    protected function tearDown(): void
    {
        Database::$mockResults = [];
        Database::$queries = [];
        Database::$affected_rows = 0;

        Settings::setInstance(null);
        unset($GLOBALS['settings']);

        parent::tearDown();
    }

    public function testStage10CreatesSuperuserWhenPasswordsMatch(): void
    {
        Database::$mockResults = [
            [],    // SELECT returns zero rows
            true,  // DELETE result
            true,  // INSERT result
        ];
        Database::$affected_rows = 1;

        $_POST = [
            'name'  => 'Admin',
            'pass1' => 'secret',
            'pass2' => 'secret',
        ];

        $installer = new Installer();
        $installer->stage10();

        $queries = Database::$queries;
        $this->assertGreaterThanOrEqual(3, count($queries));
        $this->assertStringContainsString('INSERT INTO accounts', $queries[2]);

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('Your superuser account has been created', $output);
    }

    public function testStage10RejectsMismatchedPasswords(): void
    {
        Database::$mockResults = [
            [],
        ];

        $_POST = [
            'name'  => 'Admin',
            'pass1' => 'alpha',
            'pass2' => 'beta',
        ];

        $installer = new Installer();
        $installer->stage10();

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString("Oops, your passwords don't match", $output);

        foreach (Database::$queries as $query) {
            $this->assertStringNotContainsString('INSERT INTO accounts', $query);
        }
    }

    public function testStage10SkipsCreationWhenSuperuserAlreadyExists(): void
    {
        Database::$mockResults = [
            [
                ['login' => 'Admin', 'password' => 'hash'],
            ],
        ];

        $_POST = [];

        $installer = new Installer();
        $installer->stage10();

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('You already have a superuser account', $output);

        $this->assertCount(1, Database::$queries);
        $this->assertStringContainsString('SELECT login, password FROM accounts', Database::$queries[0]);
    }
}
