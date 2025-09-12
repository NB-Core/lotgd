<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Accounts;
use Lotgd\ExpireChars;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class NotifyExpirationAfterLoginTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        if (!class_exists('Lotgd\\Doctrine\\Bootstrap', false)) {
            require __DIR__ . '/Stubs/DoctrineBootstrap.php';
        }
        \Lotgd\MySQL\Database::$doctrineConnection = null;
        \Lotgd\MySQL\Database::$instance = null;
        \Lotgd\Tests\Stubs\DoctrineBootstrap::$conn = null;
        Database::$queries = [];
        Database::$mockResults = [];
        Database::$affected_rows = 0;

        Settings::setInstance(new DummySettings([
            'expireoldacct' => 45,
            'notifydaysbeforedeletion' => 5,
            'gameadminemail' => 'admin@example.com',
            'serverurl' => 'http://example.com',
        ]));

        $ref = new \ReflectionClass(ExpireChars::class);
        $prop = $ref->getProperty('settingsExtended');
        $prop->setAccessible(true);
        $prop->setValue(null, new DummySettings([
            'expirationnoticesubject' => 'Subject',
            'expirationnoticetext' => 'Body',
        ]));

        class_exists(\Lotgd\Tests\Stubs\PHPMailer::class);
        $GLOBALS['mail_sent_count'] = 0;
    }

    public function testUserReceivesNewWarningAfterLogin(): void
    {
        global $session, $baseaccount;

        $session = [
            'loggedin' => true,
            'allowednavs' => [],
            'bufflist' => [],
            'user' => [
                'acctid' => 1,
                'login' => 'tester',
                'emailaddress' => 'tester@example.com',
                'sentnotice' => 1,
                'allowednavs' => '',
                'bufflist' => '',
                'alive' => 1,
            ],
        ];
        $baseaccount = $session['user'];

        // Simulate login reset
        $session['user']['sentnotice'] = 0;
        Accounts::saveUser();

        $entity = Accounts::getAccountEntity();
        $this->assertSame(0, $entity->getSentnotice());

        Database::$queries = [];
        Database::$mockResults = [
            [
                ['login' => 'tester', 'acctid' => 1, 'emailaddress' => 'tester@example.com'],
            ],
            true,
        ];

        $ref = new \ReflectionClass(ExpireChars::class);
        $method = $ref->getMethod('notifyUpcomingExpirations');
        $method->setAccessible(true);
        $method->invoke(null);

        $this->assertSame(1, $GLOBALS['mail_sent_count']);
        $this->assertStringContainsString('sentnotice=1', end(Database::$queries));
    }
}
