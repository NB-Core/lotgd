<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async {

    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class TwoFactorAuthPasskeyBridgeScriptTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session, $forms_output;

            require_once __DIR__ . '/../bootstrap.php';
            require_once __DIR__ . '/../../modules/twofactorauth.php';

            $forms_output = '';
            $session = [
                'user' => [
                    'superuser' => 0,
                ],
            ];
        }

        public function testBridgeResolverChecksLegacyAndModernNamespacesInDeterministicOrder(): void
        {
            $script = $this->renderBridgeScript();

            self::assertStringContainsString('window.twofactorauthResolvePasskeyHandler', $script);
            self::assertStringContainsString('resolvedHandlers&&resolvedHandlers.TwoFactorAuthPasskey', $script);
            self::assertStringContainsString('window.Lotgd&&window.Lotgd.Async&&window.Lotgd.Async.Handler', $script);
            self::assertStringContainsString('window.JaxonLotgd&&window.JaxonLotgd.Async&&window.JaxonLotgd.Async.Handler', $script);
        }

        public function testBridgeDispatchAcceptsLotgdOrJaxonLotgdHandlerWhenMethodExists(): void
        {
            $script = $this->renderBridgeScript();

            self::assertStringContainsString(
                "typeof candidate[requestedMethod]==='function'",
                $script
            );
            self::assertStringContainsString(
                "window.Lotgd&&window.Lotgd.Async&&window.Lotgd.Async.Handler",
                $script
            );
            self::assertStringContainsString(
                "window.JaxonLotgd&&window.JaxonLotgd.Async&&window.JaxonLotgd.Async.Handler",
                $script
            );
        }

        public function testBridgeRejectsCleanlyWhenNoPasskeyNamespaceHasRequestedMethod(): void
        {
            $script = $this->renderBridgeScript();

            self::assertStringContainsString("if(!namespace){", $script);
            self::assertStringContainsString("reject(new Error('Passkey async handler unavailable.'));", $script);
        }

        public function testBridgeMegauserDiagnosticsIncludeNamespaceRootsAndMethod(): void
        {
            global $session;

            $session['user']['superuser'] = SU_MEGAUSER;
            $script = $this->renderBridgeScript();

            self::assertStringContainsString("const showDebug=true;", $script);
            self::assertStringContainsString("console.warn('[TwoFactorAuthPasskey] Transport failure:'", $script);
        }

        private function renderBridgeScript(): string
        {
            global $forms_output;

            $forms_output = '';
            twofactorauth_render_passkey_jaxon_bridge();

            return (string) $forms_output;
        }
    }
}
