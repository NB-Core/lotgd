<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ErrorHandler;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerWarningDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        global $settings, $mail_sent_count, $output, $last_subject, $session;

        $mail_sent_count = 0;
        $last_subject = '';
        $settings = new DummySettings([
            'notify_address' => 'admin@example.com',
            'gameadminemail' => 'admin@example.com',
            'usedatacache' => 0,
        ]);
        $session = [
            'user' => [
                'superuser' => SU_DEBUG_OUTPUT,
            ],
        ];

        new PHPMailer();

        $output = new class {
            public function appoencode($data, $priv)
            {
                return $data;
            }
        };
    }

    public function testWarningNotificationIsDisabled(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        ob_start();
        ErrorHandler::handleError(E_WARNING, 'Test warning', 'file.php', 42);
        ob_end_clean();

        $this->assertSame(0, $GLOBALS['mail_sent_count']);
    }
}
