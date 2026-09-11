<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

final class ErrorHandlerErrorDisabledTest extends TestCase
{
    public function testErrorNotificationIsDisabled(): void
    {
        $script = __DIR__ . '/error_handler_error_disabled.php';

        // exec(), not shell_exec(): the exit status matters. A script that dies
        // before reaching its own output still produces a string that does not
        // contain the notification marker, so asserting on stdout alone cannot
        // tell "notification suppressed" from "script never ran".
        exec(sprintf('php %s 2>&1', escapeshellarg($script)), $out, $status);
        $output = implode("\n", $out);

        $this->assertSame(0, $status, "Script exited with {$status}:\n{$output}");
        $this->assertStringContainsString('mail_sent_count=0', $output);
    }
}
