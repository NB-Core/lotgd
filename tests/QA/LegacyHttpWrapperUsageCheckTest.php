<?php

declare(strict_types=1);

namespace Lotgd\Tests\QA;

use Lotgd\QA\LegacyHttpWrapperUsageCheck;
use PHPUnit\Framework\TestCase;

final class LegacyHttpWrapperUsageCheckTest extends TestCase
{
    public function testCheckerFlagsLegacyHttpWrappersInCorePaths(): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents($root . '/pages/example.php', "<?php\n\$a = httpget('foo');\n\$b = \\Lotgd\\Http::get('bar');\n");

        $checker = new LegacyHttpWrapperUsageCheck();
        $violations = $checker->collectViolations($root);

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('pages/example.php:2:', $violations[0]);
    }

    public function testCheckerAllowsLegacyHttpWrappersInWhitelistedLegacyPaths(): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents($root . '/modules/example.php', "<?php\n\$a = httpget('foo');\n");
        file_put_contents($root . '/lib/example.php', "<?php\n\$a = httppost('bar');\n");

        $checker = new LegacyHttpWrapperUsageCheck();
        $violations = $checker->collectViolations($root);

        $this->assertSame([], $violations);
    }

    private function createFixtureRoot(): string
    {
        $root = sys_get_temp_dir() . '/lotgd-http-check-' . uniqid('', true);
        mkdir($root . '/pages', 0777, true);
        mkdir($root . '/src', 0777, true);
        mkdir($root . '/lib', 0777, true);
        mkdir($root . '/modules', 0777, true);

        return $root;
    }
}

