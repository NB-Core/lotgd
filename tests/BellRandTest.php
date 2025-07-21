<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Lotgd\BellRand;

require_once __DIR__ . '/../config/constants.php';

final class BellRandTest extends TestCase
{
    public function testDefaultRange(): void
    {
        $val = BellRand::generate();
        $this->assertGreaterThanOrEqual(0, $val);
        $this->assertLessThanOrEqual(1, $val);
    }

    public function testMinMaxRange(): void
    {
        $val = BellRand::generate(5, 10);
        $this->assertGreaterThanOrEqual(5, $val);
        $this->assertLessThanOrEqual(10, $val);
    }

    public function testSwappedValues(): void
    {
        $val = BellRand::generate(10, 5);
        $this->assertGreaterThanOrEqual(5, $val);
        $this->assertLessThanOrEqual(10, $val);
    }

    public function testEqualValuesReturnsValue(): void
    {
        $this->assertSame(3, BellRand::generate(3, 3));
    }
}
