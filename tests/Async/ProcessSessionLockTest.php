<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async {

    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /**
     * Guards the read-only allowlist that decides whether the async entry point may
     * release the PHP session lock before dispatching a Jaxon callable.
     *
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    final class ProcessSessionLockTest extends TestCase
    {
        protected function setUp(): void
        {
            $_GET = [];
            $_POST = [];
            unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE']);

            if (!defined('LOTGD_ASYNC_PROCESS_TEST_MODE')) {
                define('LOTGD_ASYNC_PROCESS_TEST_MODE', true);
            }

            require_once __DIR__ . '/../../async/process.php';

            // These tests are about authorization, privileges and the session
            // lock, not about CSRF. Present a valid token the way a real
            // client does, so the endpoint's CSRF gate is not what they
            // end up measuring.
            $_SERVER['HTTP_X_LOTGD_CSRF'] = \Lotgd\Security\Csrf::token(\Lotgd\Security\Csrf::SCOPE_ASYNC);
        }

        /**
         * @return array<string, array{0:string, 1:string}>
         */
        public static function readOnlyCallableProvider(): array
        {
            return [
                'commentary poll' => ['Lotgd.Async.Handler.Commentary', 'pollUpdates'],
                'commentary refresh' => ['Lotgd.Async.Handler.Commentary', 'commentaryRefresh'],
                'mail status' => ['Lotgd.Async.Handler.Mail', 'mailStatus'],
                'timeout status' => ['Lotgd.Async.Handler.Timeout', 'timeoutStatus'],
                'ban lookup' => ['Lotgd.Async.Handler.Bans', 'affectedUsers'],
            ];
        }

        #[DataProvider('readOnlyCallableProvider')]
        public function testPollingCallablesMayReleaseTheSessionLock(string $class, string $method): void
        {
            $this->assertTrue(lotgd_async_is_session_readonly_callable([
                'class' => $class,
                'method' => $method,
            ]));
        }

        /**
         * The passkey ceremonies persist their challenge in the session between the
         * begin and finish calls, so their lock must never be released early.
         *
         * @return array<string, array{0:string, 1:string}>
         */
        public static function sessionWritingCallableProvider(): array
        {
            return [
                // viewCommentary() persists the section/scriptname/last-id triple that the
                // next page load and the next poll both read back.
                'commentary text' => ['Lotgd.Async.Handler.Commentary', 'commentaryText'],
                'passkey begin registration' => ['Lotgd.Async.Handler.TwoFactorAuthPasskey', 'beginRegistration'],
                'passkey finish registration' => ['Lotgd.Async.Handler.TwoFactorAuthPasskey', 'finishRegistration'],
                'passkey begin authentication' => ['Lotgd.Async.Handler.TwoFactorAuthPasskey', 'beginAuthentication'],
                'passkey verify authentication' => ['Lotgd.Async.Handler.TwoFactorAuthPasskey', 'verifyAuthentication'],
            ];
        }

        #[DataProvider('sessionWritingCallableProvider')]
        public function testSessionWritingCallablesKeepTheLock(string $class, string $method): void
        {
            $this->assertFalse(lotgd_async_is_session_readonly_callable([
                'class' => $class,
                'method' => $method,
            ]));
        }

        /**
         * Handlers dropped into the Jaxon callable directory by modules are unknown to
         * core and must keep the previous behaviour.
         *
         * @return array<string, array{0:string, 1:string}>
         */
        public static function unknownCallableProvider(): array
        {
            return [
                'module handler' => ['Lotgd.Async.Handler.SomeModule', 'doWork'],
                'known class unknown method' => ['Lotgd.Async.Handler.Mail', 'sendMail'],
                'empty class' => ['', 'pollUpdates'],
                'empty method' => ['Lotgd.Async.Handler.Commentary', ''],
            ];
        }

        #[DataProvider('unknownCallableProvider')]
        public function testUnknownCallablesDefaultToKeepingTheLock(string $class, string $method): void
        {
            $this->assertFalse(lotgd_async_is_session_readonly_callable([
                'class' => $class,
                'method' => $method,
            ]));
        }

        public function testReleaseIsSkippedWhenNoSessionIsActive(): void
        {
            $this->assertSame(PHP_SESSION_NONE, session_status());
            $this->assertFalse(lotgd_async_release_session_lock([
                'class' => 'Lotgd.Async.Handler.Commentary',
                'method' => 'pollUpdates',
            ]));
        }

        public function testReleaseClosesAnActiveSessionAndKeepsDataReadable(): void
        {
            session_start();
            $_SESSION['session']['user']['acctid'] = 42;

            $released = lotgd_async_release_session_lock([
                'class' => 'Lotgd.Async.Handler.Commentary',
                'method' => 'pollUpdates',
            ]);

            $this->assertTrue($released);
            $this->assertSame(PHP_SESSION_NONE, session_status());
            // Handlers keep reading $session/$_SESSION after the lock is gone.
            $this->assertSame(42, $_SESSION['session']['user']['acctid']);
        }

        /**
         * Wiring check: the entry point must drop the lock before Jaxon dispatch,
         * not merely be able to.
         */
        public function testEntrypointReleasesTheLockBeforeDispatchingAPollingCallable(): void
        {
            global $jaxon, $ajax_rate_limit_seconds;

            $ajax_rate_limit_seconds = 1.0;
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Commentary';
            $_POST['jxnmthd'] = 'pollUpdates';

            session_start();
            $_SESSION['session']['user']['loggedin'] = true;

            $jaxon = new class {
                public ?int $statusDuringDispatch = null;

                public function canProcessRequest(): bool
                {
                    return true;
                }

                public function processRequest(): void
                {
                    $this->statusDuringDispatch = session_status();
                }
            };

            ob_start();
            lotgd_async_process_entrypoint();
            ob_end_clean();

            $this->assertSame(PHP_SESSION_NONE, $jaxon->statusDuringDispatch);
            // The rate-limit timestamp is still written before the lock is released.
            $this->assertArrayHasKey('lastrequest', $_SESSION);
        }

        /**
         * End-to-end shape check with the payload a real Jaxon 5 client sends. Without
         * `jxncall` parsing the context stayed empty and the lock was never released in
         * production, so this is the test that proves the optimisation actually applies.
         */
        public function testEntrypointReleasesTheLockForARealisticJxncallPayload(): void
        {
            global $jaxon, $ajax_rate_limit_seconds;

            $ajax_rate_limit_seconds = 1.0;
            $_POST['jxncall'] = json_encode([
                'type' => 'class',
                'name' => 'Lotgd.Async.Handler.Commentary',
                'method' => 'pollUpdates',
                'args' => ['village', 0],
            ], JSON_THROW_ON_ERROR);

            session_start();
            $_SESSION['session']['user']['loggedin'] = true;

            $jaxon = new class {
                public ?int $statusDuringDispatch = null;

                public function canProcessRequest(): bool
                {
                    return true;
                }

                public function processRequest(): void
                {
                    $this->statusDuringDispatch = session_status();
                }
            };

            ob_start();
            lotgd_async_process_entrypoint();
            ob_end_clean();

            $this->assertSame(PHP_SESSION_NONE, $jaxon->statusDuringDispatch);
        }

        public function testEntrypointKeepsTheLockForSessionWritingCallables(): void
        {
            global $jaxon, $ajax_rate_limit_seconds;

            $ajax_rate_limit_seconds = 1.0;
            $_POST['jxncls'] = 'Lotgd.Async.Handler.TwoFactorAuthPasskey';
            $_POST['jxnmthd'] = 'beginRegistration';

            session_start();
            $_SESSION['session']['user']['loggedin'] = true;

            $jaxon = new class {
                public ?int $statusDuringDispatch = null;

                public function canProcessRequest(): bool
                {
                    return true;
                }

                public function processRequest(): void
                {
                    $this->statusDuringDispatch = session_status();
                }
            };

            ob_start();
            lotgd_async_process_entrypoint();
            ob_end_clean();

            $this->assertSame(PHP_SESSION_ACTIVE, $jaxon->statusDuringDispatch);

            session_write_close();
        }

        public function testReleaseLeavesTheSessionOpenForSessionWritingCallables(): void
        {
            session_start();

            $released = lotgd_async_release_session_lock([
                'class' => 'Lotgd.Async.Handler.TwoFactorAuthPasskey',
                'method' => 'beginRegistration',
            ]);

            $this->assertFalse($released);
            $this->assertSame(PHP_SESSION_ACTIVE, session_status());

            session_write_close();
        }
    }
}
