<?php

declare(strict_types=1);

namespace Lotgd\Tests\QA;

use Lotgd\QA\LegacyHttpWrapperUsageCheck;
use PHPUnit\Framework\TestCase;

final class LegacyHttpWrapperUsageCheckTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $fixtureRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtureRoots as $root) {
            $this->removeDirectoryRecursively($root);
        }
        $this->fixtureRoots = [];
    }

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
        mkdir($root . '/src/Lotgd/QA', 0777, true);
        file_put_contents($root . '/src/Lotgd/QA/LegacyHttpWrapperUsageCheck.php', "<?php\n\$a = httpget('foo');\n");

        $checker = new LegacyHttpWrapperUsageCheck();
        $violations = $checker->collectViolations($root);

        $this->assertSame([], $violations);
    }

    public function testCheckerIgnoresMentionsInsideCommentsAndStrings(): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents(
            $root . '/pages/docs-example.php',
            <<<'PHP'
<?php
/**
 * Example docs mentioning httpget('foo') for migration notes.
 */
$message = "Use httppost('bar') in legacy wrappers only.";
PHP
        );

        $checker = new LegacyHttpWrapperUsageCheck();
        $violations = $checker->collectViolations($root);

        $this->assertSame([], $violations);
    }

    private function createFixtureRoot(): string
    {
        $root = sys_get_temp_dir() . '/lotgd-http-check-' . uniqid('', true);
        mkdir($root . '/pages', 0777, true);
        mkdir($root . '/src', 0777, true);
        $this->fixtureRoots[] = $root;

        return $root;
    }

    private function removeDirectoryRecursively(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $current = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($current)) {
                $this->removeDirectoryRecursively($current);
                continue;
            }

            @unlink($current);
        }

        @rmdir($path);
    }
}
