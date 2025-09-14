<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\ErrorHandling;
use PHPUnit\Framework\TestCase;

final class ErrorHandlingTest extends TestCase
{
    public function testConfigureSetsErrorReporting(): void
    {
        $original = error_reporting();
        error_reporting(0);
        ErrorHandling::configure();
        $this->assertSame(E_ALL ^ E_NOTICE, error_reporting());
        error_reporting($original);
    }
}

