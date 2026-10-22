<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async {

    use Lotgd\Async\Dispatcher;
    use Lotgd\Async\Response;
    use Lotgd\Settings;
    use Lotgd\Tests\Stubs\DummySettings;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class DispatcherTest extends TestCase
    {
        protected function setUp(): void
        {
            global $session;

            if (!defined('LOTGD_ASYNC_PROCESS_TEST_MODE')) {
                define('LOTGD_ASYNC_PROCESS_TEST_MODE', true);
            }
            require_once __DIR__ . '/../bootstrap.php';
            Settings::setInstance(new DummySettings(['realtime_enabled' => 0]));
            $session = [];
        }

        /**
         * @param array<string,mixed> $call
         */
        private function dispatcher(array $call): Dispatcher
        {
            return new Dispatcher(['call' => json_encode($call, JSON_THROW_ON_ERROR)]);
        }

        public function testAValidCallIsParsedAndDescribed(): void
        {
            $dispatcher = $this->dispatcher(['class' => 'Commentary', 'method' => 'pollUpdates', 'args' => ['village', 3]]);

            self::assertTrue($dispatcher->canProcessRequest());
            self::assertSame(
                ['class' => 'Lotgd.Async.Handler.Commentary', 'method' => 'pollUpdates'],
                $dispatcher->describe()
            );
        }

        public function testArgsAreOptional(): void
        {
            self::assertTrue($this->dispatcher(['class' => 'Realtime', 'method' => 'poll'])->canProcessRequest());
        }

        public function testNoCallFieldIsNotARequest(): void
        {
            $dispatcher = new Dispatcher([]);

            self::assertFalse($dispatcher->canProcessRequest());
            self::assertSame(['class' => '', 'method' => ''], $dispatcher->describe());
            $this->expectException(\InvalidArgumentException::class);
            $dispatcher->processRequest();
        }

        /**
         * @return array<string, array{0: mixed}>
         */
        public static function unusableFieldProvider(): array
        {
            return [
                'empty string' => [''],
                'malformed json' => ['{not json'],
                'json scalar' => ['"Commentary"'],
                'json null' => ['null'],
                'not a string' => [['class' => 'Commentary']],
                'int' => [5],
            ];
        }

        #[DataProvider('unusableFieldProvider')]
        public function testUnusableCallFieldsAreRefused(mixed $value): void
        {
            $dispatcher = new Dispatcher(['call' => $value]);

            self::assertFalse($dispatcher->canProcessRequest());
            self::assertSame(['class' => '', 'method' => ''], $dispatcher->describe());
        }

        /**
         * @return array<string, array{0: array<string,mixed>}>
         */
        public static function malformedCallProvider(): array
        {
            return [
                'args object' => [['class' => 'Realtime', 'method' => 'poll', 'args' => ['cursor' => 1]]],
                'args scalar' => [['class' => 'Realtime', 'method' => 'poll', 'args' => 'x']],
                'args sparse' => [['class' => 'Realtime', 'method' => 'poll', 'args' => [1 => 5]]],
                'class missing' => [['method' => 'poll']],
                'method missing' => [['class' => 'Realtime']],
                'class not string' => [['class' => ['Realtime'], 'method' => 'poll']],
                'method not string' => [['class' => 'Realtime', 'method' => 7]],
                'class with dots' => [['class' => 'Lotgd.Async.Handler.Realtime', 'method' => 'poll']],
                'class with backslashes' => [['class' => 'Lotgd\\Async\\Handler\\Realtime', 'method' => 'poll']],
                'class with slash' => [['class' => '../Realtime', 'method' => 'poll']],
                'method with dot' => [['class' => 'Realtime', 'method' => 'poll.x']],
                'method with backslash' => [['class' => 'Realtime', 'method' => 'Foo\\poll']],
                'method with parens' => [['class' => 'Realtime', 'method' => 'poll()']],
                'method with space' => [['class' => 'Realtime', 'method' => 'poll ']],
                'class leading digit' => [['class' => '1Realtime', 'method' => 'poll']],
                'class empty' => [['class' => '', 'method' => 'poll']],
                'method empty' => [['class' => 'Realtime', 'method' => '']],
            ];
        }

        /**
         * @param array<string,mixed> $call
         */
        #[DataProvider('malformedCallProvider')]
        public function testMalformedCallsAreNotRequests(array $call): void
        {
            $dispatcher = $this->dispatcher($call);

            self::assertFalse($dispatcher->canProcessRequest());
            self::assertSame(['class' => '', 'method' => ''], $dispatcher->describe());
            $this->expectException(\InvalidArgumentException::class);
            $dispatcher->processRequest();
        }

        public function testTheConstructorCannotBeCalled(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->dispatcher(['class' => 'Realtime', 'method' => '__construct'])->processRequest();
        }

        public function testMagicMethodsCannotBeCalled(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->dispatcher(['class' => 'Realtime', 'method' => '__destruct'])->processRequest();
        }

        public function testAnUnknownClassIsRefused(): void
        {
            $dispatcher = $this->dispatcher(['class' => 'Nope', 'method' => 'poll']);

            $this->expectException(\InvalidArgumentException::class);
            $dispatcher->processRequest();
        }

        public function testAPhpClassNameIsNotAClass(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->dispatcher(['class' => 'ArrayObject', 'method' => 'count'])->processRequest();
        }

        public function testAnUnknownMethodIsRefused(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->dispatcher(['class' => 'Realtime', 'method' => 'nothing'])->processRequest();
        }

        public function testTheCoreHandlersAreRegistered(): void
        {
            self::assertSame(
                ['Bans', 'Commentary', 'Duel', 'Mail', 'Realtime', 'Timeout', 'TwoFactorAuthPasskey'],
                array_keys((new Dispatcher([]))->classes())
            );
        }

        /**
         * @return array<string, array{0:string,1:string}>
         */
        public static function excludedProvider(): array
        {
            return [
                'Commentary.test' => ['Commentary', 'test'],
                'Timeout.getInstance' => ['Timeout', 'getInstance'],
                'passkey setService' => ['TwoFactorAuthPasskey', 'setService'],
                'passkey setRepository' => ['TwoFactorAuthPasskey', 'setRepository'],
            ];
        }

        #[DataProvider('excludedProvider')]
        public function testExcludedMethodsAreRefused(string $class, string $method): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->dispatcher(['class' => $class, 'method' => $method, 'args' => []])->processRequest();
        }

        public function testStaticMethodsAreRefused(): void
        {
            // Mail::counts() is public static: PHP-side only.
            if (!method_exists(\Lotgd\Async\Handler\Mail::class, 'counts')) {
                self::markTestSkipped('Mail::counts() does not exist');
            }
            $this->expectException(\InvalidArgumentException::class);
            $this->dispatcher(['class' => 'Mail', 'method' => 'counts', 'args' => [1]])->processRequest();
        }

        public function testNonPublicMethodsAreRefused(): void
        {
            // Realtime::player() is private.
            $this->expectException(\InvalidArgumentException::class);
            $this->dispatcher(['class' => 'Realtime', 'method' => 'player'])->processRequest();
        }

        public function testTooFewArgumentsAreRefused(): void
        {
            // Realtime::region(string $region) needs one.
            $this->expectException(\InvalidArgumentException::class);
            $this->dispatcher(['class' => 'Realtime', 'method' => 'region', 'args' => []])->processRequest();
        }

        public function testATypeErrorIsTheCallersMistake(): void
        {
            // poll(int $cursor) given an array.
            $this->expectException(\InvalidArgumentException::class);
            $this->dispatcher(['class' => 'Realtime', 'method' => 'poll', 'args' => [['x']]])->processRequest();
        }

        public function testASuccessfulCallReturnsTheHandlersResponse(): void
        {
            $response = $this->dispatcher(['class' => 'Realtime', 'method' => 'poll', 'args' => [0]])->processRequest();

            self::assertInstanceOf(Response::class, $response);
            self::assertSame([], $response->getCommands());
            self::assertSame(['realtime' => ['disabled' => true]], $response->getData());
        }

        public function testOptionalArgumentsMayBeOmittedAndSurplusOnesAreIgnored(): void
        {
            $omitted = $this->dispatcher(['class' => 'Realtime', 'method' => 'poll'])->processRequest();
            $surplus = $this->dispatcher(['class' => 'Realtime', 'method' => 'poll', 'args' => [0, 'x', 'y']])->processRequest();

            self::assertSame(['realtime' => ['disabled' => true]], $omitted->getData());
            self::assertSame(['realtime' => ['disabled' => true]], $surplus->getData());
        }

        public function testARegionOfARealtimeOffPlayerIsNull(): void
        {
            $response = $this->dispatcher(['class' => 'Realtime', 'method' => 'region', 'args' => ['stats']])->processRequest();

            self::assertSame(['region' => null], $response->getData());
        }

        public function testEachRegionHasARateLimitOfItsOwn(): void
        {
            self::assertSame('Lotgd.Async.Handler.Realtime::region:mail', $this->dispatcher(['class' => 'Realtime', 'method' => 'region', 'args' => ['mail']])->rateKey());
            self::assertSame('Lotgd.Async.Handler.Realtime::region:stats', $this->dispatcher(['class' => 'Realtime', 'method' => 'region', 'args' => ['stats']])->rateKey());
            self::assertSame('Lotgd.Async.Handler.Realtime::region:?', $this->dispatcher(['class' => 'Realtime', 'method' => 'region', 'args' => ["x\ny"]])->rateKey());
            self::assertSame('Lotgd.Async.Handler.Commentary::pollUpdates', $this->dispatcher(['class' => 'Commentary', 'method' => 'pollUpdates', 'args' => ['village']])->rateKey());
        }

        public function testOnlyTheDeclaredSpellingOfAMethodIsACall(): void
        {
            // PHP would run affectedUsers for this; the privilege table keys on the
            // declared spelling, so a call that does not use it must not run.
            $this->expectException(\InvalidArgumentException::class);
            $this->dispatcher(['class' => 'Bans', 'method' => 'AFFECTEDUSERS', 'args' => ['1.2.3.4', '']])->processRequest();
        }
    }
}
