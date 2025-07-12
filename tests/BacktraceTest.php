<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Lotgd\Backtrace;

require_once __DIR__ . '/../config/constants.php';

final class BacktraceTest extends TestCase
{
    public function testShowNoBacktraceReturnsEmptyString(): void
    {
        $this->assertSame('', Backtrace::showNoBacktrace());
    }

    public function testGetTypeFormatsString(): void
    {
        $this->assertSame("<span class='string'>\"foo\"</span>", Backtrace::getType('foo'));
    }

    public function testGetTypeFormatsInteger(): void
    {
        $this->assertSame("<span class='number'>42</span>", Backtrace::getType(42));
    }

    public function testGetTypeFormatsFloat(): void
    {
        $this->assertSame("<span class='number'>3.142</span>", Backtrace::getType(3.14159));
    }

    public function testGetTypeFormatsBooleans(): void
    {
        $this->assertSame("<span class='bool'>true</span>", Backtrace::getType(true));
        $this->assertSame("<span class='bool'>false</span>", Backtrace::getType(false));
    }

    public function testGetTypeFormatsNull(): void
    {
        $this->assertSame("<span class='null'>NULL</span>", Backtrace::getType(null));
    }

    public function testGetTypeFormatsObject(): void
    {
        $obj = new stdClass();
        $this->assertSame("<span class='object'>stdClass</span>", Backtrace::getType($obj));
    }

    public function testGetTypeFormatsArray(): void
    {
        $expected = "<span class='array'>Array(<blockquote><span class='number'>0</span>=><span class='number'>1</span>, <span class='number'>1</span>=><span class='string'>\"foo\"</span></blockquote>)</span>";
        $this->assertSame($expected, Backtrace::getType([1, 'foo']));
    }
}
