<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async {

    use Lotgd\Async\CsrfMode;
    use Lotgd\Security\Csrf;
    use PHPUnit\Framework\TestCase;

    /**
     * The async endpoint's CSRF gate.
     *
     * The endpoint carries no navigation allowlist — async/process.php defines
     * OVERRIDE_FORCED_NAV, which makes both branches of doForcedNav() no-ops —
     * so a request is authenticated by the session cookie alone. What keeps a
     * cross-site call out today is SameSite=Lax, which is a browser default and
     * not something this code decides.
     *
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    final class ProcessCsrfTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session;

            $_GET = [];
            $_POST = [];
            $_COOKIE = [];
            $_SESSION = [];
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            unset($_SERVER['HTTP_X_LOTGD_CSRF']);
            $session = [];

            if (!defined('LOTGD_ASYNC_PROCESS_TEST_MODE')) {
                define('LOTGD_ASYNC_PROCESS_TEST_MODE', true);
            }

            require_once __DIR__ . '/../../async/process.php';

            $storePath = lotgd_async_denied_throttle_store_path();
            if (file_exists($storePath)) {
                unlink($storePath);
            }

            if (function_exists('apcu_clear_cache')) {
                apcu_clear_cache();
            }

            CsrfMode::setMode(CsrfMode::ENFORCE);
        }

        private function installJaxonDouble(): object
        {
            global $jaxon, $ajax_rate_limit_seconds;

            $ajax_rate_limit_seconds = 1.0;
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

            return $jaxon;
        }

        /**
         * @return array{payload: array<string, mixed>, status: int|false}
         */
        private function dispatch(string $class, string $method): array
        {
            $_POST['jxncls'] = $class;
            $_POST['jxnmthd'] = $method;

            ob_start();
            lotgd_async_process_entrypoint();
            $body = (string) ob_get_clean();

            return [
                'payload' => $body === '' ? [] : (array) json_decode($body, true, 512, JSON_THROW_ON_ERROR),
                'status' => http_response_code(),
            ];
        }

        private function login(): void
        {
            global $session;
            $session['user']['loggedin'] = true;
        }

        /**
         * Run the policy and capture what it wrote to error_log.
         *
         * Asking the state function afterwards would prove nothing: it reports
         * the same thing whether or not the policy ever reached it, which is
         * precisely how the first version of the passkey test passed against
         * broken wiring. Only the log says what the policy actually did.
         *
         * @param array{class:string,method:string} $context
         *
         * @return array{policy: array<string, mixed>, log: string}
         */
        private function capturePolicyLog(array $context): array
        {
            $log = tempnam(sys_get_temp_dir(), 'lotgd-csrf-log-');
            self::assertIsString($log);
            $previous = (string) ini_get('error_log');
            ini_set('error_log', $log);

            try {
                $policy = lotgd_async_authorization_policy($context);
            } finally {
                ini_set('error_log', $previous);
            }

            $written = (string) file_get_contents($log);
            unlink($log);

            return ['policy' => $policy, 'log' => $written];
        }

        public function testCorrectHeaderTokenIsDispatched(): void
        {
            $jaxon = $this->installJaxonDouble();
            $this->login();
            $_SERVER['HTTP_X_LOTGD_CSRF'] = Csrf::token(Csrf::SCOPE_ASYNC);

            $this->dispatch('Lotgd.Async.Handler.Mail', 'mailStatus');

            self::assertSame(1, $jaxon->processCount);
        }

        /**
         * The form field is kept as a second transport so a failure of the
         * header path does not need a release to work around.
         */
        public function testCorrectPostedTokenIsDispatched(): void
        {
            $jaxon = $this->installJaxonDouble();
            $this->login();
            $_POST[Csrf::FIELD] = Csrf::token(Csrf::SCOPE_ASYNC);

            $this->dispatch('Lotgd.Async.Handler.Mail', 'mailStatus');

            self::assertSame(1, $jaxon->processCount);
        }

        public function testMissingTokenIsRefusedWhenEnforced(): void
        {
            $jaxon = $this->installJaxonDouble();
            $this->login();
            Csrf::token(Csrf::SCOPE_ASYNC);

            $result = $this->dispatch('Lotgd.Async.Handler.Mail', 'mailStatus');

            self::assertSame(0, $jaxon->processCount);
            self::assertSame(403, $result['status']);
            self::assertSame('csrf_invalid', $result['payload']['error'] ?? null);
        }

        public function testWrongTokenIsRefusedWhenEnforced(): void
        {
            $jaxon = $this->installJaxonDouble();
            $this->login();
            Csrf::token(Csrf::SCOPE_ASYNC);
            $_SERVER['HTTP_X_LOTGD_CSRF'] = str_repeat('a', 64);

            $result = $this->dispatch('Lotgd.Async.Handler.Mail', 'mailStatus');

            self::assertSame(0, $jaxon->processCount);
            self::assertSame(403, $result['status']);
        }

        /**
         * The one that proves log mode cannot break a player. This is the whole
         * reason the setting exists.
         */
        public function testMissingTokenIsDispatchedInLogMode(): void
        {
            CsrfMode::setMode(CsrfMode::LOG);
            $jaxon = $this->installJaxonDouble();
            $this->login();
            Csrf::token(Csrf::SCOPE_ASYNC);

            $this->dispatch('Lotgd.Async.Handler.Mail', 'mailStatus');

            self::assertSame(1, $jaxon->processCount);
        }

        public function testTokenIsIgnoredWhenModeIsOff(): void
        {
            CsrfMode::setMode(CsrfMode::OFF);
            $jaxon = $this->installJaxonDouble();
            $this->login();

            $this->dispatch('Lotgd.Async.Handler.Mail', 'mailStatus');

            self::assertSame(1, $jaxon->processCount);
        }

        /**
         * The pre-login passkey pair is checked but never refused, even under
         * enforce. Refusing there would mean nobody completes two-factor login
         * if any entry point turns out not to load async/setup.php; the gain
         * would be defence in depth on a path that already validates its own
         * token inside the handler. The failure is recorded so a release of
         * evidence can promote it.
         */
        public function testUnauthenticatedPasskeyPathIsNeverRefused(): void
        {
            $jaxon = $this->installJaxonDouble();

            $this->dispatch('Lotgd.Async.Handler.TwoFactorAuthPasskey', 'beginAuthentication');

            self::assertSame(1, $jaxon->processCount, 'the login path must not be blocked');
        }

        /**
         * The wiring, not the function.
         *
         * The first version of this test called lotgd_async_csrf_state()
         * directly and passed, while the policy only reached it inside its
         * authenticated branch -- which a pre-login passkey call never enters.
         * The evidence this pair was added to collect would never have been
         * written. Assert through the entry point instead.
         */
        public function testPasskeyPathIsObservedThroughThePolicy(): void
        {
            $result = $this->capturePolicyLog([
                'class' => 'Lotgd.Async.Handler.TwoFactorAuthPasskey',
                'method' => 'beginAuthentication',
            ]);

            self::assertTrue($result['policy']['allowed'], 'the login path must not be blocked');
            self::assertStringContainsString('Jaxon csrf missing', $result['log']);
            self::assertStringContainsString('TwoFactorAuthPasskey::beginAuthentication', $result['log']);
        }

        /**
         * The signal the promotion decision rests on.
         *
         * UPGRADING.md tells an operator to set `enforce` once error_log carries
         * no "Jaxon csrf" lines. That instruction is only worth following if a
         * real player's failing poll still produces one.
         */
        public function testAnAuthenticatedFailureIsStillRecorded(): void
        {
            $this->login();
            Csrf::seed(Csrf::SCOPE_ASYNC, str_repeat('b', 64));
            $_SERVER['HTTP_X_LOTGD_CSRF'] = str_repeat('a', 64);

            $result = $this->capturePolicyLog([
                'class' => 'Lotgd.Async.Handler.Mail',
                'method' => 'mailStatus',
            ]);

            self::assertStringContainsString('Jaxon csrf mismatch', $result['log']);
            self::assertStringContainsString('Mail::mailStatus', $result['log']);
        }

        public function testAnAuthenticatedSuccessIsSilent(): void
        {
            $this->login();
            $_SERVER['HTTP_X_LOTGD_CSRF'] = Csrf::token(Csrf::SCOPE_ASYNC);

            $result = $this->capturePolicyLog([
                'class' => 'Lotgd.Async.Handler.Mail',
                'method' => 'mailStatus',
            ]);

            self::assertTrue($result['policy']['allowed']);
            self::assertSame('', $result['log'], 'a working poll must leave the signal alone');
        }

        /**
         * The counterpart: what must NOT reach that log.
         *
         * An unauthenticated non-passkey request is refused on authentication
         * whatever its token says, so its token is not evidence about anything.
         * Two very ordinary things produce a stream of them -- a tab whose
         * session timed out, or was left open after a logout, keeps polling with
         * the token inlined into the page it was rendered from; and a bare POST
         * to the endpoint carries no token at all. Recording either would mean
         * the log an operator is told to watch never falls quiet, and says
         * nothing about players when it does.
         *
         * @return iterable<string, array{0: string|null}>
         */
        public static function unauthenticatedTokenProvider(): iterable
        {
            yield 'a timed-out tab still polling with its stale token' => [str_repeat('c', 64)];
            yield 'a bare POST to the endpoint' => [null];
        }

        #[\PHPUnit\Framework\Attributes\DataProvider('unauthenticatedTokenProvider')]
        public function testAnUnauthenticatedNonPasskeyRequestIsNotRecorded(?string $token): void
        {
            if ($token !== null) {
                $_SERVER['HTTP_X_LOTGD_CSRF'] = $token;
            }

            $result = $this->capturePolicyLog([
                'class' => 'Lotgd.Async.Handler.Mail',
                'method' => 'mailStatus',
            ]);

            self::assertFalse($result['policy']['allowed']);
            self::assertSame(401, $result['policy']['status'], 'refused on authentication, not on the token');
            self::assertSame('', $result['log'], 'must not pollute the promotion signal');
        }

        /**
         * Not refusing is not the same as not looking: the state function
         * reports what it saw, which is what reaches error_log.
         */
        public function testPasskeyPathStillReportsAMissingToken(): void
        {
            $state = lotgd_async_csrf_state([
                'class' => 'Lotgd.Async.Handler.TwoFactorAuthPasskey',
                'method' => 'beginAuthentication',
            ]);

            self::assertTrue($state['valid'], 'must not refuse');
            self::assertTrue($state['observed'], 'must still be recorded');
            self::assertSame('missing', $state['reason']);
        }

        public function testPasskeyPathReportsNothingWhenTheTokenIsRight(): void
        {
            $_SERVER['HTTP_X_LOTGD_CSRF'] = Csrf::token(Csrf::SCOPE_ASYNC);

            $state = lotgd_async_csrf_state([
                'class' => 'Lotgd.Async.Handler.TwoFactorAuthPasskey',
                'method' => 'beginAuthentication',
            ]);

            self::assertTrue($state['valid']);
            self::assertFalse($state['observed']);
        }

        /**
         * The shipped default stays 'log' through this release.
         *
         * The transport is verified, but the client is inlined into each page,
         * so a tab rendered before an upgrade keeps a client with neither the
         * same-origin fix nor the recovery handler. Enforcing at upgrade time
         * would strand it. Promotion is the operator's call.
         */
        public function testLogRemainsTheShippedDefault(): void
        {
            self::assertSame(
                'log',
                (string) (require dirname(__DIR__, 2) . '/config/async.settings.php.dist')['csrf_mode']
            );
        }

        /**
         * The gate must not become an authentication bypass: a correct token on
         * an unauthenticated request still has to fail on auth. This is the
         * test that catches a mis-ordered policy.
         */
        public function testCorrectTokenDoesNotSubstituteForALogin(): void
        {
            $jaxon = $this->installJaxonDouble();
            Csrf::seed(Csrf::SCOPE_ASYNC, str_repeat('b', 64));
            $_SERVER['HTTP_X_LOTGD_CSRF'] = str_repeat('b', 64);

            $result = $this->dispatch('Lotgd.Async.Handler.Mail', 'mailStatus');

            self::assertSame(0, $jaxon->processCount);
            self::assertSame(401, $result['status']);
            self::assertSame('authentication_required', $result['payload']['error'] ?? null);
        }

        /**
         * The endpoint must never mint the token it is checking: the session
         * lock may already be released, so it would be handed out once and
         * lost.
         */
        public function testADeniedRequestDoesNotCreateAToken(): void
        {
            $this->installJaxonDouble();
            $this->login();

            $this->dispatch('Lotgd.Async.Handler.Mail', 'mailStatus');

            self::assertNull(Csrf::peek(Csrf::SCOPE_ASYNC));
        }

        /**
         * An unknown csrf_mode must not silently disable the check.
         */
        public function testUnknownModeFallsBackToLogging(): void
        {
            CsrfMode::setMode('yes please');

            self::assertSame(CsrfMode::LOG, CsrfMode::mode());
            self::assertTrue(CsrfMode::isChecked());
            self::assertFalse(CsrfMode::isEnforced());
        }
    }
}
