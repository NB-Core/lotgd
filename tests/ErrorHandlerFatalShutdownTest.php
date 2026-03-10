<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ErrorHandler;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerFatalShutdownTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testFatalShutdownOutputsError(): void
    {
        eval(<<<'PHP'
namespace Lotgd;
function error_get_last(): ?array
{
    static $called = false;
    if ($called) {
        return null;
    }
    $called = true;
    return [
        'type' => E_ERROR,
        'message' => 'Fatal example',
        'file' => 'fatal.php',
        'line' => 13,
    ];
}
PHP);

        ob_start();
        ErrorHandler::fatalShutdown();
        $output = ob_get_clean();

        $this->assertStringContainsString('An unexpected error occurred. Please try again later.', $output);
        $this->assertStringNotContainsString('Fatal example', $output);
        $this->assertStringNotContainsString('fatal.php', $output);
    }
}
