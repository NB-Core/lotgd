<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async {

    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    final class ProcessAuthorizationTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session;

            $_GET = [];
            $_SERVER['HTTP_X_LOTGD_ASYNC'] = '1';
            $_POST = [];
            $_COOKIE = [];
            $_SESSION = [];
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $session = [];

            if (!defined('LOTGD_ASYNC_PROCESS_TEST_MODE')) {
                define('LOTGD_ASYNC_PROCESS_TEST_MODE', true);
            }

            require_once __DIR__ . '/../../async/process.php';

            // These tests are about authorization, privileges and the session
            // lock, not about CSRF. Present a valid token the way a real
            // client does, so the endpoint's CSRF gate is not what they
            // end up measuring.
            $_SERVER['HTTP_X_LOTGD_CSRF'] = \Lotgd\Security\Csrf::token(\Lotgd\Security\Csrf::SCOPE_ASYNC);

            $storePath = lotgd_async_denied_throttle_store_path();
            if (file_exists($storePath)) {
                unlink($storePath);
            }

            if (function_exists('apcu_clear_cache')) {
                apcu_clear_cache();
            }
        }

        public function testUnauthenticatedProtectedCallableIsDeniedBeforeDispatch(): void
        {
            global $asyncDispatcher, $ajax_rate_limit_seconds;

            $ajax_rate_limit_seconds = 1.0;
            $_POST['call'] = json_encode(['class' => 'Mail', 'method' => 'mailStatus', 'args' => []], JSON_THROW_ON_ERROR);

            $asyncDispatcher = new class {
                public int $processCount = 0;

                public function canProcessRequest(): bool
                {
                    return true;
                }

                public function processRequest(): \Lotgd\Async\Response
                {
                    $this->processCount++;
                    return new \Lotgd\Async\Response();
                }
            };

            ob_start();
            lotgd_async_process_entrypoint();
            $body = (string) ob_get_clean();

            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(401, http_response_code());
            $this->assertSame(0, $asyncDispatcher->processCount);
            $this->assertSame('error', $payload['status'] ?? null);
            $this->assertSame('authentication_required', $payload['error'] ?? null);
            $this->assertSame('Unauthorized', $payload['message'] ?? null);
        }

        public function testAuthenticatedCallablePassesToHandlerDispatch(): void
        {
            global $asyncDispatcher, $ajax_rate_limit_seconds, $session;

            $ajax_rate_limit_seconds = 1.0;
            $session['user']['loggedin'] = true;
            $_POST['call'] = json_encode(['class' => 'Mail', 'method' => 'mailStatus', 'args' => []], JSON_THROW_ON_ERROR);

            $asyncDispatcher = new class {
                public int $processCount = 0;

                public function canProcessRequest(): bool
                {
                    return true;
                }

                public function processRequest(): \Lotgd\Async\Response
                {
                    $this->processCount++;
                    return new \Lotgd\Async\Response();
                }
            };

            ob_start();
            lotgd_async_process_entrypoint();
            ob_end_clean();

            $this->assertSame(1, $asyncDispatcher->processCount);
        }

        public function testAllowlistedPublicCallableRemainsReachable(): void
        {
            global $asyncDispatcher, $ajax_rate_limit_seconds;

            $ajax_rate_limit_seconds = 1.0;
            $_POST['call'] = json_encode(['class' => 'TwoFactorAuthPasskey', 'method' => 'beginAuthentication', 'args' => []], JSON_THROW_ON_ERROR);

            $asyncDispatcher = new class {
                public int $processCount = 0;

                public function canProcessRequest(): bool
                {
                    return true;
                }

                public function processRequest(): \Lotgd\Async\Response
                {
                    $this->processCount++;
                    return new \Lotgd\Async\Response();
                }
            };

            ob_start();
            lotgd_async_process_entrypoint();
            ob_end_clean();

            $this->assertSame(1, $asyncDispatcher->processCount);
        }


        #[DataProvider('deniedPasskeyHelperMethodProvider')]
        public function testPasskeyHelperCallableIsDeniedBeforeDispatch(string $methodName): void
        {
            global $asyncDispatcher, $ajax_rate_limit_seconds;

            $ajax_rate_limit_seconds = 1.0;
            $_POST['call'] = json_encode(['class' => 'TwoFactorAuthPasskey', 'method' => $methodName, 'args' => []], JSON_THROW_ON_ERROR);

            $asyncDispatcher = new class {
                public int $processCount = 0;

                public function canProcessRequest(): bool
                {
                    return true;
                }

                public function processRequest(): \Lotgd\Async\Response
                {
                    $this->processCount++;
                    return new \Lotgd\Async\Response();
                }
            };

            ob_start();
            lotgd_async_process_entrypoint();
            $body = (string) ob_get_clean();

            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(403, http_response_code());
            $this->assertSame(0, $asyncDispatcher->processCount);
            $this->assertSame('callable_not_allowed', $payload['error'] ?? null);
            $this->assertSame('Forbidden', $payload['message'] ?? null);
        }

        public function testDeniedResponsesHaveConsistentJsonShape(): void
        {
            global $asyncDispatcher, $ajax_rate_limit_seconds;

            $ajax_rate_limit_seconds = 1.0;
            $_POST['call'] = json_encode(['class' => 'TwoFactorAuthPasskey', 'method' => 'deleteCredential', 'args' => []], JSON_THROW_ON_ERROR);

            $asyncDispatcher = new class {
                public int $processCount = 0;

                public function canProcessRequest(): bool
                {
                    return true;
                }

                public function processRequest(): \Lotgd\Async\Response
                {
                    $this->processCount++;
                    return new \Lotgd\Async\Response();
                }
            };

            ob_start();
            lotgd_async_process_entrypoint();
            $body = (string) ob_get_clean();

            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(403, http_response_code());
            $this->assertSame(0, $asyncDispatcher->processCount);
            $this->assertArrayHasKey('status', $payload);
            $this->assertArrayHasKey('error', $payload);
            $this->assertArrayHasKey('message', $payload);
            $this->assertSame('error', $payload['status']);
            $this->assertSame('callable_not_allowed', $payload['error']);
            $this->assertSame('Forbidden', $payload['message']);
        }

        public function testDeniedThrottleRemainsEffectiveWithoutSessionCookie(): void
        {
            $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
            $_SERVER['HTTP_USER_AGENT'] = 'lotgd-test-agent/1.0';
            $_COOKIE = [];

            $start = microtime(true);
            $this->assertFalse(lotgd_async_denied_request_is_throttled($start, 2.0));

            // Simulate session churn for unauthenticated clients that do not return a PHPSESSID cookie.
            $_SESSION = [];

            $this->assertTrue(lotgd_async_denied_request_is_throttled($start + 0.2, 2.0));
        }


        /**
         * @return array<string, array{0:string}>
         */
        public static function deniedPasskeyHelperMethodProvider(): array
        {
            return [
                'setService helper' => ['setService'],
                'setRepository helper' => ['setRepository'],
            ];
        }

        /**
         * Regression guard: the policy layer used to read only the legacy class/method
         * fields, which the client never sends. Every production request
         * therefore arrived with an empty context and slipped past the passkey
         * restrictions below.
         */
        public function testPasskeyHelperCallableIsDeniedWithARealisticCallPayload(): void
        {
            global $asyncDispatcher, $ajax_rate_limit_seconds;

            $ajax_rate_limit_seconds = 1.0;
            $_SESSION['session']['user']['loggedin'] = true;
            $_POST['call'] = json_encode(['class' => 'TwoFactorAuthPasskey', 'method' => 'deleteCredential', 'args' => []], JSON_THROW_ON_ERROR);

            $asyncDispatcher = new class {
                public int $processCount = 0;

                public function canProcessRequest(): bool
                {
                    return true;
                }

                public function processRequest(): \Lotgd\Async\Response
                {
                    $this->processCount++;
                    return new \Lotgd\Async\Response();
                }
            };

            ob_start();
            lotgd_async_process_entrypoint();
            $body = (string) ob_get_clean();

            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(403, http_response_code());
            $this->assertSame(0, $asyncDispatcher->processCount);
            $this->assertSame('callable_not_allowed', $payload['error'] ?? null);
        }

        public function testUnauthenticatedPasskeyAuthIsAllowlistedWithARealisticCallPayload(): void
        {
            global $asyncDispatcher, $ajax_rate_limit_seconds;

            $ajax_rate_limit_seconds = 1.0;
            $_POST['call'] = json_encode(['class' => 'TwoFactorAuthPasskey', 'method' => 'beginAuthentication', 'args' => []], JSON_THROW_ON_ERROR);

            $asyncDispatcher = new class {
                public int $processCount = 0;

                public function canProcessRequest(): bool
                {
                    return true;
                }

                public function processRequest(): \Lotgd\Async\Response
                {
                    $this->processCount++;
                    return new \Lotgd\Async\Response();
                }
            };

            ob_start();
            lotgd_async_process_entrypoint();
            ob_end_clean();

            $this->assertSame(1, $asyncDispatcher->processCount);
        }

        public function testAbuseKeyIgnoresSessionIdWhenNoCookieIsPresent(): void
        {
            $_SERVER['REMOTE_ADDR'] = '198.51.100.24';
            $_SERVER['HTTP_USER_AGENT'] = 'lotgd-key-test/1.0';
            $_COOKIE = [];

            session_id('first-session-id');
            $firstKey = lotgd_async_abuse_key();

            session_id('second-session-id');
            $secondKey = lotgd_async_abuse_key();

            $this->assertSame($firstKey, $secondKey);
        }
    }
}
