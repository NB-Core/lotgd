<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async {

    use PHPUnit\Framework\TestCase;

    /**
     * The dispatcher runs the JSON `call` field. The async policy layer must
     * read the same value, otherwise authorization and the session-lock decision apply
     * to a different callable than the one that actually runs.
     *
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    final class ProcessRequestContextTest extends TestCase
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
        }

        /**
         * @param array<string, mixed> $call
         */
        private function postCall(array $call): void
        {
            $_POST['call'] = json_encode($call, JSON_THROW_ON_ERROR);
        }

        public function testContextIsReadFromTheCallField(): void
        {
            $this->postCall(['class' => 'Commentary', 'method' => 'pollUpdates', 'args' => []]);

            $this->assertSame(
                ['class' => 'Lotgd.Async.Handler.Commentary', 'method' => 'pollUpdates'],
                lotgd_async_request_context()
            );
        }

        public function testCallInQueryParametersIsNotAContextButIsNotFallenBackFrom(): void
        {
            $_GET['call'] = json_encode(['class' => 'Mail', 'method' => 'mailStatus'], JSON_THROW_ON_ERROR);
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Commentary';
            $_POST['jxnmthd'] = 'pollUpdates';

            // The dispatcher reads POST only; a GET `call` field must not decide anything,
            // and its presence still blocks the legacy fields.
            $this->assertSame(['class' => '', 'method' => ''], lotgd_async_request_context());
        }

        /**
         * A payload that names one callable in `call` and another in the legacy
         * fields must never be resolved in favour of the legacy fields.
         */
        public function testLegacyFieldsCannotOverrideTheCallField(): void
        {
            $this->postCall(['class' => 'TwoFactorAuthPasskey', 'method' => 'beginRegistration']);
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Commentary';
            $_POST['jxnmthd'] = 'pollUpdates';

            $context = lotgd_async_request_context();

            $this->assertSame('Lotgd.Async.Handler.TwoFactorAuthPasskey', $context['class']);
            $this->assertSame('beginRegistration', $context['method']);
            // ...so the session lock is kept for the handler the dispatcher really runs.
            $this->assertFalse(lotgd_async_is_session_readonly_callable($context));
        }

        /**
         * Presence of the field is what makes it authoritative: an unusable call
         * must not hand control back to fields the same request also controls.
         */
        public function testMalformedCallDoesNotFallBackToLegacyFields(): void
        {
            $_POST['call'] = '{not valid json';
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Mail';
            $_POST['jxnmthd'] = 'mailStatus';

            $this->assertSame(['class' => '', 'method' => ''], lotgd_async_request_context());
        }

        public function testCallWithNonStringKeysDoesNotFallBackToLegacyFields(): void
        {
            $this->postCall(['class' => ['Commentary'], 'method' => 'pollUpdates']);
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Commentary';
            $_POST['jxnmthd'] = 'pollUpdates';

            $this->assertSame(['class' => '', 'method' => ''], lotgd_async_request_context());
        }

        public function testCallNamingAClassPathDoesNotFallBackToLegacyFields(): void
        {
            $this->postCall(['class' => 'Lotgd.Async.Handler.Commentary', 'method' => 'pollUpdates']);
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Mail';
            $_POST['jxnmthd'] = 'mailStatus';

            $this->assertSame(['class' => '', 'method' => ''], lotgd_async_request_context());
        }

        /**
         * The legacy scan is only reachable when no call is present at all, which
         * is a payload shape the dispatcher refuses to run.
         */
        public function testLegacyFieldsAreUsedOnlyWithoutAnyCall(): void
        {
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Mail';
            $_POST['jxnmthd'] = 'mailStatus';

            $this->assertSame(
                ['class' => 'Lotgd.Async.Handler.Mail', 'method' => 'mailStatus'],
                lotgd_async_request_context()
            );
        }

        public function testEmptyCallStringDoesNotFallBackToLegacyFields(): void
        {
            $_POST['call'] = '';
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Mail';
            $_POST['jxnmthd'] = 'mailStatus';

            $this->assertSame(['class' => '', 'method' => ''], lotgd_async_request_context());
        }

        public function testANameWithAControlCharacterIsNoCall(): void
        {
            // Not stripped into a valid name: refused, so the policy and the
            // dispatcher never see two spellings of one call.
            $this->postCall(['class' => "Mail\n", 'method' => "mailStatus\n"]);

            $this->assertSame(['class' => '', 'method' => ''], lotgd_async_request_context());
        }

        public function testEmptyPayloadYieldsAnEmptyContext(): void
        {
            $this->assertSame(['class' => '', 'method' => ''], lotgd_async_request_context());
        }
    }
}
