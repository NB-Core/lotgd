<?php
declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../bootstrap.php';
    require_once __DIR__ . '/../Stubs/DoctrineBootstrap.php';
}

namespace Lotgd\Tests\Mail {

use Doctrine\DBAL\ParameterType;
use Lotgd\DataCache;
use Lotgd\MySQL\Database;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\DoctrineBootstrap;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\MailDummySettings;
use PHPUnit\Framework\TestCase;
use function Lotgd\Tests\Mail\Fixture\mailSend as fixtureMailSend;

final class MailSendParameterBindingTest extends TestCase
{
    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        Database::$doctrineConnection = null;
        Database::$instance = null;
        Database::$mockResults = [];
        Database::$queries = [];
        DoctrineBootstrap::$conn = null;

        $this->connection = new DoctrineConnection();
        Database::setDoctrineConnection($this->connection);
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
        Database::resetDoctrineConnection();
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
        if (! function_exists('Lotgd\\Tests\\Mail\\Fixture\\mailSend')) {
            require __DIR__ . '/case_send_fixture.php';
        }

        fixtureMailSend();
    }

    private function assertMailInsertIssued(): void
    {
        $inserted = array_filter(
            $this->connection->executeStatements,
            static fn (array $entry): bool => isset($entry['sql'])
                && stripos($entry['sql'], 'INSERT INTO') === 0
                && strpos($entry['sql'], 'mail') !== false
        );

        $this->assertNotEmpty($inserted, 'Expected mail insert query to be executed.');
    }

    public function testMailSendBindsQuotedLogin(): void
    {
        $login = "O'Brien";
        $acctid = 2001;

        $this->addAccount($acctid, 'Quoted Recipient');
        $this->connection->fetchAssociativeResults[] = ['acctid' => $acctid];

        $body = 'He said "Hi" and it\'s fine.';
        $_POST = [
            'to' => $login,
            'subject' => 'Hello',
            'body' => $body,
            'returnto' => '0',
        ];

        $this->executeMailSend();

        $loginFetch = $this->findFetchAssociativeEntry('WHERE login = :login');
        $this->assertNotNull($loginFetch, 'Expected login lookup query to be executed.');
        $this->assertSame(['login' => $login], $loginFetch['params']);
        $this->assertSame(['login' => ParameterType::STRING], $loginFetch['types']);

        $this->assertMailInsertIssued();

        global $mail_table;
        $this->assertNotEmpty($mail_table, 'Expected mail to be stored.');
        $this->assertSame($body, $mail_table[0]['body']);
        $this->assertSame($body, $this->connection->lastExecuteStatementParams['body'] ?? null);
    }

    public function testMailSendBindsMultibyteLogin(): void
    {
        $login = '受信者';
        $acctid = 2002;

        $this->addAccount($acctid, '受信者');
        $this->connection->fetchAssociativeResults[] = ['acctid' => $acctid];

        $body = '世界🌟からの"こんにちは"';
        $_POST = [
            'to' => $login,
            'subject' => 'こんにちは',
            'body' => $body,
            'returnto' => '0',
        ];

        $this->executeMailSend();

        $loginFetch = $this->findFetchAssociativeEntry('WHERE login = :login');
        $this->assertNotNull($loginFetch, 'Expected login lookup query to be executed.');
        $this->assertSame(['login' => $login], $loginFetch['params']);
        $this->assertSame(['login' => ParameterType::STRING], $loginFetch['types']);

        $this->assertMailInsertIssued();

        global $mail_table;
        $this->assertNotEmpty($mail_table, 'Expected mail to be stored.');
        $this->assertSame($body, $mail_table[0]['body']);
        $this->assertSame($body, $this->connection->lastExecuteStatementParams['body'] ?? null);
    }

    public function testMailBodyRespectsConfiguredLimit(): void
    {
        $login = 'TruncateUser';
        $acctid = 2003;

        $this->addAccount($acctid, 'Truncate Recipient');
        $this->connection->fetchAssociativeResults[] = ['acctid' => $acctid];

        $body = 'こんにちは世界🌟';
        $expected = 'こんにちは';

        Settings::getInstance()->saveSetting('mailsizelimit', 5);

        $_POST = [
            'to' => $login,
            'subject' => 'Limit Test',
            'body' => $body,
            'returnto' => '0',
        ];

        $this->executeMailSend();

        $loginFetch = $this->findFetchAssociativeEntry('WHERE login = :login');
        $this->assertNotNull($loginFetch, 'Expected login lookup query to be executed.');
        $this->assertSame(['login' => $login], $loginFetch['params']);
        $this->assertSame(['login' => ParameterType::STRING], $loginFetch['types']);

        $this->assertMailInsertIssued();

        global $mail_table;
        $this->assertNotEmpty($mail_table, 'Expected mail to be stored.');
        $this->assertSame($expected, $mail_table[0]['body']);
        $this->assertSame($expected, $this->connection->lastExecuteStatementParams['body'] ?? null);
    }

    private function findFetchAssociativeEntry(string $needle): ?array
    {
        foreach (array_reverse($this->connection->fetchAssociativeLog) as $entry) {
            if (str_contains($entry['sql'], $needle)) {
                return $entry;
            }
        }

        return null;
    }
}

}
