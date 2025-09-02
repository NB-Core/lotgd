<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ErrorHandler;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerArrayMessageTest extends TestCase
{
    protected function setUp(): void
    {
        global $settings, $mail_sent_count, $output, $last_subject;

        $mail_sent_count = 0;
        $last_subject = '';
        $settings = new DummySettings([
            'notify_address' => 'admin@example.com',
            'gameadminemail' => 'admin@example.com',
            'usedatacache' => 0,
        ]);

        // Ensure the PHPMailer stub is loaded so Mail::send uses it
        new PHPMailer();

        // Provide minimal output handler for debug()
        $output = new class {
            public function appoencode($data, $priv)
            {
                return $data;
            }
        };
    }

    public function testArrayMessageWarningNotificationIsSent(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        ErrorHandler::errorNotify(E_WARNING, ['msg' => 'array'], 'file.php', 1, '<trace>');

        $this->assertSame(1, $GLOBALS['mail_sent_count']);
        $this->assertSame('LotGD Warning on example.com', $GLOBALS['last_subject']);
    }
}
