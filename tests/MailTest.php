<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Mail;
use Lotgd\Tests\Stubs\Database;
use Lotgd\Tests\Stubs\MailDummySettings;
use Lotgd\Tests\Stubs\PHPMailer;
use Lotgd\Settings;
use PHPUnit\Framework\TestCase;

final class MailTest extends TestCase
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
            'inboxlimit' => 50,
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
        // Reset Mail's cached settings between tests
        $ref = new \ReflectionClass(\Lotgd\Mail::class);
        $prop = $ref->getProperty('settings');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        unset($GLOBALS['mail_force_error'], $GLOBALS['mail_force_error_message']);
    }

    public function testSystemMailStoresMessageAndSkipsInvalidEmail(): void
    {
        $GLOBALS['accounts_table'][1] = [
            'prefs' => serialize(['emailonmail' => true, 'systemmail' => true]),
            'emailaddress' => 'invalid-email',
            'name' => 'User1'
        ];
        Mail::systemMail(1, 'Subject', 'Body', 0);
        $this->assertCount(1, $GLOBALS['mail_table']);
        $this->assertSame('Subject', $GLOBALS['mail_table'][0]['subject']);
        $this->assertSame(0, $GLOBALS['mail_sent_count']);
    }

    public function testSystemMailSendsNotificationWhenEmailAllowed(): void
    {
        $GLOBALS['accounts_table'][1] = [
            'prefs' => serialize(['emailonmail' => true]),
            'emailaddress' => 'sender@example.com',
            'name' => 'Sender'
        ];
        $GLOBALS['accounts_table'][2] = [
            'prefs' => serialize(['emailonmail' => true]),
            'emailaddress' => 'player@example.com',
            'name' => 'Recipient'
        ];

        $GLOBALS['settings_array']['soap'] = 0;
        Settings::getInstance()->saveSetting('soap', 0);

        Mail::systemMail(2, 'Subject', 'Body', 1);

        $this->assertSame(1, $GLOBALS['mail_sent_count']);
        $this->assertCount(1, $GLOBALS['mail_table']);

        $mailRecord = $GLOBALS['mail_table'][0];
        $this->assertSame(1, $mailRecord['msgfrom']);
        $this->assertSame(2, $mailRecord['msgto']);
        $this->assertSame('Subject', $mailRecord['subject']);
        $this->assertSame('Body', $mailRecord['body']);
    }

    public function testInboxCountAndFull(): void
    {
        $GLOBALS['settings_array']['inboxlimit'] = 3;
        $settings = new MailDummySettings($GLOBALS['settings_array']);
        Settings::setInstance($settings);
        $GLOBALS['settings'] = $settings;
        $GLOBALS['mail_table'] = [
            ['messageid' => 1, 'msgfrom' => 0, 'msgto' => 1, 'subject' => 'a', 'body' => 'b', 'sent' => 't', 'seen' => 0],
            ['messageid' => 2, 'msgfrom' => 0, 'msgto' => 1, 'subject' => 'c', 'body' => 'd', 'sent' => 't', 'seen' => 1],
            ['messageid' => 3, 'msgfrom' => 0, 'msgto' => 1, 'subject' => 'e', 'body' => 'f', 'sent' => 't', 'seen' => 0],
        ];
        $this->assertSame(3, Mail::inboxCount(1));
        $this->assertSame(2, Mail::inboxCount(1, true));
        $this->assertTrue(Mail::isInboxFull(1));
    }

    public function testSendDetailedSuccessReturnsStructuredResult(): void
    {
        $result = Mail::send(
            ['player@example.com' => 'Player'],
            'Body',
            'Subject',
            ['admin@example.com' => 'Admin'],
            false,
            'text/plain',
            true
        );

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('', $result['error']);
        $this->assertSame(1, $GLOBALS['mail_sent_count']);
    }

    public function testSendDetailedFailureIncludesErrorInfo(): void
    {
        $GLOBALS['mail_force_error'] = true;
        $GLOBALS['mail_force_error_message'] = 'Simulated failure';

        $result = Mail::send(
            ['player@example.com' => 'Player'],
            'Body',
            'Subject',
            ['admin@example.com' => 'Admin'],
            false,
            'text/plain',
            true
        );

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame('Simulated failure', $result['error']);
        $this->assertSame(0, $GLOBALS['mail_sent_count']);

        $GLOBALS['mail_force_error'] = true;
        $GLOBALS['mail_force_error_message'] = 'Simulated failure';

        $boolResult = Mail::send(
            ['player@example.com' => 'Player'],
            'Body',
            'Subject',
            ['admin@example.com' => 'Admin']
        );

        $this->assertFalse($boolResult);
    }
}
