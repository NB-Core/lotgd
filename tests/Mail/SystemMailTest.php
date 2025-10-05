<?php

declare(strict_types=1);

namespace Lotgd\Tests\Mail;

use Lotgd\Mail;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\DoctrineConnection;
use Lotgd\Tests\Stubs\MailDummySettings;
use PHPUnit\Framework\TestCase;

final class SystemMailTest extends TestCase
{
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

        $settings = new MailDummySettings($GLOBALS['settings_array']);
        Settings::setInstance($settings);
        $GLOBALS['settings'] = $settings;

        $ref = new \ReflectionClass(Mail::class);
        $prop = $ref->getProperty('settings');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        unset($GLOBALS['mail_force_error'], $GLOBALS['mail_force_error_message']);
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
    }
}
