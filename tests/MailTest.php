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
        $settings = new MailDummySettings($GLOBALS['settings_array']);
        Settings::setInstance($settings);
        $GLOBALS['settings'] = $settings;
        // Reset Mail's cached settings between tests
        $ref = new \ReflectionClass(\Lotgd\Mail::class);
        $prop = $ref->getProperty('settings');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
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
}
