<?php

declare(strict_types=1);

namespace {
    if (! function_exists('db_prefix')) {
        function db_prefix(string $name): string
        {
            return $name;
        }
    }
}

namespace Lotgd\Installer {

    if (! function_exists(__NAMESPACE__ . '\\header')) {
        function header(string $header, bool $replace = true, int $http_response_code = 0): void
        {
            $GLOBALS['installer_headers'][] = $header;
        }
    }
}

namespace Lotgd\Tests\Installer {

    use Lotgd\Installer\Installer;
    use Lotgd\Output;
    use Lotgd\Tests\Stubs\Database;
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     */
    final class Stage7Test extends TestCase
    {
        protected function setUp(): void
        {
            class_exists(Database::class);

            global $session, $output, $settings;

            $session  = [];
            $_SESSION = &$session;
            $output   = Output::getInstance();
            $settings = null;

            $_POST = [];
            $_SERVER['SCRIPT_NAME'] = 'test.php';

            header_remove();

            $GLOBALS['installer_headers'] = [];

            global $logd_version, $recommended_modules, $noinstallnavs, $stage, $DB_USEDATACACHE;

            $logd_version        = '0.0.0';
            $recommended_modules = [];
            $noinstallnavs       = [];
            $stage               = 7;
            $DB_USEDATACACHE     = false;
        }

        public function testStage7RendersConfirmationWhenNoSelectionMade(): void
        {
            $installer = new Installer();

            $installer->stage7();

            $this->assertSame(6, $_SESSION['stagecompleted']);

            $output = Output::getInstance()->getRawOutput();

            $this->assertStringContainsString('Confirmation', $output);
            $this->assertStringContainsString('Perform a clean install.', $output);
        }

        public function testStage7HandlesUpgradeSelectionFromPostData(): void
        {
            $_POST['type']    = 'upgrade';
            $_POST['version'] = '1.0.0';

            $installer = new Installer();

            $installer->stage7();

            $this->assertTrue($_SESSION['dbinfo']['upgrade']);
            $this->assertSame('1.0.0', $_SESSION['fromversion']);
            $this->assertSame(7, $_SESSION['stagecompleted']);

            $output = Output::getInstance()->getRawOutput();

            $this->assertStringNotContainsString('Confirmation', $output);
            $this->assertStringNotContainsString('Perform a clean install.', $output);
            $this->assertContains('Location: installer.php?stage=8', $this->getRedirectHeaders());
        }

        /**
         * @return list<string>
         */
        private function getRedirectHeaders(): array
        {
            $headers = $GLOBALS['installer_headers'] ?? [];

            return array_values(array_filter($headers, static fn (string $header): bool => str_starts_with($header, 'Location:')));
        }
    }
}
