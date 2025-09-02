<?php

declare(strict_types=1);

namespace Lotgd\Tests {
    use Lotgd\ErrorHandler;
    use Lotgd\Tests\Stubs\DummySettings;
    use PHPUnit\Framework\TestCase;

    final class ErrorHandlerReentrancyTest extends TestCase
    {
        protected function setUp(): void
        {
            global $settings, $reentrant_debug;

            $settings = new DummySettings([
                'notify_on_warn' => 0,
                'usedatacache' => 0,
            ]);
            $reentrant_debug = true;
        }

        protected function tearDown(): void
        {
            global $reentrant_debug;
            $reentrant_debug = false;
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

namespace Lotgd {
    function debug($t, $force = false): void
    {
        if (!empty($GLOBALS['reentrant_debug'])) {
            ErrorHandler::handleError(E_WARNING, 'Second warning', __FILE__, __LINE__);
        } else {
            \debug($t, $force);
        }
    }
}
