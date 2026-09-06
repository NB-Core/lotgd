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
        private string $fixtureFile = '';

        protected function setUp(): void
        {
            require_once __DIR__ . '/../bootstrap.php';

            /*
             * Never write to config/async.settings.php: it is gitignored, so a developer
             * running the suite locally would have their real configuration overwritten and
             * then deleted with no way to restore it. The fixture lives in a temp file that
             * async/common/settings.php is pointed at through LOTGD_ASYNC_SETTINGS_FILE.
             */
            $fixtureFile = tempnam(sys_get_temp_dir(), 'lotgd_async_settings_');
            if ($fixtureFile === false) {
                // Deliberately a failure rather than a skip: this whole test file exists
                // because a skip silently disabled it for everyone with a local config.
                // An unwritable temp directory is a broken environment, not an unsupported
                // one, and should be loud.
                self::fail('Could not create an async settings fixture in ' . sys_get_temp_dir() . '.');
            }

            // No '.php' suffix: require() does not care about the extension, and appending
            // one would leave the zero-byte file tempnam() itself creates lying around.
            $this->fixtureFile = $fixtureFile;

            if (!defined('LOTGD_ASYNC_SETTINGS_FILE')) {
                define('LOTGD_ASYNC_SETTINGS_FILE', $this->fixtureFile);
            }
        }

        protected function tearDown(): void
        {
            if ($this->fixtureFile !== '' && file_exists($this->fixtureFile)) {
                unlink($this->fixtureFile);
            }

            DebugMode::setEnabled(false);
        }

        /**
         * @param array<string, mixed> $settings
         */
        private function loadSettings(array $settings): void
        {
            file_put_contents(
                (string) LOTGD_ASYNC_SETTINGS_FILE,
                '<?php return ' . var_export($settings, true) . ';'
            );
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

        /**
         * Regression guard: an earlier revision of this test wrote the fixture to the real
         * config/async.settings.php and removed it in tearDown, which deleted a developer's
         * gitignored configuration on every suite run - including when the test skipped,
         * because PHPUnit still calls tearDown after a skip in setUp.
         */
        public function testTheRealConfigurationFileIsNeverTouched(): void
        {
            $realConfig = dirname(__DIR__, 2) . '/config/async.settings.php';
            $existedBefore = file_exists($realConfig);
            $contentBefore = $existedBefore ? file_get_contents($realConfig) : null;

            $this->loadSettings(['debug_console' => 1]);

            $this->assertSame($existedBefore, file_exists($realConfig));
            if ($existedBefore) {
                $this->assertSame($contentBefore, file_get_contents($realConfig));
            }
            $this->assertNotSame($realConfig, (string) LOTGD_ASYNC_SETTINGS_FILE);
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
