<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Backtrace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BacktraceTest extends TestCase
{
    public function testShowNoBacktraceReturnsEmptyString(): void
    {
        $this->assertSame('', Backtrace::showNoBacktrace());
    }

    /**
     * @dataProvider getTypeDataProvider
     */
    #[DataProvider('getTypeDataProvider')]
    public function testGetType($input, string $expected): void
    {
        $this->assertSame($expected, Backtrace::getType($input));
    }

    public static function getTypeDataProvider(): array
    {
        return [
            'string' => ['foo', "<span class='string'>\"foo\"</span>"],
            'integer' => [42, "<span class='number'>42</span>"],
            'float' => [3.14159, "<span class='number'>3.142</span>"],
            'boolean true' => [true, "<span class='bool'>true</span>"],
            'boolean false' => [false, "<span class='bool'>false</span>"],
            'null' => [null, "<span class='null'>NULL</span>"],
            'object' => [new \stdClass(), "<span class='object'>stdClass</span>"],
            'array' => [
                [1, 'foo'],
                "<span class='array'>Array(<blockquote><span class='number'>0</span>=><span class='number'>1</span>, <span class='number'>1</span>=><span class='string'>\"foo\"</span></blockquote>)</span>"
            ],
        ];
    }
}
