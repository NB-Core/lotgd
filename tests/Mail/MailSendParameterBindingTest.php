<?php

declare(strict_types=1);

namespace Lotgd\Tests\Mail;

use Doctrine\DBAL\ParameterType;
use Lotgd\DataCache;
use Lotgd\MySQL\Database;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\DoctrineBootstrap;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\MailDummySettings;
use PHPUnit\Framework\TestCase;

/**
 * @runClassInSeparateProcess
 */
final class MailSendParameterBindingTest extends TestCase
{
    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/DoctrineBootstrap.php';

        Database::$doctrineConnection = null;
        Database::$instance = null;
        Database::$mockResults = [];
        Database::$queries = [];
        DoctrineBootstrap::$conn = null;

        $this->connection = Database::getDoctrineConnection();
        $this->connection->fetchAssociativeResults = [];
        $this->connection->lastFetchAssociativeParams = [];
        $this->connection->lastFetchAssociativeTypes = [];
        $this->connection->executeQueryParams = [];
        $this->connection->executeQueryTypes = [];
        $this->connection->queries = [];

        global $session, $mail_table, $accounts_table, $mail_sent_count, $op, $id;
        $session = ['user' => ['acctid' => 123]];
        $op = '';
        $id = 0;
        $mail_table = [];
        $mail_sent_count = 0;
        $accounts_table = [];

        Database::setPrefix('');

        $settings = new MailDummySettings([
            'mailsizelimit' => 1024,
            'charset' => 'UTF-8',
            'inboxlimit' => 50,
            'onlyunreadmails' => true,
            'soap' => 0,
            'usedatacache' => 0,
            'datacachepath' => sys_get_temp_dir(),
            'serverurl' => 'http://example.com',
            'gameadminemail' => 'admin@example.com',
            'notificationmailsubject' => '{subject}',
            'notificationmailtext' => '{body}',
        ]);

        Settings::setInstance($settings);
        $GLOBALS['settings'] = $settings;

        DataCache::getInstance();

        $_POST = [];
        $_GET = [];

        $this->addAccount(123, 'Sender');
    }

    protected function tearDown(): void
    {
        Settings::setInstance(null);
        unset($GLOBALS['settings']);
    }

    private function addAccount(int $acctid, string $name): void
    {
        global $accounts_table;

        $accounts_table[$acctid] = [
            'prefs' => serialize([]),
            'emailaddress' => '',
            'name' => $name,
        ];
    }

    private function executeMailSend(): void
    {
        if (! function_exists('mailSend')) {
            require __DIR__ . '/case_send_fixture.php';

            return;
        }

        mailSend();
    }

    public function testMailSendBindsQuotedLogin(): void
    {
        $login = "O'Brien";
        $acctid = 2001;

        $this->addAccount($acctid, 'Quoted Recipient');
        $this->connection->fetchAssociativeResults[] = ['acctid' => $acctid];

        $_POST = [
            'to' => $login,
            'subject' => 'Hello',
            'body' => 'Greetings',
            'returnto' => '0',
        ];

        $this->executeMailSend();

        $this->assertSame(['login' => $login], $this->connection->lastFetchAssociativeParams);
        $this->assertSame(['login' => ParameterType::STRING], $this->connection->lastFetchAssociativeTypes);

        $inserted = array_filter(
            Database::$queries,
            static fn (string $sql): bool => strpos($sql, 'INSERT INTO mail') !== false
        );

        $this->assertNotEmpty($inserted, 'Expected mail insert query to be executed.');
    }

    public function testMailSendBindsMultibyteLogin(): void
    {
        $login = '受信者';
        $acctid = 2002;

        $this->addAccount($acctid, '受信者');
        $this->connection->fetchAssociativeResults[] = ['acctid' => $acctid];

        $_POST = [
            'to' => $login,
            'subject' => 'こんにちは',
            'body' => '世界🌟',
            'returnto' => '0',
        ];

        $this->executeMailSend();

        $this->assertSame(['login' => $login], $this->connection->lastFetchAssociativeParams);
        $this->assertSame(['login' => ParameterType::STRING], $this->connection->lastFetchAssociativeTypes);

        $inserted = array_filter(
            Database::$queries,
            static fn (string $sql): bool => strpos($sql, 'INSERT INTO mail') !== false
        );

        $this->assertNotEmpty($inserted, 'Expected mail insert query to be executed.');
    }
}
