<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

final class ErrorHandlerWithoutSettingsTest extends TestCase
{
    public function testExceptionWithoutSettingsDoesNotFatal(): void
    {
        $script = __DIR__ . '/error_handler_without_settings.php';
        $cmd    = sprintf('php %s', escapeshellarg($script));
        $output = shell_exec($cmd);

        $this->assertIsString($output);
        $this->assertStringContainsString('done', $output);
        $this->assertStringNotContainsString('Fatal error', $output);
    }
}
