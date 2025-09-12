<?php

declare(strict_types=1);

namespace Lotgd\Tests;

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
final class NotifyUpcomingExpirationsQueryTest extends TestCase
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

    public function testQueryTargetsPendingAccounts(): void
    {
        Database::$mockResults = [
            [
                ['login' => 'tester', 'acctid' => 1, 'emailaddress' => 'tester@example.com'],
            ],
            true,
        ];

        $method = new \ReflectionMethod(ExpireChars::class, 'notifyUpcomingExpirations');
        $method->setAccessible(true);
        $method->invoke(null);

        $expectedDate = date('Y-m-d H:i:s', strtotime('-40 days'));
        $expected = 'SELECT login,acctid,emailaddress FROM accounts'
            . " WHERE (laston < '$expectedDate')"
            . " AND emailaddress!='' AND sentnotice=0 AND (superuser&" . NO_ACCOUNT_EXPIRATION . ')=0';

        $this->assertSame($expected, Database::$queries[0]);
        $this->assertSame(1, $GLOBALS['mail_sent_count']);
        $this->assertStringContainsString('sentnotice=1', Database::$queries[1]);
    }
}
