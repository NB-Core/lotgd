<?php

declare(strict_types=1);

namespace Lotgd\Tests\QA;

use Lotgd\QA\InterpolatedDatabaseQueryCheck;
use PHPUnit\Framework\TestCase;

final class InterpolatedDatabaseQueryCheckTest extends TestCase
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

    public function testCheckerFlagsDynamicDatabaseQueryUsage(): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents(
            $root . '/src/Lotgd/Security/example.php',
            "<?php\nuse Lotgd\\MySQL\\Database;\nDatabase::query(\$sql);\n"
        );

        $checker = new InterpolatedDatabaseQueryCheck();
        $violations = $checker->collectViolations($root);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('src/Lotgd/Security/example.php:3:', $violations[0]);
    }

    public function testCheckerIgnoresSelfWhitelistedPath(): void
    {
        $root = $this->createFixtureRoot();
        mkdir($root . '/src/Lotgd/QA', 0777, true);
        file_put_contents(
            $root . '/src/Lotgd/QA/InterpolatedDatabaseQueryCheck.php',
            "<?php\nDatabase::query(\$sql);\n"
        );

        $checker = new InterpolatedDatabaseQueryCheck();
        $violations = $checker->collectViolations($root);

        $this->assertSame([], $violations);
    }

    public function testCheckerAllowsLiteralDatabaseQuery(): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents(
            $root . '/src/Lotgd/Security/literal.php',
            "<?php\nDatabase::query('SELECT 1');\n"
        );

        $checker = new InterpolatedDatabaseQueryCheck();
        $violations = $checker->collectViolations($root);

        $this->assertSame([], $violations);
    }

    private function createFixtureRoot(): string
    {
        $root = sys_get_temp_dir() . '/lotgd-interpolated-query-check-' . uniqid('', true);
        mkdir($root . '/src/Lotgd/Security', 0777, true);
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
