<?php

declare(strict_types=1);

namespace Lotgd\Tests\QA;

use Lotgd\QA\SqlAddslashesUsageCheck;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testCheckerFlagsLegacyLikeLineWithoutBaselineByDefault(): void
    {
        $root = $this->createFixtureRoot();
        mkdir($root . '/src/Lotgd', 0777, true);
        file_put_contents(
            $root . '/src/Lotgd/PlayerFunctions.php',
            $this->buildLegacyPlayerFunctionsFixture(
                269,
                "\$sql = 'SELECT acctid,laston,loggedin FROM ' . Database::prefix('accounts') . ' WHERE acctid IN (' . addslashes(implode(',', \$players)) . ')';"
            )
        );

        $checker = new SqlAddslashesUsageCheck();
        $violations = $checker->collectViolations($root);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('src/Lotgd/PlayerFunctions.php:269:', $violations[0]);
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

    /**
     * A helper that builds the query and hands it back is a finding, even
     * though nothing on or near that line executes it.
     *
     * This shape escaped the checker entirely until now, because isSqlContext()
     * demanded a sink marker -- `$sql`, `Database::query`, `executeQuery(` or
     * `executeStatement(` -- within six lines above and twelve below. A helper
     * returns its query to a caller, often in another file, so no sink is in
     * reach.
     *
     * It is not a hypothetical shape. It is the one I wrote by accident while
     * probing this checker during the test audit, and watching it pass is what
     * turned the gap up.
     */
    public function testCheckerFlagsAHelperThatReturnsTheQueryItBuilt(): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents(
            $root . '/pages/example.php',
            "<?php\nfunction build(\$n)\n{\n    return \"SELECT * FROM t WHERE n = '\" . addslashes(\$n) . \"'\";\n}\n"
        );

        $violations = (new SqlAddslashesUsageCheck())->collectViolations($root);

        self::assertCount(1, $violations);
        self::assertStringContainsString('pages/example.php:4:', $violations[0]);
    }

    /**
     * The same for a statement that writes.
     */
    public function testCheckerFlagsAReturnedUpdateStatement(): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents(
            $root . '/src/example.php',
            "<?php\nfunction build(\$n)\n{\n    return \"UPDATE accounts SET name = '\" . addslashes(\$n) . \"' WHERE acctid = 1\";\n}\n"
        );

        $violations = (new SqlAddslashesUsageCheck())->collectViolations($root);

        self::assertCount(1, $violations);
    }

    /**
     * Prose is not a query, and this is where the sink-free rule has to earn
     * its keep.
     *
     * Both of these contain a SQL keyword; neither builds a query. An earlier
     * version of the rule asked only whether a keyword appeared anywhere on the
     * line and reported both -- two false positives out of four probe cases.
     * The rule now asks whether a quote is followed straight away by a
     * statement keyword, which the prose lines are not.
     *
     * @param non-empty-string $body
     */
    #[DataProvider('provideProseThatMentionsSql')]
    public function testCheckerIgnoresProseThatMerelyMentionsSql(string $body): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents($root . '/pages/example.php', "<?php\n" . $body . "\n");

        self::assertSame(
            [],
            (new SqlAddslashesUsageCheck())->collectViolations($root),
            'not a query: ' . $body
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideProseThatMentionsSql(): array
    {
        return [
            'a sentence containing select and from' => [
                '$msg = "Please select \'" . addslashes($name) . "\' from the list";',
            ],
            'a sentence containing set' => [
                '$msg = "Your password is set to \'" . addslashes($pw) . "\'";',
            ],
            'a sentence containing delete' => [
                '$msg = "Really delete \'" . addslashes($name) . "\' from your roster?";',
            ],
        ];
    }

    private function createFixtureRoot(): string
    {
        $root = sys_get_temp_dir() . '/lotgd-sql-addslashes-check-' . uniqid('', true);
        mkdir($root . '/pages', 0777, true);
        mkdir($root . '/src', 0777, true);
        $this->fixtureRoots[] = $root;

        return $root;
    }

    private function buildLegacyPlayerFunctionsFixture(int $lineNumber, string $line): string
    {
        $phpLines = ['<?php'];
        while (count($phpLines) < ($lineNumber - 1)) {
            $phpLines[] = '$placeholder = null;';
        }
        $phpLines[] = $line;

        return implode("\n", $phpLines) . "\n";
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
