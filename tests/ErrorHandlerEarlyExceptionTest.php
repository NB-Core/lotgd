<?php

declare(strict_types=1);

namespace Lotgd\Tests {
    use Lotgd\ErrorHandler;
    use Lotgd\Settings;
    use Lotgd\Tests\Stubs\PHPMailer;
    use PHPUnit\Framework\TestCase;

    final class ErrorHandlerEarlyExceptionTest extends TestCase
    {
        protected function setUp(): void
        {
            global $settings, $mail_sent_count, $output;

            $mail_sent_count = 0;

            // Provide settings object that is not an instance of Lotgd\Settings
            $settings = new class {
                public function getSetting(string $name, $default = null)
                {
                    $map = [
                        'notify_on_error' => 1,
                        'notify_address' => 'admin@example.com',
                        'gameadminemail' => 'admin@example.com',
                        'notify_every' => 30,
                        'usedatacache' => 0,
                    ];

                    return $map[$name] ?? $default;
                }
            };

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

        /**
         * An exception thrown before the Settings singleton exists must be
         * handled, not amplified.
         *
         * ErrorHandler::errorNotify() bails out when Settings::hasInstance() is
         * false (see the guard at the top of that method): there is nowhere to
         * read notify_address from yet, and bootstrapping Settings from inside
         * an error handler is how a single failure turns into a loop. So the
         * contract here is that handling stays quiet and does not throw --
         * notification is covered by ErrorHandlerExceptionNotifyTest, which
         * supplies a real Settings instance.
         */
        public function testExceptionBeforeSettingsIsHandledWithoutNotifying(): void
        {
            $this->assertFalse(Settings::hasInstance());

            try {
                strlen([]);
            } catch (\TypeError $e) {
                ob_start();
                @ErrorHandler::handleException($e);
                ob_end_clean();
            }

            $this->assertSame(0, $GLOBALS['mail_sent_count']);
        }
    }
}
