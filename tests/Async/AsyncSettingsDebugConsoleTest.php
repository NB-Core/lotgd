<?php

declare(strict_types=1);

namespace Lotgd\Tests\Async {

    use Lotgd\Async\DebugMode;
    use Lotgd\Async\Handler\Timeout;
    use PHPUnit\Framework\TestCase;

    /**
     * `debug_console` must switch verbose logging only. Its predecessor `mail_debug`
     * overrode the poll interval with 500 seconds, which throttled polling to once every
     * eight minutes and read like a broken async layer.
     *
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     */
    #[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    final class AsyncSettingsDebugConsoleTest extends TestCase
    {
        private string $customFile;

        protected function setUp(): void
        {
            require_once __DIR__ . '/../bootstrap.php';
            $this->customFile = dirname(__DIR__, 2) . '/config/async.settings.php';
            if (file_exists($this->customFile)) {
                $this->markTestSkipped('A local config/async.settings.php would shadow the fixture.');
            }
        }

        protected function tearDown(): void
        {
            if (file_exists($this->customFile)) {
                unlink($this->customFile);
            }
            DebugMode::setEnabled(false);
        }

        /**
         * @param array<string, mixed> $settings
         */
        private function loadSettings(array $settings): void
        {
            file_put_contents($this->customFile, '<?php return ' . var_export($settings, true) . ';');
            require dirname(__DIR__, 2) . '/async/common/settings.php';
        }

        public function testDebugConsoleEnablesVerboseLogging(): void
        {
            $this->loadSettings(['debug_console' => 1, 'check_mail_timeout_seconds' => 10]);

            $this->assertTrue(DebugMode::isEnabled());
        }

        public function testDebugConsoleDefaultsToOff(): void
        {
            $this->loadSettings(['check_mail_timeout_seconds' => 10]);

            $this->assertFalse(DebugMode::isEnabled());
        }

        public function testDebugConsoleLeavesThePollIntervalUntouched(): void
        {
            $this->loadSettings(['debug_console' => 1, 'check_mail_timeout_seconds' => 10]);

            $this->assertSame(10, Timeout::getInstance()->getCheckMailTimeoutSeconds());
        }

        public function testDebugConsoleLeavesTheTimeoutDisplayUntouched(): void
        {
            $this->loadSettings(['debug_console' => 1, 'start_timeout_show_seconds' => 300]);

            $this->assertSame(300, Timeout::getInstance()->getStartTimeoutShowSeconds());
        }

        /**
         * Existing installations still carry the old key in their configuration file.
         */
        public function testLegacyMailDebugKeyStillEnablesVerboseLogging(): void
        {
            $this->loadSettings(['mail_debug' => 1, 'check_mail_timeout_seconds' => 10]);

            $this->assertTrue(DebugMode::isEnabled());
        }

        public function testLegacyMailDebugNoLongerOverridesThePollInterval(): void
        {
            $this->loadSettings(['mail_debug' => 1, 'check_mail_timeout_seconds' => 10]);

            $this->assertSame(10, Timeout::getInstance()->getCheckMailTimeoutSeconds());
        }

        public function testDebugConsoleWinsOverTheLegacyKey(): void
        {
            $this->loadSettings(['debug_console' => 0, 'mail_debug' => 1]);

            $this->assertFalse(DebugMode::isEnabled());
        }

        public function testDistributedDefaultsShipWithDebugConsoleDisabled(): void
        {
            $defaults = require dirname(__DIR__, 2) . '/config/async.settings.php.dist';

            $this->assertArrayHasKey('debug_console', $defaults);
            $this->assertArrayNotHasKey('mail_debug', $defaults);
            $this->assertSame(0, $defaults['debug_console']);
        }
    }
}
