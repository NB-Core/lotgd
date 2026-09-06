<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async {

    use PHPUnit\Framework\TestCase;

    /**
     * Jaxon 5 dispatches on the JSON `jxncall` descriptor. The async policy layer must
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
        private function postJxncall(array $call): void
        {
            $_POST['jxncall'] = json_encode($call, JSON_THROW_ON_ERROR);
        }

        public function testContextIsReadFromTheJxncallDescriptor(): void
        {
            $this->postJxncall([
                'type' => 'class',
                'name' => 'Lotgd.Async.Handler.Commentary',
                'method' => 'pollUpdates',
                'args' => [],
            ]);

            $this->assertSame(
                ['class' => 'Lotgd.Async.Handler.Commentary', 'method' => 'pollUpdates'],
                lotgd_async_request_context()
            );
        }

        public function testJxncallIsAlsoReadFromQueryParameters(): void
        {
            $_GET['jxncall'] = json_encode([
                'type' => 'class',
                'name' => 'Lotgd.Async.Handler.Mail',
                'method' => 'mailStatus',
            ], JSON_THROW_ON_ERROR);

            $this->assertSame(
                ['class' => 'Lotgd.Async.Handler.Mail', 'method' => 'mailStatus'],
                lotgd_async_request_context()
            );
        }

        public function testMultipartPayloadsAreUrlDecodedLikeJaxonDoes(): void
        {
            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=x';
            $_POST['jxncall'] = rawurlencode(json_encode([
                'type' => 'class',
                'name' => 'Lotgd.Async.Handler.Commentary',
                'method' => 'pollUpdates',
            ], JSON_THROW_ON_ERROR));

            $this->assertSame(
                ['class' => 'Lotgd.Async.Handler.Commentary', 'method' => 'pollUpdates'],
                lotgd_async_request_context()
            );
        }

        /**
         * A payload that describes one callable to Jaxon and another to the policy layer
         * must never be resolved in favour of the legacy fields.
         */
        public function testLegacyFieldsCannotOverrideTheJxncallDescriptor(): void
        {
            $this->postJxncall([
                'type' => 'class',
                'name' => 'Lotgd.Async.Handler.TwoFactorAuthPasskey',
                'method' => 'beginRegistration',
            ]);
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Commentary';
            $_POST['jxnmthd'] = 'pollUpdates';

            $context = lotgd_async_request_context();

            $this->assertSame('Lotgd.Async.Handler.TwoFactorAuthPasskey', $context['class']);
            $this->assertSame('beginRegistration', $context['method']);
            // ...so the session lock is kept for the handler Jaxon really dispatches.
            $this->assertFalse(lotgd_async_is_session_readonly_callable($context));
        }

        /**
         * A `func` descriptor is dispatchable by Jaxon's FunctionPlugin, so it must not
         * be reinterpreted through the legacy fields either.
         */
        public function testNonClassDescriptorsYieldAnUnknownCallable(): void
        {
            $this->postJxncall([
                'type' => 'func',
                'name' => 'Lotgd.Async.Handler.Commentary',
                'method' => 'pollUpdates',
            ]);
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Commentary';
            $_POST['jxnmthd'] = 'pollUpdates';

            $this->assertSame(['class' => '', 'method' => ''], lotgd_async_request_context());
        }

        /**
         * Presence of the field is what makes it authoritative: an unusable descriptor
         * must not hand control back to fields the same request also controls.
         */
        public function testMalformedJxncallDoesNotFallBackToLegacyFields(): void
        {
            $_POST['jxncall'] = '{not valid json';
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Mail';
            $_POST['jxnmthd'] = 'mailStatus';

            $this->assertSame(['class' => '', 'method' => ''], lotgd_async_request_context());
        }

        public function testDescriptorWithNonStringKeysDoesNotFallBackToLegacyFields(): void
        {
            $this->postJxncall([
                'type' => 'class',
                'name' => ['Lotgd.Async.Handler.Commentary'],
                'method' => 'pollUpdates',
            ]);
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Commentary';
            $_POST['jxnmthd'] = 'pollUpdates';

            $this->assertSame(['class' => '', 'method' => ''], lotgd_async_request_context());
        }

        /**
         * The legacy scan is only reachable when no descriptor is present at all, which
         * is a payload shape Jaxon refuses to dispatch.
         */
        public function testLegacyFieldsAreUsedOnlyWithoutAnyDescriptor(): void
        {
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Mail';
            $_POST['jxnmthd'] = 'mailStatus';

            $this->assertSame(
                ['class' => 'Lotgd.Async.Handler.Mail', 'method' => 'mailStatus'],
                lotgd_async_request_context()
            );
        }

        public function testEmptyJxncallStringDoesNotFallBackToLegacyFields(): void
        {
            $_POST['jxncall'] = '';
            $_POST['jxncls'] = 'Lotgd.Async.Handler.Mail';
            $_POST['jxnmthd'] = 'mailStatus';

            $this->assertSame(['class' => '', 'method' => ''], lotgd_async_request_context());
        }

        public function testControlCharactersAreStrippedFromTheDescriptor(): void
        {
            $this->postJxncall([
                'type' => 'class',
                'name' => "Lotgd.Async.Handler.Mail\n",
                'method' => "mailStatus\r",
            ]);

            $this->assertSame(
                ['class' => 'Lotgd.Async.Handler.Mail', 'method' => 'mailStatus'],
                lotgd_async_request_context()
            );
        }

        public function testEmptyPayloadYieldsAnEmptyContext(): void
        {
            $this->assertSame(['class' => '', 'method' => ''], lotgd_async_request_context());
        }
    }
}
