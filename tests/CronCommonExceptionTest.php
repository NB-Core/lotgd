<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

final class CronCommonExceptionTest extends TestCase
{
    public function testExceptionInCommonTriggersEmail(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cron');
        shell_exec('php ' . escapeshellarg(__DIR__ . '/cron_common_exception.php') . ' ' . escapeshellarg($file));
        $this->assertSame('1', trim((string) file_get_contents($file)));
    }
}

