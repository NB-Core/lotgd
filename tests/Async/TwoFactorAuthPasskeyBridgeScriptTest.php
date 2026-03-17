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

            $first = strpos($script, "name:'window.getJaxonHandlers()'");
            $second = strpos($script, "name:'window.Lotgd.Async.Handler'");
            $third = strpos($script, "name:'window.JaxonLotgd.Async.Handler'");

            self::assertIsInt($first);
            self::assertIsInt($second);
            self::assertIsInt($third);
            self::assertLessThan($second, $first);
            self::assertLessThan($third, $second);
        }

        public function testBridgeDispatchAcceptsLotgdOrJaxonLotgdHandlerWhenMethodExists(): void
        {
            $script = $this->renderBridgeScript();

            self::assertStringContainsString(
                "typeof candidate.handler[requestedMethod]==='function'",
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
            self::assertStringContainsString("const hasAnyRoot=sources.some", $script);
            self::assertStringContainsString(
                "'Passkey async export missing from generated Jaxon script.'",
                $script
            );
            self::assertStringContainsString("const missingExportMessage=hasAnyRoot?'Passkey async export missing from generated Jaxon script.':'Passkey async handler unavailable.';", $script);
            self::assertStringContainsString("reject(new Error(missingExportMessage));", $script);
        }

        public function testBridgeMegauserDiagnosticsIncludeNamespaceRootsAndMethod(): void
        {
            global $session;

            $session['user']['superuser'] = SU_MEGAUSER;
            $script = $this->renderBridgeScript();

            self::assertStringContainsString("const showDebug=true;", $script);
            self::assertStringContainsString("' rootPresent='+String", $script);
            self::assertStringContainsString("' method='+String(method)", $script);
            self::assertStringContainsString("resolvedSource=", $script);
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
