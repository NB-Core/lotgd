<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ErrorHandler;
use Lotgd\Output;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerMultiAddressTest extends TestCase
{
    protected function setUp(): void
    {
        global $settings, $mail_sent_count, $output, $last_subject, $session;

        $mail_sent_count = 0;
        $last_subject = '';
        // These progress messages name the game's notification addresses, so they
        // are shown to an operator holding SU_DEBUG_OUTPUT and to nobody else.
        $session = ['user' => ['superuser' => SU_DEBUG_OUTPUT]];
        $settings = new DummySettings([
            'notify_on_error' => 1,
            'notify_address' => 'one@example.com; two@example.com',
            'gameadminemail' => 'admin@example.com',
            'usedatacache' => 0,
        ]);

        // Ensure the PHPMailer stub is loaded so Mail::send uses it
        new PHPMailer();
        $output = new class {
            public function appoencode($data, $priv)
            {
                return $data;
            }
        };

        Output::getInstance()->resetOutput();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['session'], $GLOBALS['settings']);
    }

    public function testErrorNotificationIsSentToAllAddresses(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        ErrorHandler::errorNotify(E_ERROR, 'Test error', 'file.php', 42, '<trace>');

        $this->assertSame(2, $GLOBALS['mail_sent_count']);
        $outputText = Output::getInstance()->getRawOutput();
        $this->assertStringContainsString('Notifying one@example.com of this error.', $outputText);
        $this->assertStringContainsString('Notifying two@example.com of this error.', $outputText);
    }

    /**
     * The notification plumbing used to be forced onto the page, so any visitor
     * who tripped a warning was handed the game's notification addresses and the
     * cached list of recent failures.
     */
    public function testNotificationDetailsAreNotShownToAnonymousVisitors(): void
    {
        global $session;

        $session = [];
        Output::getInstance()->resetOutput();

        $_SERVER['HTTP_HOST'] = 'example.com';
        ErrorHandler::errorNotify(E_ERROR, 'Test error', 'file.php', 42, '<trace>');

        $outputText = Output::getInstance()->getRawOutput();
        $this->assertStringNotContainsString('one@example.com', $outputText);
        $this->assertStringNotContainsString('two@example.com', $outputText);
        $this->assertStringNotContainsString('Notifying', $outputText);
    }
}
