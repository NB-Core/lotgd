<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async {

    use Lotgd\Async\Handler\TwoFactorAuthPasskey;
    use Lotgd\Security\PasskeyCredentialRepository;
    use Lotgd\Security\PasskeyService;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class TwoFactorAuthPasskeyHandlerTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session;

            require_once __DIR__ . '/../bootstrap.php';

            // Define global test doubles lazily inside the isolated process only.
            if (!\function_exists('get_module_pref')) {
                eval(
                    <<<'PHPSTUB'
                    namespace {
                        function get_module_pref(string $name, ?string $module = null, ?int $user = null)
                        {
                            $acctId = $user ?? 0;

                            return $GLOBALS['twofactorauth_module_prefs'][$acctId][$name] ?? 0;
                        }
                    }
                    PHPSTUB
                );
            }

            if (!\function_exists('set_module_pref')) {
                eval(
                    <<<'PHPSTUB'
                    namespace {
                        function set_module_pref(string $name, mixed $value, ?string $module = null, ?int $user = null): void
                        {
                            $acctId = $user ?? 0;
                            if (!isset($GLOBALS['twofactorauth_module_prefs'][$acctId]) || !is_array($GLOBALS['twofactorauth_module_prefs'][$acctId])) {
                                $GLOBALS['twofactorauth_module_prefs'][$acctId] = [];
                            }

                            $GLOBALS['twofactorauth_module_prefs'][$acctId][$name] = $value;
                        }
                    }
                    PHPSTUB
                );
            }

            if (!\function_exists('get_module_setting')) {
                eval(
                    <<<'PHPSTUB'
                    namespace {
                        function get_module_setting(string $name, ?string $module = null)
                        {
                            return $GLOBALS['twofactorauth_module_settings'][$name] ?? 0;
                        }
                    }
                    PHPSTUB
                );
            }

            $session = [
                'user' => [
                    'acctid' => 42,
                    'login' => 'tester',
                    'name' => 'Test User',
                    'superuser' => 0,
                ],
            ];

            $session['twofactorauth_csrf'] = 'csrf-test-token';
            $GLOBALS['twofactorauth_module_prefs'] = [
                42 => [
                    'pending_challenge' => 1,
                    'failed_attempts' => 0,
                    'locked_until' => 0,
                ],
            ];
            $GLOBALS['twofactorauth_module_settings'] = [
                'max_attempts' => 5,
                'lock_seconds' => 60,
            ];
        }

        public function testBeginRegistrationSuccessReturnsJaxonPayload(): void
        {
            $repo = $this->createMock(PasskeyCredentialRepository::class);
            $repo->method('hasCredentialTable')->willReturn(true);
            $repo->method('listForAccount')->willReturn([]);

            $service = $this->createMock(PasskeyService::class);
            $service->method('beginRegistration')->willReturn(['publicKey' => ['challenge' => 'abc']]);

            $handler = new TwoFactorAuthPasskey();
            $handler->setService($service);
            $handler->setRepository($repo);
            $response = $handler->beginRegistration('req-1', 'csrf-test-token', 'My device');

            $payload = $this->extractCallbackPayload($response->getCommands());
            self::assertSame('req-1', $payload['requestId']);
            self::assertTrue($payload['data']['ok']);
            self::assertSame('abc', $payload['data']['options']['publicKey']['challenge']);
        }


        public function testBeginRegistrationWorksWithNoConstructorArgumentsInProductionPath(): void
        {
            $repo = $this->createMock(PasskeyCredentialRepository::class);
            $repo->method('hasCredentialTable')->willReturn(true);
            $repo->method('listForAccount')->willReturn([]);

            $service = $this->createMock(PasskeyService::class);
            $service->method('beginRegistration')->willReturn(['publicKey' => ['challenge' => 'prod-path-challenge']]);

            $handler = new TwoFactorAuthPasskey();
            $handler->setService($service);
            $handler->setRepository($repo);

            $response = $handler->beginRegistration('req-default-ctor', 'csrf-test-token', 'Laptop');
            $payload = $this->extractCallbackPayload($response->getCommands());

            self::assertSame('req-default-ctor', $payload['requestId']);
            self::assertTrue($payload['data']['ok']);
            self::assertArrayHasKey('options', $payload['data']);
            self::assertArrayHasKey('publicKey', $payload['data']['options']);
            self::assertArrayHasKey('challenge', $payload['data']['options']['publicKey']);
            self::assertSame('prod-path-challenge', $payload['data']['options']['publicKey']['challenge']);
        }

        public function testBeginRegistrationRepositoryExceptionReturnsStructuredErrorPayload(): void
        {
            $repo = $this->createMock(PasskeyCredentialRepository::class);
            $repo->method('hasCredentialTable')->willReturn(true);
            $repo->method('listForAccount')->willThrowException(new \RuntimeException('no such table: twofactorauth_passkeys'));

            $service = $this->createMock(PasskeyService::class);
            $service->expects(self::never())->method('beginRegistration');

            $handler = new TwoFactorAuthPasskey();
            $handler->setService($service);
            $handler->setRepository($repo);
            $response = $handler->beginRegistration('req-repo-fail', 'csrf-test-token', 'My device');

            $payload = $this->extractCallbackPayload($response->getCommands());
            self::assertSame('req-repo-fail', $payload['requestId']);
            self::assertFalse($payload['data']['ok']);
            self::assertSame('begin_repo_exception', $payload['data']['error']);
            self::assertArrayNotHasKey('debug_message', $payload['data']);
        }

        public function testBeginRegistrationRepositoryExceptionAlwaysReturnsCallbackPayload(): void
        {
            $repo = $this->createMock(PasskeyCredentialRepository::class);
            $repo->method('hasCredentialTable')->willReturn(true);
            $repo->method('listForAccount')->willThrowException(new \RuntimeException('db unavailable'));

            $handler = new TwoFactorAuthPasskey();
            $handler->setService($this->createMock(PasskeyService::class));
            $handler->setRepository($repo);
            $response = $handler->beginRegistration('req-timeout-guard', 'csrf-test-token', 'Label');

            $commands = $response->getCommands();
            self::assertNotEmpty($commands);
            $payload = $this->extractCallbackPayload($commands);

            self::assertSame('req-timeout-guard', $payload['requestId']);
            self::assertFalse($payload['data']['ok']);
            self::assertArrayHasKey('error', $payload['data']);
        }

        public function testBeginRegistrationHidesDiagnosticsForNormalUser(): void
        {
            $repo = $this->createMock(PasskeyCredentialRepository::class);
            $repo->method('hasCredentialTable')->willReturn(false);

            $handler = new TwoFactorAuthPasskey();
            $handler->setService($this->createMock(PasskeyService::class));
            $handler->setRepository($repo);

            $GLOBALS['session']['user']['superuser'] = 0;
            $payload = $this->extractCallbackPayload(
                $handler->beginRegistration('req-normal', 'csrf-test-token', 'Label')->getCommands()
            );

            self::assertSame('begin_repo_exception', $payload['data']['error']);
            self::assertArrayNotHasKey('debug_message', $payload['data']);
            self::assertArrayNotHasKey('diagnostic', $payload['data']);
        }

        public function testBeginRegistrationShowsDiagnosticsForMegauser(): void
        {
            $repo = $this->createMock(PasskeyCredentialRepository::class);
            $repo->method('hasCredentialTable')->willReturn(false);

            $handler = new TwoFactorAuthPasskey();
            $handler->setService($this->createMock(PasskeyService::class));
            $handler->setRepository($repo);

            $GLOBALS['session']['user']['superuser'] = 1;
            $payload = $this->extractCallbackPayload(
                $handler->beginRegistration('req-mega', 'csrf-test-token', 'Label')->getCommands()
            );

            self::assertSame('begin_repo_exception', $payload['data']['error']);
            self::assertArrayHasKey('debug_message', $payload['data']);
            self::assertArrayHasKey('diagnostic', $payload['data']);
        }

        public function testBeginRegistrationRejectsInvalidCsrf(): void
        {
            $handler = new TwoFactorAuthPasskey();

            $response = $handler->beginRegistration('req-csrf', 'wrong-token', 'Label');
            $payload = $this->extractCallbackPayload($response->getCommands());

            self::assertFalse($payload['data']['ok']);
            self::assertSame('csrf', $payload['data']['error']);
        }

        public function testVerifyAuthenticationFailureIncrementsAttemptCounter(): void
        {
            $service = $this->createMock(PasskeyService::class);
            $service->method('finishAuthentication')->willReturn(['ok' => false, 'error' => 'verify_failed', 'clone' => false]);

            $handler = new TwoFactorAuthPasskey();
            $handler->setService($service);
            $response = $handler->verifyAuthentication('req-verify', 'csrf-test-token', ['id' => 'cred', 'response' => []]);
            $payload = $this->extractCallbackPayload($response->getCommands());

            self::assertFalse($payload['data']['ok']);
            self::assertSame('verify_failed', $payload['data']['error']);
            self::assertSame(1, (int) ($GLOBALS['twofactorauth_module_prefs'][42]['failed_attempts'] ?? 0));
        }

        public function testJaxonBootstrapExportsOnlyPasskeyAllowlistedMethods(): void
        {
            require __DIR__ . '/../../async/common/jaxon.php';

            global $jaxon;
            self::assertNotNull($jaxon);

            $exportedMethods = $this->extractPasskeyExportedMethods((string) $jaxon->getScript());

            self::assertSame(
                ['beginRegistration', 'finishRegistration', 'beginAuthentication', 'verifyAuthentication'],
                $exportedMethods
            );
        }

        public function testJaxonBootstrapDoesNotExportNonAllowlistedPasskeyMethods(): void
        {
            require __DIR__ . '/../../async/common/jaxon.php';

            global $jaxon;
            self::assertNotNull($jaxon);

            $script = (string) $jaxon->getScript();

            // These public methods are used by tests/DI only and must never be remotely callable.
            self::assertStringNotContainsString("'setService'", $script);
            self::assertStringNotContainsString("'setRepository'", $script);
            self::assertStringNotContainsString("'setRepositoryFactory'", $script);
            self::assertStringNotContainsString("'setServiceFactory'", $script);
        }


        public function testBeginAuthenticationRepositoryExceptionReturnsCallbackPayload(): void
        {
            $repo = $this->createMock(PasskeyCredentialRepository::class);
            $repo->method('listForAccount')->willThrowException(new \RuntimeException('repository unavailable'));

            $service = $this->createMock(PasskeyService::class);
            $service->expects(self::never())->method('beginAuthentication');

            $handler = new TwoFactorAuthPasskey();
            $handler->setRepository($repo);
            $handler->setService($service);

            $response = $handler->beginAuthentication('req-auth-exception', 'csrf-test-token');
            $payload = $this->extractCallbackPayload($response->getCommands());

            self::assertSame('req-auth-exception', $payload['requestId']);
            self::assertFalse($payload['data']['ok']);
            self::assertSame('begin_auth_exception', $payload['data']['error']);
        }

        public function testBeginAuthenticationWithoutPendingChallengeIsRejected(): void
        {
            $GLOBALS['twofactorauth_module_prefs'][42]['pending_challenge'] = 0;

            $handler = new TwoFactorAuthPasskey();

            $response = $handler->beginAuthentication('req-pending', 'csrf-test-token');
            $payload = $this->extractCallbackPayload($response->getCommands());

            self::assertFalse($payload['data']['ok']);
            self::assertSame('no_pending', $payload['data']['error']);
        }

        /**
         * Decode the Jaxon command list and return the callback envelope.
         *
         * @param array<int, mixed> $commands
         * @return array{requestId:string,data:array<string,mixed>}
         */
        private function extractCallbackPayload(array $commands): array
        {
            self::assertNotEmpty($commands);

            $first = $commands[0];
            self::assertSame('script.exec.call', $first['name'] ?? '');
            self::assertSame('twofactorauthHandleJaxonResponse', $first['args']['func'] ?? '');

            return [
                'requestId' => (string) ($first['args']['args'][0] ?? ''),
                'data' => (array) ($first['args']['args'][1] ?? []),
            ];
        }

        /**
         * Parse exported passkey methods from generated Jaxon bootstrap script.
         *
         * @return array<int, string>
         */
        private function extractPasskeyExportedMethods(string $script): array
        {
            $prefix = 'Lotgd_Async_Handler_TwoFactorAuthPasskey = {';
            $start = strpos($script, $prefix);
            self::assertNotFalse($start, 'Passkey handler object was not exported in Jaxon bootstrap script.');

            $slice = substr($script, (int) $start);
            self::assertIsString($slice);

            $end = strpos($slice, '};', 0);
            self::assertNotFalse($end, 'Unable to parse passkey handler export block from Jaxon bootstrap script.');

            $block = substr($slice, 0, (int) $end);
            self::assertIsString($block);

            preg_match_all('/^\s*([a-zA-Z0-9_]+):\s+\(\.\.\.args\)\s+=>\s+jx\.rc\(/m', $block, $matches);

            return array_values((array) ($matches[1] ?? []));
        }
    }
}
