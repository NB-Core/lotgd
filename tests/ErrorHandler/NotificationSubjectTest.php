<?php

declare(strict_types=1);

namespace Lotgd\Tests\ErrorHandler;

use Lotgd\ErrorHandler;
use Lotgd\Tests\Stubs\DummySettings;
use Lotgd\Tests\Stubs\JsonMessage;
use Lotgd\Tests\Stubs\PHPMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * errorNotify() turns an error into one mail to the operator, and the subject
 * line is the whole of what an operator sees in a mailbox listing: severity,
 * and which host it came from. Getting either wrong makes a flood of
 * notifications unsortable.
 *
 * This replaces eight classes that each called errorNotify() once and checked
 * the subject. They shared a 25-line setUp and differed in one errno, one
 * message, or one absent setting -- ErrorHandlerNotifyNoAddressTest and
 * ErrorHandlerCliHostFallbackTest asserted byte-identical strings. The
 * differences are the provider table below, where they can be read side by
 * side.
 *
 * Every row also asserts that nothing was raised while building the mail. That
 * came from the two numeric/JSON cases, where encoding a non-string message
 * used to emit a warning of its own; applying it to every row costs nothing and
 * means a new message type cannot regress quietly.
 */
final class NotificationSubjectTest extends TestCase
{
    protected function setUp(): void
    {
        global $mail_sent_count, $last_subject, $output;

        $mail_sent_count = 0;
        $last_subject = '';

        // Loading the stub is what makes Mail::send() use it.
        new PHPMailer();

        $output = new class {
            public function appoencode($data, $priv)
            {
                return $data;
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['settings'], $_SERVER['HTTP_HOST']);
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('notificationProvider')]
    public function testSubjectLine(array $settings, ?string $host, int $errno, mixed $message, string $expectedSubject): void
    {
        $GLOBALS['settings'] = new DummySettings($settings + ['usedatacache' => 0]);

        if ($host === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $host;
        }

        $raised = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$raised): bool {
            $raised[] = [$errno, $errstr];

            return true;
        }, E_ALL);

        try {
            ErrorHandler::errorNotify($errno, $message, 'file.php', 42, '<trace>');
        } finally {
            restore_error_handler();
        }

        self::assertSame(1, $GLOBALS['mail_sent_count'], 'exactly one notification should be sent');
        self::assertSame($expectedSubject, $GLOBALS['last_subject']);
        self::assertSame([], $raised, 'building the notification must not raise anything itself');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: ?string, 2: int, 3: mixed, 4: string}>
     */
    public static function notificationProvider(): array
    {
        $addressed = [
            'notify_on_error' => 1,
            'notify_address' => 'admin@example.com',
            'gameadminemail' => 'admin@example.com',
        ];
        $unaddressed = ['notify_address' => 'admin@example.com', 'gameadminemail' => 'admin@example.com'];

        return [
            // The severity label comes from the errno.
            'error' => [$addressed, 'example.com', E_ERROR, 'Test error', 'LotGD Error on example.com'],
            'notice' => [$addressed, 'example.com', E_NOTICE, 'Test', 'LotGD Notice on example.com'],
            'warning' => [$addressed, 'example.com', E_WARNING, 'Test warning', 'LotGD Warning on example.com'],
            'errno with no label of its own' => [$addressed, 'example.com', 9999, 'Test error', 'LotGD Unknown on example.com'],

            // The message need not be a string. None of these may raise while
            // being encoded into the mail body.
            'array message' => [$unaddressed, 'example.com', E_WARNING, ['msg' => 'array'], 'LotGD Warning on example.com'],
            'numeric message' => [$unaddressed, 'example.com', E_WARNING, 123, 'LotGD Warning on example.com'],
            'JsonSerializable message' => [$unaddressed, 'example.com', E_WARNING, new JsonMessage(), 'LotGD Warning on example.com'],

            // Off the web there is no host to name, and the subject says so
            // rather than reading as if it came from a blank server.
            'no host' => [$addressed, null, E_ERROR, 'msg', 'LotGD Error on CLI execution – hostname unavailable'],

            // With no notify_address configured the mail still goes out, to
            // gameadminemail. The subject is unaffected.
            'no notify_address falls back to the admin address' => [
                ['notify_on_error' => 1, 'gameadminemail' => 'admin@example.com'],
                null,
                E_ERROR,
                'Test error',
                'LotGD Error on CLI execution – hostname unavailable',
            ],
        ];
    }
}
