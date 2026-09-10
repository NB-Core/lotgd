<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ErrorHandler;
use Lotgd\Output;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerSuppressionTest extends TestCase
{
    private int $originalErrorReporting;

    protected function setUp(): void
    {
        global $mail_sent_count, $last_subject, $output, $session, $settings;

        $this->originalErrorReporting = error_reporting(E_ALL);
        $mail_sent_count = 0;
        $last_subject = '';
        $settings = new DummySettings([
            'notify_on_warn' => 1,
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

        Output::getInstance()->resetOutput();
        $_SERVER['HTTP_HOST'] = 'example.com';
        set_error_handler([ErrorHandler::class, 'handleError']);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        error_reporting($this->originalErrorReporting);
        Output::getInstance()->resetOutput();
        unset($GLOBALS['mail_sent_count'], $GLOBALS['last_subject'], $GLOBALS['output']);
        unset($GLOBALS['session'], $GLOBALS['settings']);
    }

    public function testSuppressedWarningIsNeitherDisplayedNorEmailed(): void
    {
        @trigger_error('Suppressed warning', E_USER_WARNING);

        $this->assertSame('', Output::getInstance()->getRawOutput());
        $this->assertSame(0, $GLOBALS['mail_sent_count']);
    }

    public function testUnsuppressedWarningUsesNormalDisplayAndNotificationHandling(): void
    {
        trigger_error('Unsuppressed warning', E_USER_WARNING);

        $this->assertStringContainsString('Unsuppressed warning', Output::getInstance()->getRawOutput());
        $this->assertSame(1, $GLOBALS['mail_sent_count']);
        $this->assertSame('LotGD Warning on example.com', $GLOBALS['last_subject']);
    }
}
