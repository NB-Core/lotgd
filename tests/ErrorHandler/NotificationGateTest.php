<?php

declare(strict_types=1);

namespace Lotgd\Tests\ErrorHandler;

use Lotgd\ErrorHandler;
use Lotgd\Output;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\PHPMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * handleError() decides whether an error becomes a notification at all;
 * errorNotify(), covered by NotificationSubjectTest, only decides what that
 * notification says.
 *
 * The gate is per severity, and getting it wrong is expensive in both
 * directions: a game that warns on every page view will mail its operator
 * thousands of times a night, and an operator who turned notifications on and
 * gets none learns nothing about a failing site. So each case is stated as a
 * pair -- setting on, mail sent; setting absent, no mail -- because a test that
 * only checks the positive side passes just as well when the gate is welded
 * open.
 */
final class NotificationGateTest extends TestCase
{
    private int $originalErrorReporting;

    protected function setUp(): void
    {
        global $mail_sent_count, $last_subject, $output, $session;

        $this->originalErrorReporting = error_reporting(E_ALL);
        $mail_sent_count = 0;
        $last_subject = '';
        $session = ['user' => ['superuser' => SU_DEBUG_OUTPUT]];

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
        Output::getInstance()->resetOutput();
        error_reporting($this->originalErrorReporting);
        unset($GLOBALS['settings'], $_SERVER['HTTP_HOST']);
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('gateProvider')]
    public function testNotificationGate(array $settings, int $errno, int $expectedMails, string $expectedSubject): void
    {
        $GLOBALS['settings'] = new DummySettings($settings + [
            'notify_address' => 'admin@example.com',
            'gameadminemail' => 'admin@example.com',
            'usedatacache' => 0,
        ]);
        $_SERVER['HTTP_HOST'] = 'example.com';

        ob_start();
        try {
            ErrorHandler::handleError($errno, 'Test message', 'file.php', 42);
        } finally {
            ob_end_clean();
        }

        self::assertSame($expectedMails, $GLOBALS['mail_sent_count']);
        self::assertSame($expectedSubject, $GLOBALS['last_subject']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int, 2: int, 3: string}>
     */
    public static function gateProvider(): array
    {
        return [
            'warning notifies when notify_on_warn is set' => [
                ['notify_on_warn' => 1],
                E_WARNING,
                1,
                'LotGD Warning on example.com',
            ],
            'warning stays quiet without notify_on_warn' => [
                [],
                E_WARNING,
                0,
                '',
            ],
        ];
    }
}
