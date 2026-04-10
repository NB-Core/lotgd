<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ExpireChars;
use Lotgd\MySQL\Database as CoreDatabase;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\DbMysqli;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class NotifyUpcomingExpirationsQueryTest extends TestCase
{
    protected function setUp(): void
    {
        class_exists(DbMysqli::class);
        class_exists(Database::class);
        if (!class_exists('Lotgd\\Doctrine\\Bootstrap', false)) {
            require __DIR__ . '/Stubs/DoctrineBootstrap.php';
        }
        CoreDatabase::resetDoctrineConnection();
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

        ExpireChars::setSettingsExtendedForTests(new DummySettings([
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
        ];
        $connection = CoreDatabase::getDoctrineConnection();
        $connection->queries = [];
        $connection->executeStatements = [];

        ExpireChars::notifyUpcomingExpirationsForTests();

        $expected = 'SELECT login,acctid,emailaddress FROM accounts'
            . ' WHERE (laston < :threshold)'
            . " AND emailaddress != '' AND sentnotice = :sentNotice AND (superuser & :noAccountExpiration) = 0";

        $this->assertSame($expected, $connection->queries[0] ?? null);
        $this->assertSame(1, $GLOBALS['mail_sent_count']);
        $this->assertStringContainsString('sentnotice = :sentNotice', $connection->queries[1] ?? '');
    }
}
