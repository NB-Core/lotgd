<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ErrorHandler;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerMultiAddressTest extends TestCase
{
    protected function setUp(): void
    {
        global $settings, $mail_sent_count, $output, $last_subject, $forms_output;

        $mail_sent_count = 0;
        $last_subject = '';
        $forms_output = '';
        $settings = new DummySettings([
            'notify_on_error' => 1,
            'notify_address' => 'one@example.com; two@example.com',
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

    public function testErrorNotificationIsSentToAllAddresses(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        ErrorHandler::errorNotify(E_ERROR, 'Test error', 'file.php', 42, '<trace>');

        $this->assertSame(2, $GLOBALS['mail_sent_count']);
        $this->assertStringContainsString('Notifying one@example.com of this error.', $GLOBALS['forms_output']);
        $this->assertStringContainsString('Notifying two@example.com of this error.', $GLOBALS['forms_output']);
    }
}
