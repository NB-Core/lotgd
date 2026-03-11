<?php

declare(strict_types=1);

namespace {
    if (!function_exists('get_module_pref')) {
        function get_module_pref(string $name, ?string $module = null, ?int $user = null)
        {
            return $GLOBALS['twofactorauth_test_prefs'][$name] ?? 0;
        }
    }

    if (!function_exists('set_module_pref')) {
        function set_module_pref(string $name, mixed $value, ?string $module = null, ?int $user = null): void
        {
            $GLOBALS['twofactorauth_test_prefs'][$name] = $value;
        }
    }

    if (!function_exists('get_module_setting')) {
        function get_module_setting(string $name, ?string $module = null)
        {
            return $GLOBALS['twofactorauth_test_settings'][$name] ?? 0;
        }
    }

    require_once dirname(__DIR__, 2) . '/modules/twofactorauth.php';
}

namespace Lotgd\Tests\Security {

    use Lotgd\Nav;
    use Lotgd\Output;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    class TwoFactorAuthModuleFlowTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session;

            $session = [
                'loggedin' => true,
                'user' => [
                    'acctid' => 7,
                    'restorepage' => 'forest.php?op=fight',
                ],
                'allowednavs' => [
                    'forest.php?op=fight' => true,
                    'village.php' => true,
                ],
            ];

            $GLOBALS['twofactorauth_test_prefs'] = [
                'enabled' => 1,
                'pending_challenge' => 0,
                'resume_restorepage' => '',
                'resume_allowednavs_json' => '',
            ];

            $GLOBALS['twofactorauth_test_settings'] = [
                'token_digits' => 6,
                'period_seconds' => 30,
                'window' => 1,
                'max_attempts' => 5,
                'lock_seconds' => 120,
            ];
            $_GET = [];
            $_POST = [];
            $_SERVER['REQUEST_URI'] = 'runmodule.php?module=twofactorauth&op=challenge';
        }

        public function testLoginStagesSessionSnapshotAndEveryhitPersistsIt(): void
        {
            global $session;

            twofactorauth_dohook('player-login', []);

            self::assertTrue($session['twofactorauth_pending']);
            self::assertSame('forest.php?op=fight', $session['twofactorauth_resume_restorepage']);
            self::assertSame(['forest.php?op=fight' => true, 'village.php' => true], $session['twofactorauth_resume_allowednavs']);
            self::assertSame('', $GLOBALS['twofactorauth_test_prefs']['resume_restorepage']);

            twofactorauth_dohook('everyhit', []);

            self::assertSame(1, $GLOBALS['twofactorauth_test_prefs']['pending_challenge']);
            self::assertSame('forest.php?op=fight', $GLOBALS['twofactorauth_test_prefs']['resume_restorepage']);
            self::assertArrayHasKey('pending_since', $GLOBALS['twofactorauth_test_prefs']);
            self::assertArrayNotHasKey('twofactorauth_resume_restorepage', $session);
            self::assertArrayNotHasKey('twofactorauth_resume_allowednavs', $session);

            $persistedNavs = json_decode((string) $GLOBALS['twofactorauth_test_prefs']['resume_allowednavs_json'], true);
            self::assertSame(['forest.php?op=fight' => true, 'village.php' => true], $persistedNavs);
        }



        public function testLoginStagesSnapshotFromSerializedAccountAllowedNavsWhenSessionMapIsEmpty(): void
        {
            global $session;

            $session['allowednavs'] = [];
            $session['user']['allowednavs'] = serialize([
                'forest.php?op=fight' => true,
                'village.php' => true,
            ]);

            twofactorauth_dohook('player-login', []);

            self::assertSame(
                ['forest.php?op=fight' => true, 'village.php' => true],
                $session['twofactorauth_resume_allowednavs']
            );

            twofactorauth_dohook('everyhit', []);

            $persistedNavs = json_decode((string) $GLOBALS['twofactorauth_test_prefs']['resume_allowednavs_json'], true);
            self::assertSame(['forest.php?op=fight' => true, 'village.php' => true], $persistedNavs);

            $resolvedTarget = twofactorauth_resolve_resume_target(
                (string) $GLOBALS['twofactorauth_test_prefs']['resume_restorepage'],
                twofactorauth_decode_allowednavs_snapshot((string) $GLOBALS['twofactorauth_test_prefs']['resume_allowednavs_json'])
            );

            self::assertSame('forest.php?op=fight', $resolvedTarget);
        }

        public function testVerifySuccessAddsResumeNavigation(): void
        {
            $secret = \TwoFactorAuthService::generateSecret();
            $token = \TwoFactorAuthService::generateTokenAtTime($secret, 6, 30, time());

            $GLOBALS['twofactorauth_test_prefs']['pending_challenge'] = 1;
            $GLOBALS['twofactorauth_test_prefs']['secret_encrypted'] = \TwoFactorAuthService::encryptSecret($secret, twofactorauth_signing_key());
            $GLOBALS['twofactorauth_test_prefs']['last_used_timestep'] = 0;
            $_POST['token'] = $token;

            twofactorauth_handle_challenge_verification(Output::getInstance());

            self::assertSame(0, $GLOBALS['twofactorauth_test_prefs']['pending_challenge']);

            $foundResumeNav = false;
            foreach (Nav::getSections() as $section) {
                foreach ($section->getItems() as $item) {
                    if ($item->link === 'runmodule.php?module=twofactorauth&op=resume') {
                        $foundResumeNav = true;
                        break 2;
                    }
                }
            }

            self::assertTrue($foundResumeNav, 'Expected verify success to register resume navigation.');
        }

        #[RunInSeparateProcess]
        public function testResumeRestoresSnapshotAndResolvesTarget(): void
        {
            global $session;

            $session['user']['restorepage'] = '';
            $session['allowednavs'] = [];

            $GLOBALS['twofactorauth_test_prefs']['pending_challenge'] = 0;
            $GLOBALS['twofactorauth_test_prefs']['resume_restorepage'] = 'forest.php?op=fight';
            $GLOBALS['twofactorauth_test_prefs']['resume_allowednavs_json'] = json_encode(['forest.php?op=fight' => true]);

            $storedTarget = trim((string) get_module_pref('resume_restorepage'));
            $storedAllowedNavs = twofactorauth_decode_allowednavs_snapshot((string) get_module_pref('resume_allowednavs_json'));

            $session['allowednavs'] = $storedAllowedNavs;
            $session['user']['restorepage'] = $storedTarget;
            $resolvedTarget = twofactorauth_resolve_resume_target($storedTarget, $storedAllowedNavs);
            twofactorauth_clear_resume_snapshot();
            twofactorauth_clear_session_staging_keys();

            self::assertSame('forest.php?op=fight', $resolvedTarget);
            self::assertSame('forest.php?op=fight', $session['user']['restorepage']);
            self::assertSame(['forest.php?op=fight' => true], $session['allowednavs']);
            self::assertSame('', $GLOBALS['twofactorauth_test_prefs']['resume_restorepage']);
            self::assertSame('', $GLOBALS['twofactorauth_test_prefs']['resume_allowednavs_json']);
        }
    }
}
