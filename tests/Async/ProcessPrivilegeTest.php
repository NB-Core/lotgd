<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async {

    use PHPUnit\Framework\TestCase;

    /**
     * Per-callable authorization at the async entry point.
     *
     * Authentication is not authorization. bans.php gates itself with
     * SuAccess::check(SU_EDIT_BANS), but async/process.php dispatches to the
     * handler directly, so that gate never runs on this path.
     *
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    final class ProcessPrivilegeTest extends TestCase
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

        /**
         * Counting dispatch double, matching ProcessAuthorizationTest.
         */
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

        /**
         * The case this gate exists for: an ordinary player asking the ban
         * lookup which accounts a ban rule covers.
         */
        public function testLoggedInPlayerWithoutBanRightsIsRefused(): void
        {
            global $session;

            $jaxon = $this->installJaxonDouble();
            $session['user']['loggedin'] = true;
            $session['user']['superuser'] = 0;

            $result = $this->dispatch('Lotgd.Async.Handler.Bans', 'affectedUsers');

            self::assertSame(0, $jaxon->processCount, 'the handler must not run');
            self::assertSame(403, $result['status']);
            self::assertSame('insufficient_privileges', $result['payload']['error'] ?? null);
            self::assertSame('Forbidden', $result['payload']['message'] ?? null);
        }

        /**
         * A ban master keeps the feature. SU_EDIT_BANS is a mask of
         * SU_MEGAUSER|SU_IS_BANMASTER, so either bit alone must pass.
         */
        public function testBanMasterReachesTheHandler(): void
        {
            global $session;

            $jaxon = $this->installJaxonDouble();
            $session['user']['loggedin'] = true;
            $session['user']['superuser'] = SU_IS_BANMASTER;

            $this->dispatch('Lotgd.Async.Handler.Bans', 'affectedUsers');

            self::assertSame(1, $jaxon->processCount);
        }

        public function testMegauserReachesTheHandler(): void
        {
            global $session;

            $jaxon = $this->installJaxonDouble();
            $session['user']['loggedin'] = true;
            $session['user']['superuser'] = SU_MEGAUSER;

            $this->dispatch('Lotgd.Async.Handler.Bans', 'affectedUsers');

            self::assertSame(1, $jaxon->processCount);
        }

        /**
         * Order matters. Without a session the answer must stay 401, not 403:
         * a privilege-shaped refusal would tell an anonymous caller which
         * callables are privileged.
         */
        public function testUnauthenticatedCallerStillGets401NotAPrivilegeError(): void
        {
            $jaxon = $this->installJaxonDouble();

            $result = $this->dispatch('Lotgd.Async.Handler.Bans', 'affectedUsers');

            self::assertSame(0, $jaxon->processCount);
            self::assertSame(401, $result['status']);
            self::assertSame('authentication_required', $result['payload']['error'] ?? null);
        }

        /**
         * The gate is targeted: unlisted callables are unaffected by it.
         */
        public function testUnlistedCallableIsNotPrivilegeGated(): void
        {
            global $session;

            $jaxon = $this->installJaxonDouble();
            $session['user']['loggedin'] = true;
            $session['user']['superuser'] = 0;

            $this->dispatch('Lotgd.Async.Handler.Mail', 'mailStatus');

            self::assertSame(1, $jaxon->processCount);
        }

        /**
         * The debug echo is refused before dispatch, independently of whether
         * the Jaxon registration options still exclude it.
         */
        public function testCommentaryDebugEchoIsNotInvokable(): void
        {
            global $session;

            $jaxon = $this->installJaxonDouble();
            $session['user']['loggedin'] = true;
            $session['user']['superuser'] = SU_MEGAUSER;

            $result = $this->dispatch('Lotgd.Async.Handler.Commentary', 'test');

            self::assertSame(0, $jaxon->processCount);
            self::assertSame(403, $result['status']);
            self::assertSame('callable_not_allowed', $result['payload']['error'] ?? null);
        }

        /**
         * The real polling callables on the same class stay reachable.
         */
        public function testCommentaryPollingRemainsReachable(): void
        {
            global $session;

            $jaxon = $this->installJaxonDouble();
            $session['user']['loggedin'] = true;

            $this->dispatch('Lotgd.Async.Handler.Commentary', 'pollUpdates');

            self::assertSame(1, $jaxon->processCount);
        }

        /**
         * The registry holds constant names, so a typo would resolve to
         * nothing. The check denies in that case, but a silent deny in
         * production is a poor way to find out — assert the names exist.
         */
        public function testEveryRegisteredConstantNameResolves(): void
        {
            $registry = lotgd_async_required_superuser_bits();
            self::assertNotSame([], $registry, 'the registry must not be empty');

            foreach ($registry as $className => $methods) {
                foreach ($methods as $methodName => $constantName) {
                    self::assertTrue(
                        defined($constantName),
                        "$className.$methodName requires undefined constant $constantName"
                    );
                    self::assertNotSame(0, (int) constant($constantName), "$constantName must not be zero");
                }
            }
        }

        /**
         * A callable with a requirement the account cannot satisfy is denied
         * even when it is also unauth allowlisted. That combination is a
         * configuration contradiction; it must fail closed.
         */
        public function testPrivilegeCheckDeniesWithoutASuperuserValue(): void
        {
            global $session;

            $session['user']['loggedin'] = true;
            unset($session['user']['superuser']);

            self::assertFalse(lotgd_async_has_required_privileges([
                'class' => 'Lotgd.Async.Handler.Bans',
                'method' => 'affectedUsers',
            ]));
        }
    }
}
