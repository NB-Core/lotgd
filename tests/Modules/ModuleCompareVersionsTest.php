<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules;

use Lotgd\Modules;
use PHPUnit\Framework\TestCase;

final class ModuleCompareVersionsTest extends TestCase
{
    public function testReturnsNegativeWhenFirstVersionLower(): void
    {
        self::assertLessThan(0, Modules::compareVersions('1.2', '1.10'));
    }

    public function testReturnsPositiveWhenFirstVersionHigher(): void
    {
        self::assertGreaterThan(0, Modules::compareVersions('1.10', '1.2'));
    }

    public function testReturnsZeroWhenVersionsAreEqual(): void
    {
        self::assertSame(0, Modules::compareVersions('1.2', '1.2'));
    }
}
