<?php

declare(strict_types=1);

namespace Lotgd\Tests\Mail;

use Doctrine\DBAL\ParameterType;
use Lotgd\Mail;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

final class SystemMailTest extends TestCase
{
    private DoctrineConnection $connection;

    protected function setUp(): void
    {
        $GLOBALS['accounts_table'] = [];
        $GLOBALS['mail_table'] = [];
        $GLOBALS['mail_sent_count'] = 0;
        \Lotgd\MySQL\Database::$doctrineConnection = null;
        \Lotgd\MySQL\Database::$instance = null;
        \Lotgd\MySQL\Database::$lastSql = '';
        if (class_exists('Lotgd\\Tests\\Stubs\\DoctrineBootstrap', false)) {
            \Lotgd\Tests\Stubs\DoctrineBootstrap::$conn = null;
        }

        $GLOBALS['settings_array'] = [
            'mailsizelimit' => 1024,
            'charset' => 'UTF-8',
            'serverurl' => 'http://example.com',
            'gameadminemail' => 'admin@example.com',
            'notificationmailsubject' => '{subject}',
            'notificationmailtext' => '{body}',
        ];
        \Lotgd\MySQL\Database::$settings_extended_table = [
            'notificationmailsubject' => '{subject}',
            'notificationmailtext' => '{body}',
        ];

        $settings = new DummySettings($GLOBALS['settings_array']);
        Settings::setInstance($settings);
        $GLOBALS['settings'] = $settings;

        $this->connection = new DoctrineConnection();
        Database::setDoctrineConnection($this->connection);

        Mail::resetSettings();

        unset($GLOBALS['mail_force_error'], $GLOBALS['mail_force_error_message']);
    }

    protected function tearDown(): void
    {
        Database::resetDoctrineConnection();
    }

    public function testWithRealtimeOnTheRecipientsPageLearnsOfTheMail(): void
    {
        $GLOBALS['accounts_table'][7] = ['prefs' => serialize([]), 'emailaddress' => '', 'name' => 'Target User'];
        $settings = new DummySettings($GLOBALS['settings_array'] + ['realtime_enabled' => 1]);
        Settings::setInstance($settings);
        $GLOBALS['settings'] = $settings;

        Mail::systemMail(7, 'Hello', 'Body', 0);

        $sql = array_column($this->connection->executeStatements, 'sql');
        $mail = array_keys(preg_grep('/^INSERT INTO mail/', $sql));
        $event = array_keys(preg_grep('/^INSERT INTO events/', $sql));
        self::assertCount(1, $event, 'One announcement for the one mail.');
        self::assertGreaterThan($mail[0], $event[0], 'Announced after the mail row exists.');
        $params = $this->connection->executeStatements[$event[0]]['params'];
        self::assertSame('user-7', $params['channel']);
        self::assertSame(['region' => 'mail', 'version' => 1], json_decode($params['payload'], true), 'The version is the new message id.');
    }

    public function testWithRealtimeOffNoEventIsWritten(): void
    {
        $GLOBALS['accounts_table'][7] = ['prefs' => serialize([]), 'emailaddress' => '', 'name' => 'Target User'];

        Mail::systemMail(7, 'Hello', 'Body', 0);

        self::assertSame([], preg_grep('/^INSERT INTO events/', array_column($this->connection->executeStatements, 'sql')));
    }

    public function testSystemMailPersistsMultibyteAndQuoteCharacters(): void
    {
        $GLOBALS['accounts_table'][7] = [
            'prefs' => serialize(['emailonmail' => false]),
            'emailaddress' => 'player@example.com',
            'name' => 'Target User',
        ];

        $subject = "Ron''s \"Subject\" 😃";
        $body = "He said, \"Hello!\" 😃 and added: it's fine.";

        Mail::systemMail(7, $subject, $body, 0);

        $this->assertCount(1, $GLOBALS['mail_table']);
        $record = $GLOBALS['mail_table'][0];
        $this->assertSame(0, $record['msgfrom']);
        $this->assertSame(7, $record['msgto']);
        $this->assertSame($subject, $record['subject']);
        $this->assertSame($body, $record['body']);

        $conn = Database::getDoctrineConnection();
        $this->assertInstanceOf(DoctrineConnection::class, $conn);
        $this->assertNotEmpty($conn->executeStatements);
        $params = $conn->executeStatements[0]['params'];
        $this->assertSame($subject, $params['subject']);
        $this->assertSame($body, $params['body']);
        $this->assertSame(0, $params['msgfrom']);
        $this->assertSame(7, $params['msgto']);
        $this->assertSame($record['sent'], $params['sent']);

        $this->assertNotEmpty($this->connection->fetchAssociativeLog);
        $firstFetch = $this->connection->fetchAssociativeLog[0];
        $this->assertSame(['acctid' => 7], $firstFetch['params']);
        $this->assertSame(['acctid' => ParameterType::INTEGER], $firstFetch['types']);
    }
}
