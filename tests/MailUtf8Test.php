<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Mail;
use Lotgd\Sanitize;
use Lotgd\Tests\Stubs\MailDummySettings;
use PHPUnit\Framework\TestCase;

final class MailUtf8Test extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['accounts_table'] = [];
        $GLOBALS['mail_table'] = [];
        $GLOBALS['mail_sent_count'] = 0;
        $GLOBALS['settings_array'] = [
            'mailsizelimit' => 1024,
            'charset' => 'UTF-8',
            'serverurl' => 'http://example.com',
            'gameadminemail' => 'admin@example.com',
            'inboxlimit' => 50,
            'notificationmailsubject' => '{subject}',
            'notificationmailtext' => '{body}',
        ];
        $GLOBALS['settings'] = new MailDummySettings($GLOBALS['settings_array']);
        $GLOBALS['session'] = ['user' => ['acctid' => 1, 'prefs' => []]];
        $GLOBALS['forms_output'] = '';
        $_POST = [];
        $_GET = [];
    }

    public function testSystemMailStoresUtf8(): void
    {
        $GLOBALS['accounts_table'][2] = ['prefs' => serialize([]), 'emailaddress' => '', 'name' => '受信者'];
        $subject = 'こんにちは';
        $body = '世界🌟';

        Mail::systemMail(2, $subject, $body, 0, true);
        $stored = $GLOBALS['mail_table'][0];

        $this->assertSame($subject, $stored['subject']);
        $this->assertSame($body, $stored['body']);
        $this->assertTrue(mb_check_encoding($stored['subject'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($stored['body'], 'UTF-8'));
    }

    public function testMailSendStoresUtf8(): void
    {
        $GLOBALS['accounts_table'][2] = [
            'prefs' => serialize(['dirtyemail' => true]),
            'emailaddress' => '',
            'name' => '受信者',
            'login' => 'target',
        ];
        $subject = 'こんにちは';
        $body = '世界🌟';
        global $mail_send_accounts;
        $mail_send_accounts = ['target' => 2];
        require __DIR__ . '/Stubs/MailSendStubs.php';

        $_POST['to'] = 'target';
        $_POST['subject'] = $subject;
        $_POST['body'] = $body;

        mailSend();
        $stored = $GLOBALS['mail_table'][0];

        $this->assertSame($subject, $stored['subject']);
        $this->assertSame($body, $stored['body']);
        $this->assertTrue(mb_check_encoding($stored['subject'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($stored['body'], 'UTF-8'));
    }

    public function testSanitizeMbPreservesValidUtf8(): void
    {
        $str = 'こんにちは世界';
        $sanitized = Sanitize::sanitizeMb($str);
        $this->assertSame($str, $sanitized);
        $this->assertTrue(mb_check_encoding($sanitized, 'UTF-8'));
    }
}
