<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ErrorHandler;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerCliHostFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        global $settings, $mail_sent_count, $output, $last_subject;

        $mail_sent_count = 0;
        $last_subject = '';
        unset($_SERVER['HTTP_HOST']);
        $settings = new DummySettings([
            'notify_on_error' => 1,
            'notify_address' => 'admin@example.com',
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

    public function testCliHostFallbackIsUsed(): void
    {
        ErrorHandler::errorNotify(E_ERROR, 'msg', 'file.php', 1, '');

        $this->assertSame(1, $GLOBALS['mail_sent_count']);
        $this->assertSame('LotGD Error on CLI execution – hostname unavailable', $GLOBALS['last_subject']);
    }
}
