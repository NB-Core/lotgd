<?php

declare(strict_types=1);

namespace Lotgd\Installer {
    function is_writable(string $path): bool
    {
        return false;
    }
}

namespace Lotgd\Tests {

    use Lotgd\Installer\InstallerLogger;
    use PHPUnit\Framework\TestCase;

/**
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
    final class InstallerLoggerTest extends TestCase
    {
        public function testLogReturnsFalseWithoutWarningsWhenDirectoryIsNotWritable(): void
        {
            $warnings = [];
            set_error_handler(function (int $severity, string $message) use (&$warnings) {
                $warnings[] = $message;
                return true;
            });

            $result = InstallerLogger::log('test message');

            restore_error_handler();

            $this->assertFalse($result);
            $this->assertSame([], $warnings);
        }
    }

}
