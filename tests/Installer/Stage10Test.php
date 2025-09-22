<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use Lotgd\Installer\Installer;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DoctrineBootstrap;
use Lotgd\Tests\Stubs\DoctrineConnection;
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
        require_once __DIR__ . '/../Stubs/DoctrineBootstrap.php';

        class_exists(Database::class);
        Database::$mockResults = [];
        Database::$queries = [];
        Database::$affected_rows = 0;
        Database::$doctrineConnection = null;

        DoctrineBootstrap::$conn = null;

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
        Database::$doctrineConnection = null;

        if (class_exists(DoctrineBootstrap::class, false)) {
            DoctrineBootstrap::$conn = null;
        }

        Settings::setInstance(null);
        unset($GLOBALS['settings']);

        parent::tearDown();
    }

    public function testStage10CreatesSuperuserWhenPasswordsMatch(): void
    {
        Database::$mockResults = [
            [],    // SELECT returns zero rows
        ];

        $connection = new DoctrineConnection();
        DoctrineBootstrap::$conn = $connection;
        Database::$doctrineConnection = null;

        $_POST = [
            'name'  => 'Admin',
            'pass1' => 'secret',
            'pass2' => 'secret',
        ];

        $installer = new Installer();
        $installer->stage10();

        $queries = Database::$queries;
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('SELECT login, password FROM accounts', $queries[0]);

        $this->assertNotEmpty($connection->queries);
        $this->assertSame('DELETE FROM accounts WHERE login = ?', $connection->queries[0]);
        $this->assertSame(
            'INSERT INTO accounts (login, password, superuser, name, playername, ctitle, title, regdate, badguy, companions, allowednavs, restorepage, bufflist, dragonpoints, prefs, donationconfig, specialinc, specialmisc, emailaddress, replaceemail, emailvalidation, hauntedby, bio) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $connection->queries[1]
        );

        $expectedPrivileges = SU_MEGAUSER | SU_EDIT_MOUNTS | SU_EDIT_CREATURES |
            SU_EDIT_PETITIONS | SU_EDIT_COMMENTS | SU_EDIT_DONATIONS |
            SU_EDIT_USERS | SU_EDIT_CONFIG | SU_INFINITE_DAYS |
            SU_EDIT_EQUIPMENT | SU_EDIT_PAYLOG | SU_DEVELOPER |
            SU_POST_MOTD | SU_MODERATE_CLANS | SU_EDIT_RIDDLES |
            SU_MANAGE_MODULES | SU_AUDIT_MODERATION | SU_RAW_SQL |
            SU_VIEW_SOURCE | SU_NEVER_EXPIRE;

        $this->assertSame('accounts', $connection->lastDelete['table']);
        $this->assertSame(['login' => 'Admin'], $connection->lastDelete['criteria']);

        $this->assertSame('accounts', $connection->lastInsert['table']);
        $this->assertSame('Admin', $connection->lastInsert['data']['login']);
        $this->assertSame(md5(md5('secret')), $connection->lastInsert['data']['password']);
        $this->assertSame($expectedPrivileges, $connection->lastInsert['data']['superuser']);
        $this->assertSame('`%Admin `&Admin`0', $connection->lastInsert['data']['name']);
        $this->assertSame('`%Admin `&Admin`0', $connection->lastInsert['data']['playername']);
        $this->assertSame('`%Admin', $connection->lastInsert['data']['ctitle']);
        $this->assertSame(serialize(['village.php' => true]), $connection->lastInsert['data']['allowednavs']);
        $this->assertSame('village.php', $connection->lastInsert['data']['restorepage']);
        $this->assertSame('', $connection->lastInsert['data']['bio']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $connection->lastInsert['data']['regdate']
        );

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('Your superuser account has been created', $output);
    }

    public function testStage10RejectsMismatchedPasswords(): void
    {
        Database::$mockResults = [
            [],
        ];

        $connection = new DoctrineConnection();
        DoctrineBootstrap::$conn = $connection;
        Database::$doctrineConnection = null;

        $_POST = [
            'name'  => 'Admin',
            'pass1' => 'alpha',
            'pass2' => 'beta',
        ];

        $installer = new Installer();
        $installer->stage10();

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString("Oops, your passwords don't match", $output);

        $this->assertEmpty($connection->lastInsert);
    }

    public function testStage10SkipsCreationWhenSuperuserAlreadyExists(): void
    {
        Database::$mockResults = [
            [
                ['login' => 'Admin', 'password' => 'hash'],
            ],
        ];

        $connection = new DoctrineConnection();
        DoctrineBootstrap::$conn = $connection;
        Database::$doctrineConnection = null;

        $_POST = [];

        $installer = new Installer();
        $installer->stage10();

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('You already have a superuser account', $output);

        $this->assertCount(1, Database::$queries);
        $this->assertStringContainsString('SELECT login, password FROM accounts', Database::$queries[0]);

        $this->assertEmpty($connection->queries);
    }

    public function testStage10AllowsApostrophesInLogin(): void
    {
        Database::$mockResults = [
            [],
        ];

        $connection = new DoctrineConnection();
        DoctrineBootstrap::$conn = $connection;
        Database::$doctrineConnection = null;

        $_POST = [
            'name'  => "O'Connor",
            'pass1' => 'secret',
            'pass2' => 'secret',
        ];

        $installer = new Installer();
        $installer->stage10();

        $this->assertSame("O'Connor", $connection->lastInsert['data']['login']);
        $this->assertSame("`%Admin `&O'Connor`0", $connection->lastInsert['data']['name']);

        $output = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('Your superuser account has been created', $output);
    }
}
