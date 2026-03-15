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

            $GLOBALS['twofactorauth_csrf_token'] = 'csrf-test-token';
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
            $repo->method('listForAccount')->willReturn([]);

            $service = $this->createMock(PasskeyService::class);
            $service->method('beginRegistration')->willReturn(['publicKey' => ['challenge' => 'abc']]);

            $handler = new TwoFactorAuthPasskey($service, $repo);
            $response = $handler->beginRegistration('req-1', 'csrf-test-token', 'My device');

            $payload = $this->extractCallbackPayload($response->getOutput());
            self::assertSame('req-1', $payload['requestId']);
            self::assertTrue($payload['data']['ok']);
            self::assertSame('abc', $payload['data']['options']['publicKey']['challenge']);
        }

        public function testBeginRegistrationRejectsInvalidCsrf(): void
        {
            $handler = new TwoFactorAuthPasskey(
                $this->createMock(PasskeyService::class),
                $this->createMock(PasskeyCredentialRepository::class)
            );

            $response = $handler->beginRegistration('req-csrf', 'wrong-token', 'Label');
            $payload = $this->extractCallbackPayload($response->getOutput());

            self::assertFalse($payload['data']['ok']);
            self::assertSame('csrf', $payload['data']['error']);
        }

        public function testVerifyAuthenticationFailureIncrementsAttemptCounter(): void
        {
            $service = $this->createMock(PasskeyService::class);
            $service->method('finishAuthentication')->willReturn(['ok' => false, 'error' => 'verify_failed', 'clone' => false]);

            $handler = new TwoFactorAuthPasskey($service, $this->createMock(PasskeyCredentialRepository::class));
            $response = $handler->verifyAuthentication('req-verify', 'csrf-test-token', ['id' => 'cred', 'response' => []]);
            $payload = $this->extractCallbackPayload($response->getOutput());

            self::assertFalse($payload['data']['ok']);
            self::assertSame('verify_failed', $payload['data']['error']);
            self::assertSame(1, (int) ($GLOBALS['twofactorauth_module_prefs'][42]['failed_attempts'] ?? 0));
        }

        public function testBeginAuthenticationWithoutPendingChallengeIsRejected(): void
        {
            $GLOBALS['twofactorauth_module_prefs'][42]['pending_challenge'] = 0;

            $handler = new TwoFactorAuthPasskey(
                $this->createMock(PasskeyService::class),
                $this->createMock(PasskeyCredentialRepository::class)
            );

            $response = $handler->beginAuthentication('req-pending', 'csrf-test-token');
            $payload = $this->extractCallbackPayload($response->getOutput());

            self::assertFalse($payload['data']['ok']);
            self::assertSame('no_pending', $payload['data']['error']);
        }

        /**
         * Decode the Jaxon response and return the callback envelope.
         *
         * @return array{requestId:string,data:array<string,mixed>}
         */
        private function extractCallbackPayload(string $output): array
        {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $commands = $decoded['jxn']['commands'] ?? [];
            self::assertNotEmpty($commands);

            $first = $commands[0];
            self::assertSame('script.exec.call', $first['name'] ?? '');
            self::assertSame('window.twofactorauthHandleJaxonResponse', $first['args']['func'] ?? '');

            return [
                'requestId' => (string) ($first['args']['args'][0] ?? ''),
                'data' => (array) ($first['args']['args'][1] ?? []),
            ];
        }
    }
}
