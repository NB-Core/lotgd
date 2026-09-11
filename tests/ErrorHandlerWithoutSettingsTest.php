<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

final class ErrorHandlerWithoutSettingsTest extends TestCase
{
    public function testExceptionWithoutSettingsDoesNotFatal(): void
    {
        $script = __DIR__ . '/error_handler_without_settings.php';

        // Runs in a fresh process on purpose: the point is an exception raised
        // before any Settings instance exists, and nothing in this suite's
        // process can honestly claim that state. exec() so the exit status is
        // checked too -- see ErrorHandlerErrorDisabledTest.
        exec(sprintf('php %s 2>&1', escapeshellarg($script)), $out, $status);
        $output = implode("\n", $out);

        $this->assertSame(0, $status, "Script exited with {$status}:\n{$output}");
        $this->assertStringContainsString('done', $output);
        $this->assertStringNotContainsString('Fatal error', $output);
    }
}
