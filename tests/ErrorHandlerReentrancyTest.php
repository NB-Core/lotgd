<?php

declare(strict_types=1);

namespace Lotgd\Tests {
    use Lotgd\ErrorHandler;
    use Lotgd\Output;
    use Lotgd\Tests\Stubs\DummySettings;
    use PHPUnit\Framework\TestCase;

    final class ErrorHandlerReentrancyTest extends TestCase
    {
        private $originalOutput;

        protected function setUp(): void
        {
            global $settings;

            $settings = new DummySettings([
                'notify_on_warn' => 0,
                'usedatacache' => 0,
            ]);

            $this->originalOutput = Output::getInstance();
            $mock = new class extends Output {
                public function debug($text, $force = false)
                {
                    parent::debug($text, $force);
                    ErrorHandler::handleError(E_WARNING, 'Second warning', __FILE__, __LINE__);
                }
            };
            $ref = new \ReflectionProperty(Output::class, 'instance');
            $ref->setAccessible(true);
            $ref->setValue(null, $mock);
        }

        protected function tearDown(): void
        {
            $ref = new \ReflectionProperty(Output::class, 'instance');
            $ref->setAccessible(true);
            $ref->setValue(null, $this->originalOutput);
            unset($GLOBALS['settings']);
        }

        public function testSecondErrorProducesSimplifiedMessageAndExecutionContinues(): void
        {
            $executionContinues = false;

            ob_start();
            ErrorHandler::handleError(E_WARNING, 'Initial warning', __FILE__, __LINE__);
            $output = ob_get_clean();
            $executionContinues = true;

            $this->assertStringContainsString('Second warning', $output);
            $this->assertStringContainsString(
                'Additionally this occurred while within logd_error_handler()',
                $output
            );
            $this->assertTrue($executionContinues);
        }
    }
}
