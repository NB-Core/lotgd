<?php

declare(strict_types=1);

namespace Lotgd\Tests\QA;

use Lotgd\QA\SqlAddslashesUsageCheck;
use PHPUnit\Framework\TestCase;

final class SqlAddslashesUsageCheckTest extends TestCase
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

    public function testCheckerFlagsAddslashesInSqlBuildingContexts(): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents(
            $root . '/src/example.php',
            "<?php\n\$sql = \"UPDATE modules SET name='\" . addslashes(\$name) . \"'\";\n"
        );

        $checker = new SqlAddslashesUsageCheck();
        $violations = $checker->collectViolations($root);

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('src/example.php:2:', $violations[0]);
    }

    public function testCheckerIgnoresAddslashesOutsideSqlContexts(): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents(
            $root . '/pages/example.php',
            "<?php\n\$display = addslashes(\$value);\n\$safe = htmlspecialchars(\$display, ENT_QUOTES);\n"
        );

        $checker = new SqlAddslashesUsageCheck();
        $violations = $checker->collectViolations($root);

        $this->assertSame([], $violations);
    }

    public function testCheckerAllowsDocumentedLegacyBaselinePatterns(): void
    {
        $root = $this->createFixtureRoot();
        mkdir($root . '/src/Lotgd', 0777, true);
        file_put_contents(
            $root . '/src/Lotgd/PlayerFunctions.php',
            "<?php\n\$sql = 'SELECT acctid FROM accounts WHERE acctid IN (' . addslashes(implode(',', \$players)) . ')';\n"
        );

        $checker = new SqlAddslashesUsageCheck();
        $violations = $checker->collectViolations($root);

        $this->assertSame([], $violations);
    }

    public function testCheckerFlagsSplitSqlConstructionUsingEscapedTemporaryVariable(): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents(
            $root . '/pages/split-sql.php',
            <<<'PHP'
<?php
$escapedName = addslashes($name);
$audit = 'not sql';
$flag = true;
$sql = "UPDATE modules SET formalname='" . $escapedName . "'";
Database::query($sql);
PHP
        );

        $checker = new SqlAddslashesUsageCheck();
        $violations = $checker->collectViolations($root);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('pages/split-sql.php:2:', $violations[0]);
    }

    private function createFixtureRoot(): string
    {
        $root = sys_get_temp_dir() . '/lotgd-sql-addslashes-check-' . uniqid('', true);
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
