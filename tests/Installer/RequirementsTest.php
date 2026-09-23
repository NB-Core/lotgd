<?php

declare(strict_types=1);

namespace Lotgd\Tests\Installer;

use Lotgd\Installer\Requirements;
use PHPUnit\Framework\TestCase;

/**
 * The installer's first screen. A server that fails here must be told which
 * requirement is missing in words a hosting panel uses, because the
 * alternative is a Doctrine "could not find driver" several stages later.
 */
final class RequirementsTest extends TestCase
{
    public function testSupportedServerHasNothingUnmet(): void
    {
        self::assertSame([], Requirements::unmet('8.3.0', static fn (string $extension): bool => true));
        self::assertSame([], Requirements::unmet('8.4.12', static fn (string $extension): bool => true));
    }

    public function testOldPhpIsReportedWithBothVersions(): void
    {
        $unmet = Requirements::unmet('8.2.29', static fn (string $extension): bool => true);

        self::assertCount(1, $unmet);
        self::assertStringContainsString('PHP 8.3.0 or higher', $unmet[0]);
        self::assertStringContainsString('8.2.29', $unmet[0]);
    }

    public function testMissingPdoMysqlIsReportedByName(): void
    {
        $unmet = Requirements::unmet('8.4.0', static fn (string $extension): bool => $extension !== 'pdo_mysql');

        self::assertCount(1, $unmet);
        self::assertStringContainsString('"pdo_mysql"', $unmet[0]);
    }

    public function testMissingMysqliIsReportedByName(): void
    {
        $unmet = Requirements::unmet('8.4.0', static fn (string $extension): bool => $extension !== 'mysqli');

        self::assertCount(1, $unmet);
        self::assertStringContainsString('"mysqli"', $unmet[0]);
    }

    public function testEveryUnmetRequirementIsReportedAtOnce(): void
    {
        // One round trip to the hosting panel instead of one per requirement.
        $unmet = Requirements::unmet('7.4.33', static fn (string $extension): bool => false);

        self::assertCount(1 + count(Requirements::REQUIRED_EXTENSIONS), $unmet);
    }

    public function testDefaultsDescribeTheRunningServer(): void
    {
        $expected = [];
        foreach (array_keys(Requirements::REQUIRED_EXTENSIONS) as $extension) {
            if (!extension_loaded($extension)) {
                $expected[] = $extension;
            }
        }

        self::assertCount(count($expected), Requirements::unmet());
    }
}
