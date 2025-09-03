<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ErrorHandler;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerNumericMessageTest extends TestCase
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

    public function testNumericMessageWarningNotificationIsSentWithoutJsonWarnings(): void
    {
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = [$errno, $errstr];
            return true;
        }, E_WARNING);

        $_SERVER['HTTP_HOST'] = 'example.com';
        try {
            ErrorHandler::errorNotify(E_WARNING, 123, 'file.php', 1, '<trace>');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(1, $GLOBALS['mail_sent_count']);
        $this->assertSame('LotGD Warning on example.com', $GLOBALS['last_subject']);
        $this->assertEmpty($warnings);
    }

    public function testJsonSerializableMessageWarningNotificationIsSentWithoutJsonWarnings(): void
    {
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = [$errno, $errstr];
            return true;
        }, E_WARNING);

        $message = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['msg' => 'json'];
            }
        };

        $_SERVER['HTTP_HOST'] = 'example.com';
        try {
            ErrorHandler::errorNotify(E_WARNING, $message, 'file.php', 1, '<trace>');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(1, $GLOBALS['mail_sent_count']);
        $this->assertSame('LotGD Warning on example.com', $GLOBALS['last_subject']);
        $this->assertEmpty($warnings);
    }
}
