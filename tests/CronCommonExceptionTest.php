<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

final class CronCommonExceptionTest extends TestCase
{
    public function testExceptionInCommonIsLogged(): void
    {
        $logFile = __DIR__ . '/../logs/bootstrap.log';

        if (file_exists($logFile)) {
            unlink($logFile);
        }

        shell_exec('php ' . escapeshellarg(__DIR__ . '/cron_common_exception.php'));

        $this->assertFileExists($logFile);
        $log = (string) file_get_contents($logFile);
        $this->assertStringContainsString('Cron common.php failure', $log);

        unlink($logFile);
    }
}
