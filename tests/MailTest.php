<?php

declare(strict_types=1);

namespace {
    use PHPUnit\Framework\TestCase;
    use Lotgd\Tests\Stubs\Database;
    use Lotgd\Tests\Stubs\PHPMailer;
    use Lotgd\Tests\Stubs\MailDummySettings;
    use Lotgd\Mail;

    require_once __DIR__ . '/../config/constants.php';
    require_once __DIR__ . '/../lib/settings.php';

    // --- Stubs and helper globals ---

// Simple in-memory tables
    $GLOBALS['accounts_table'] = [];
    $GLOBALS['mail_table'] = [];
    $GLOBALS['settings_array'] = [
    'mailsizelimit' => 1024,
    'charset' => 'UTF-8',
    'serverurl' => 'http://example.com',
    'gameadminemail' => 'admin@example.com',
    'inboxlimit' => 50,
    'notificationmailsubject' => '{subject}',
    'notificationmailtext' => '{body}',
    ];
}

namespace {
    if (!function_exists('invalidatedatacache')) {
        function invalidatedatacache(string $name)
        {
        }
    }
    if (!function_exists('full_sanitize')) {
        function full_sanitize($in)
        {
            return $in;
        }
    }
    if (!function_exists('translate_inline')) {
        function translate_inline($text, $ns = false)
        {
            return $text;
        }
    }
    if (!function_exists('translate_mail')) {
        function translate_mail($text, $to = 0)
        {
            return $text;
        }
    }
    if (!function_exists('soap')) {
        function soap($input, $debug = false, $skiphook = false)
        {
            return $input;
        }
    }
    if (!function_exists('output')) {
        function output(string $format, ...$args)
        {
        }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;
    use Lotgd\Mail;
    use Lotgd\Tests\Stubs\Database;
    use Lotgd\Tests\Stubs\PHPMailer;
    use Lotgd\Tests\Stubs\MailDummySettings;


    final class MailTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['accounts_table'] = [];
            $GLOBALS['mail_table'] = [];
            $GLOBALS['mail_sent_count'] = 0;
            $GLOBALS['settings'] = new MailDummySettings($GLOBALS['settings_array']);
        }

        public function testSystemMailStoresMessageAndSkipsInvalidEmail(): void
        {
            $GLOBALS['accounts_table'][1] = [
            'prefs' => serialize(['emailonmail' => true,'systemmail' => true]),
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
            $GLOBALS['settings'] = new MailDummySettings($GLOBALS['settings_array']);
            $GLOBALS['mail_table'] = [
            ['messageid' => 1,'msgfrom' => 0,'msgto' => 1,'subject' => 'a','body' => 'b','sent' => 't','seen' => 0],
            ['messageid' => 2,'msgfrom' => 0,'msgto' => 1,'subject' => 'c','body' => 'd','sent' => 't','seen' => 1],
            ['messageid' => 3,'msgfrom' => 0,'msgto' => 1,'subject' => 'e','body' => 'f','sent' => 't','seen' => 0],
            ];
            $this->assertSame(3, Mail::inboxCount(1));
            $this->assertSame(2, Mail::inboxCount(1, true));
            $this->assertTrue(Mail::isInboxFull(1));
        }
    }
}
