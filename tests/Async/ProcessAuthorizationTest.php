<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async {

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
            $_POST = [];
            $_COOKIE = [];
            $_SESSION = [];
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $session = [];

            if (!defined('LOTGD_ASYNC_PROCESS_TEST_MODE')) {
                define('LOTGD_ASYNC_PROCESS_TEST_MODE', true);
            }

            require_once __DIR__ . '/../../async/process.php';
        }

        public function testUnauthenticatedProtectedCallableIsDeniedBeforeDispatch(): void
        {
            global $jaxon, $ajax_rate_limit_seconds;

            $ajax_rate_limit_seconds = 1.0;
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Mail';
            $_POST['jxnmthd'] = 'mailStatus';

            $jaxon = new class {
                public int $processCount = 0;

                public function canProcessRequest(): bool
                {
                    return true;
                }

                public function processRequest(): void
                {
                    $this->processCount++;
                }
            };

            ob_start();
            lotgd_async_process_entrypoint();
            $body = (string) ob_get_clean();

            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(401, http_response_code());
            $this->assertSame(0, $jaxon->processCount);
            $this->assertSame('error', $payload['status'] ?? null);
            $this->assertSame('authentication_required', $payload['error'] ?? null);
            $this->assertSame('Unauthorized', $payload['message'] ?? null);
        }

        public function testAuthenticatedCallablePassesToHandlerDispatch(): void
        {
            global $jaxon, $ajax_rate_limit_seconds, $session;

            $ajax_rate_limit_seconds = 1.0;
            $session['user']['loggedin'] = true;
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Mail';
            $_POST['jxnmthd'] = 'mailStatus';

            $jaxon = new class {
                public int $processCount = 0;

                public function canProcessRequest(): bool
                {
                    return true;
                }

                public function processRequest(): void
                {
                    $this->processCount++;
                }
            };

            ob_start();
            lotgd_async_process_entrypoint();
            ob_end_clean();

            $this->assertSame(1, $jaxon->processCount);
        }

        public function testAllowlistedPublicCallableRemainsReachable(): void
        {
            global $jaxon, $ajax_rate_limit_seconds;

            $ajax_rate_limit_seconds = 1.0;
            $_POST['jxncls'] = 'Lotgd.Async.Handler.TwoFactorAuthPasskey';
            $_POST['jxnmthd'] = 'beginAuthentication';

            $jaxon = new class {
                public int $processCount = 0;

                public function canProcessRequest(): bool
                {
                    return true;
                }

                public function processRequest(): void
                {
                    $this->processCount++;
                }
            };

            ob_start();
            lotgd_async_process_entrypoint();
            ob_end_clean();

            $this->assertSame(1, $jaxon->processCount);
        }

        public function testDeniedResponsesHaveConsistentJsonShape(): void
        {
            global $jaxon, $ajax_rate_limit_seconds;

            $ajax_rate_limit_seconds = 1.0;
            $_POST['jxncls'] = 'Lotgd.Async.Handler.TwoFactorAuthPasskey';
            $_POST['jxnmthd'] = 'deleteCredential';

            $jaxon = new class {
                public int $processCount = 0;

                public function canProcessRequest(): bool
                {
                    return true;
                }

                public function processRequest(): void
                {
                    $this->processCount++;
                }
            };

            ob_start();
            lotgd_async_process_entrypoint();
            $body = (string) ob_get_clean();

            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(403, http_response_code());
            $this->assertSame(0, $jaxon->processCount);
            $this->assertArrayHasKey('status', $payload);
            $this->assertArrayHasKey('error', $payload);
            $this->assertArrayHasKey('message', $payload);
            $this->assertSame('error', $payload['status']);
            $this->assertSame('callable_not_allowed', $payload['error']);
            $this->assertSame('Forbidden', $payload['message']);
        }
    }
}
