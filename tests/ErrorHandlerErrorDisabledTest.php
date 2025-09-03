<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

final class ErrorHandlerErrorDisabledTest extends TestCase
{
    public function testErrorNotificationIsDisabled(): void
    {
        $script = __DIR__ . '/error_handler_error_disabled.php';
        $cmd    = sprintf('php %s', escapeshellarg($script));
        $output = shell_exec($cmd);

        $this->assertIsString($output);
        $this->assertStringContainsString('mail_sent_count=0', $output);
    }
}
