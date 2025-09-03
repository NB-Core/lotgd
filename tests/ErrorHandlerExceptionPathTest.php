<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ErrorHandler;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerExceptionPathTest extends TestCase
{
    protected function setUp(): void
    {
        global $settings, $mail_sent_count, $output, $last_subject;

        $mail_sent_count = 0;
        $last_subject = '';
        $settings = new DummySettings([
            'notify_on_error' => 1,
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

    public function testExceptionSendsNotification(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        ob_start();
        @ErrorHandler::handleException(new \RuntimeException('boom'));
        ob_end_clean();

        $this->assertSame(1, $GLOBALS['mail_sent_count']);
        $this->assertSame('LotGD Error on example.com', $GLOBALS['last_subject']);
    }
}
