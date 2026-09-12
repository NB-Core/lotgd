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
        self::assertStringContainsString('src/example.php:4:', $violations[0]);
    }

    /**
     * A statement whose opening quote sits on an earlier line than the escaped
     * value, which is how most multi-line SQL in this codebase is written.
     *
     * The line-based version of this rule could not see it: only the physical
     * line carrying addslashes() was inspected, and the SELECT is above it. It
     * is the shape the gap was supposed to be closed for, so it is asserted
     * rather than assumed.
     */
    public function testCheckerFlagsAReturnedStatementSpanningLines(): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents(
            $root . '/pages/example.php',
            "<?php
function build(\$n)
{
    return \"SELECT * FROM t
            WHERE n = '\" . addslashes(\$n) . \"'\";
}
"
        );

        $violations = (new SqlAddslashesUsageCheck())->collectViolations($root);

        self::assertCount(1, $violations);
        self::assertStringContainsString('pages/example.php:5:', $violations[0], 'reported where the escaping happens');
    }

    /**
     * A statement that does not open with one of the five verbs is still caught
     * where a sink is in reach.
     *
     * Reported by Codex on #1532: the first version of this change replaced the
     * keyword test in the sink branch instead of adding beside it, so a common
     * table expression handed straight to Database::query() matched neither
     * branch. That was a regression in an existing guard, and this case is
     * where it would show again.
     */
    public function testCheckerFlagsAStatementThatDoesNotOpenWithAVerbAtASink(): void
    {
        $root = $this->createFixtureRoot();
        file_put_contents(
            $root . '/pages/example.php',
            "<?php
\Lotgd\MySQL\Database::query(\"WITH rows AS (SELECT 1) SELECT * FROM rows WHERE n = '\" . addslashes(\$n) . \"'\");
"
        );

        self::assertCount(1, (new SqlAddslashesUsageCheck())->collectViolations($root));
    }

    /**
     * Prose is not a query, and this is where the sink-free rule has to earn
     * its keep.
     *
     * Each row contains a SQL keyword; none builds a query. Two earlier
     * versions of the rule got these wrong in different ways. The first asked
     * only whether a keyword appeared anywhere on the line and reported the
     * first two. The second asked whether any quote on the line was followed by
     * a keyword, and reported the third: the apostrophes around the quoted word
     * looked like string delimiters. Reading the string as a token settles it,
     * because those apostrophes are content.
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
            'a sentence that quotes a keyword' => [
                '$msg = "Choose \'delete\' to remove " . addslashes($name);',
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
