<?php

declare(strict_types=1);

namespace Lotgd\Tests\QA;

use Lotgd\QA\SqlValueInterpolationCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlValueInterpolationCheckTest extends TestCase
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
        parent::tearDown();
    }

    /**
     * @param non-empty-string $body PHP body written into the fixture file.
     */
    #[DataProvider('provideFlaggedSnippets')]
    public function testFlagsValueInterpolation(string $body): void
    {
        $violations = $this->analyse($body);

        self::assertCount(1, $violations, 'expected exactly one finding for: ' . $body);
        self::assertSame('pages/probe.php', $violations[0]['file']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideFlaggedSnippets(): array
    {
        return [
            'quoted value' => ['$sql = "SELECT * FROM t WHERE name=\'$name\'";'],
            'bare value' => ['$sql = "SELECT * FROM t WHERE id=$id";'],
            'braced value' => ['$sql = "DELETE FROM t WHERE id={$row[\'id\']}";'],
            'update set' => ['$sql = "UPDATE t SET name=\'$name\' WHERE id=1";'],
            // A string cast changes the type and nothing about the content.
            // This is the exact shape that was exploited in motd.php.
            'string cast' => ['$name = (string) $raw; $sql = "SELECT * FROM t WHERE name=\'$name\'";'],
            'limit clause' => ['$sql = "SELECT * FROM t ORDER BY id LIMIT $start";'],
            'in list' => ['$sql = "SELECT * FROM t WHERE id IN (\'$id\')";'],
        ];
    }

    /**
     * @param non-empty-string $body
     */
    #[DataProvider('provideAcceptedSnippets')]
    public function testAcceptsSafeConstructs(string $body): void
    {
        self::assertSame([], $this->analyse($body), 'unexpected finding for: ' . $body);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideAcceptedSnippets(): array
    {
        return [
            'bound parameter' => ['$sql = "SELECT * FROM t WHERE id = :id";'],
            // Identifiers cannot be bound, so interpolating them is normal.
            'table after FROM' => ['$sql = "SELECT * FROM {$table} WHERE id = :id";'],
            'table after UPDATE' => ['$sql = "UPDATE {$table} SET name = :name WHERE id = :id";'],
            'table after INSERT INTO' => ['$sql = "INSERT INTO {$table} (a) VALUES (:a)";'],
            'inline int cast' => ['$sql = "SELECT * FROM t WHERE id=" . (int) $id;'],
            'cast at assignment' => ['$id = (int) $raw; $sql = "SELECT * FROM t WHERE id=$id";'],
            'intval at assignment' => ['$id = intval($raw); $sql = "SELECT * FROM t WHERE id=$id";'],
            'integer literal assignment' => ['$id = 0; $sql = "SELECT * FROM t WHERE id=$id";'],
            'no interpolation' => ['$sql = "SELECT * FROM t WHERE id = 1";'],
            'markup is not sql' => ['$html = "<a href=\'x.php?id=$id\'>select</a>";'],
        ];
    }

    /**
     * A variable that is integer-cast in one place but assigned freely in
     * another must not be trusted: the last assignment wins at runtime, and
     * the check cannot know which one that is.
     */
    public function testMixedAssignmentsAreNotTrusted(): void
    {
        $violations = $this->analyse(
            '$id = (int) $raw; $id = $_GET["id"]; $sql = "SELECT * FROM t WHERE id=$id";'
        );

        self::assertCount(1, $violations);
    }

    public function testScanCanBeRestrictedToGivenFiles(): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents(
            $root . '/pages/probe.php',
            "<?php\n\$sql = \"SELECT * FROM t WHERE id='\$id'\";\n"
        );
        file_put_contents(
            $root . '/pages/other.php',
            "<?php\n\$sql = \"SELECT * FROM t WHERE id='\$other'\";\n"
        );

        $checker = new SqlValueInterpolationCheck();

        self::assertCount(2, $checker->collectViolations($root));
        self::assertCount(1, $checker->collectViolations($root, ['pages/probe.php']));
        self::assertSame([], $checker->collectViolations($root, ['pages/missing.php']));
    }

    /**
     * @return list<array{file: string, line: int, expression: string, context: string}>
     */
    private function analyse(string $body): array
    {
        $root = $this->createFixtureRoot();
        file_put_contents($root . '/pages/probe.php', "<?php\n" . $body . "\n");

        return (new SqlValueInterpolationCheck())->collectViolations($root, ['pages/probe.php']);
    }

    private function createFixtureRoot(): string
    {
        $root = sys_get_temp_dir() . '/lotgd-sql-interpolation-' . bin2hex(random_bytes(6));
        mkdir($root . '/pages', 0777, true);
        $this->fixtureRoots[] = $root;

        return $root;
    }

    private function removeDirectoryRecursively(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($path);
    }
}
