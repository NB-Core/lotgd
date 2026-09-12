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

    /**
     * A finding is reported on the line the interpolated value sits on, not on
     * the line the string opened.
     *
     * This is not cosmetic. --changed-since is what runs in CI, and it keeps
     * only findings whose line the diff marked as added. Most SQL in this
     * codebase is written as a multi-line executeStatement() call, so an
     * interpolation reintroduced into the query argument leaves the opening
     * line untouched -- and a finding attributed to that opening line was
     * silently filtered out as "not added by this change".
     *
     * Measured when this was found: 42 of the tree's 120 findings pointed at
     * the wrong line, every one of them a case CI would have waved through.
     */
    public function testAFindingIsReportedOnTheLineOfTheInterpolatedValue(): void
    {
        $violations = $this->analyse(
            '$rows = $conn->executeStatement(' . "\n"
            . '    "DELETE FROM {$table} WHERE id = \'$id\'"' . "\n"
            . ');'
        );

        self::assertCount(1, $violations);
        self::assertSame(
            3,
            $violations[0]['line'],
            'line 2 opens the call, line 3 carries the query -- and line 3 is what the diff marks as added'
        );
    }

    /**
     * The same for a curly-brace interpolation, which the reader handles on a
     * different branch.
     */
    public function testACurlyInterpolationIsReportedOnItsOwnLineToo(): void
    {
        $violations = $this->analyse(
            '$rows = $conn->executeStatement(' . "\n"
            . '    "UPDATE t SET a = 1' . "\n"
            . '     WHERE id = \'{$row[\'id\']}\'"' . "\n"
            . ');'
        );

        self::assertCount(1, $violations);
        self::assertSame(4, $violations[0]['line'], 'the third line of the statement');
    }

    /**
     * A single-line statement is unaffected, which is the control: the fix must
     * not shift findings that were already right.
     */
    public function testASingleLineStatementKeepsItsLine(): void
    {
        $violations = $this->analyse('$sql = "SELECT * FROM t WHERE id = \'$id\'";');

        self::assertCount(1, $violations, 'the control must produce exactly one finding to be a control at all');
        self::assertSame(2, $violations[0]['line']);
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
     * A named test file is skipped; production PHP anywhere is not.
     *
     * --changed-since hands this method whatever .php files the diff touched.
     * It used to accept all of them, so this checker's own fixtures -- which
     * hold the forbidden shapes on purpose -- became CI failures the moment
     * the concatenation rule could see them.
     *
     * The first fix inverted the question and kept only the audit roots,
     * which silently dropped 77 production files: install/, modules/,
     * migrations/ and scripts/ are all outside them, and
     * install/lib/Installer.php builds a query from a posted username.
     * Codex caught that on #1534. Narrowing CI to match the audit was the
     * wrong direction; the audit was widened to match CI instead, and only
     * fixtures are skipped.
     */
    public function testTestFixturesAreSkippedButProductionCodeAnywhereIsNot(): void
    {
        $root = $this->createFixtureRoot();
        mkdir($root . '/tests/QA', 0777, true);
        $body = '$sql = "SELECT * FROM t WHERE a = \'" . Http::get(\'a\') . "\'";';
        file_put_contents($root . '/tests/QA/FixtureTest.php', "<?php\n" . $body . "\n");
        file_put_contents($root . '/pages/probe.php', "<?php\n" . $body . "\n");

        $checker = new SqlValueInterpolationCheck();

        self::assertSame(
            [],
            $checker->collectViolations($root, ['tests/QA/FixtureTest.php']),
            'a fixture holds the forbidden shapes on purpose'
        );
        self::assertCount(
            1,
            $checker->collectViolations($root, ['pages/probe.php']),
            'the identical body under a scanned root is still reported'
        );

        mkdir($root . '/install/lib', 0777, true);
        file_put_contents($root . '/install/lib/Installer.php', "<?php\n" . $body . "\n");
        self::assertCount(
            1,
            $checker->collectViolations($root, ['install/lib/Installer.php']),
            'production PHP outside the historical audit roots is production PHP'
        );
    }

    /**
     * A request value joined into a query with `.` instead of interpolated.
     *
     * The reader that finds interpolations walks the tokens *inside* a string
     * literal, so a value concatenated around one is a different token
     * entirely and was invisible to it. The gap turned up while auditing the
     * tests that had been guarding this exact shape by searching login.php
     * and superuser.php for its spelling.
     *
     * @param non-empty-string $body
     */
    #[DataProvider('provideFlaggedConcatenations')]
    public function testFlagsRequestValuesConcatenatedIntoAQuery(string $body): void
    {
        $violations = $this->analyse($body);

        self::assertCount(1, $violations, 'expected exactly one finding for: ' . $body);
        self::assertSame('pages/probe.php', $violations[0]['file']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideFlaggedConcatenations(): array
    {
        return [
            'the shape a grep test used to guard' => [
                '$sql = "DELETE FROM news WHERE newsid=\'" . Http::get(\'newsid\') . "\'";',
            ],
            'straight from the superglobal' => [
                '$rows = $conn->executeQuery("SELECT * FROM t WHERE name = \'" . $_GET[\'n\'] . "\'");',
            ],
            'through the legacy wrapper, which escapes but does not bind' => [
                '$sql = "SELECT * FROM t WHERE name = \'" . httpget(\'n\') . "\'";',
            ],
            'a posted value in an IN list' => [
                '$sql = "SELECT * FROM t WHERE id IN (" . Http::post(\'ids\') . ")";',
            ],
            // Tokens do not care about lines, so the fragments are the same
            // whether the statement is written on one line or five.
            'spread over several lines' => [
                "\$sql = \"UPDATE accounts\n    SET name = '\"\n    . Http::post('name')\n    . \"'\n    WHERE acctid = 1\";",
            ],

            // Not in value position, and reported anyway. The interpolation
            // pass passes over identifiers because interpolating a table name
            // is normal; a value read straight from the request is not, in
            // any position. Sorting and column lists are where this shape
            // actually shows up.
            'an ORDER BY taken from the request' => [
                '$sql = "SELECT * FROM t ORDER BY " . Http::get(\'sort\');',
            ],
            'a column list taken from the request' => [
                '$sql = "SELECT " . Http::get(\'cols\') . " FROM accounts WHERE acctid = 1";',
            ],
            'a table name taken from the request' => [
                '$sql = "SELECT * FROM " . Http::get(\'t\') . " WHERE acctid = 1";',
            ],

            // The keyword sits in an earlier fragment than the one the slot
            // touches, so finding it needs the whole statement rather than
            // the literals next to the slot.
            'the keyword is in an earlier fragment' => [
                '$sql = "SELECT name FROM accounts WHERE login = " . "\'" . Http::get(\'n\') . "\'";',
            ],
        ];
    }

    /**
     * Both request values in one statement are reported, not just the first.
     *
     * The second slot's neighbouring fragments are "' AND b = '" and "'",
     * neither of which carries a keyword, so a rule that assembles only the
     * literals adjacent to the slot sees no query there and passes it over.
     * That is what the first version of this did, and it took a mutation
     * that refused to die to show it: disabling the outward walk changed no
     * result, because nothing exercised the case it existed for.
     */
    public function testEveryRequestValueInOneStatementIsReported(): void
    {
        $violations = $this->analyse(
            '$sql = "SELECT * FROM t WHERE a = \'" . Http::get(\'a\')' . "\n"
            . '    . "\' AND b = \'" . Http::get(\'b\') . "\'";'
        );

        self::assertCount(2, $violations, 'the second injected value is not a free pass');
        self::assertSame("Http::get('a')", $violations[0]['expression']);
        self::assertSame("Http::get('b')", $violations[1]['expression']);
    }

    /**
     * Shapes the concatenation rule must stay silent on.
     *
     * The first row is not hypothetical: it is the only candidate the rule
     * found in the whole tree when it was first measured, and reporting it
     * would have been a false positive in `composer static`. A URL query
     * string puts an `=` before its slot exactly as a WHERE clause does,
     * which is why the assembled text has to be put through looksLikeSql()
     * rather than the surrounding file.
     *
     * @param non-empty-string $body
     */
    #[DataProvider('provideUnflaggedConcatenations')]
    public function testIgnoresConcatenationsThatDoNotBuildAQuery(string $body): void
    {
        self::assertSame([], $this->analyse($body), 'not a query: ' . $body);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideUnflaggedConcatenations(): array
    {
        return [
            'a URL query string, the tree\'s only candidate' => [
                'Nav::add("viewpetition.php?page=" . Http::get(\'page\'));',
            ],
            'a URL in a file that also contains SQL' => [
                '$sql = "SELECT * FROM t WHERE a = 1";' . "\n"
                . 'Nav::add("user.php?op=" . Http::get(\'op\'));',
            ],
            'an integer cast is a complete defence' => [
                '$sql = "SELECT * FROM t WHERE id = " . (int) Http::get(\'id\');',
            ],
            'markup that happens to contain a keyword' => [
                '$html = "<select name=\'" . Http::get(\'f\') . "\'>";',
            ],
            'a value that is bound rather than concatenated' => [
                '$conn->executeQuery("SELECT * FROM t WHERE id = :id", [\'id\' => Http::get(\'id\')]);',
            ],

            // Prose, and mine to answer for. The concatenation pass drops the
            // value-position test that keeps these out of the interpolation
            // pass, so it has to require a whole statement instead of a
            // keyword -- "set" and "values" are ordinary English.
            'a sentence containing set' => [
                '$msg = "Your password is set to \'" . Http::get(\'p\') . "\'";',
            ],
            'a sentence containing values' => [
                '$msg = "Choose between the values \'" . Http::get(\'v\') . "\' and none";',
            ],
            'a sentence containing where' => [
                '$msg = "We could not find where \'" . Http::get(\'n\') . "\' went";',
            ],
        ];
    }

    /**
     * A query keeps its markup and is still recognised.
     *
     * looksLikeSql() vetoed any text containing a tag, so an INSERT that
     * stores formatted content hid a concatenated request value behind its
     * own <p>. Reported by Codex on #1534; the veto is for a bare keyword
     * inside markup -- "<select name=…>" -- and a whole statement no longer
     * needs it.
     */
    public function testAQueryThatStoresMarkupIsStillSeen(): void
    {
        $violations = $this->analyse(
            '$sql = "INSERT INTO comments (body) VALUES (\'<p>" . Http::post(\'body\') . "</p>\')";'
        );

        self::assertCount(1, $violations);
        self::assertSame("Http::post('body')", $violations[0]['expression']);
    }

    /**
     * The same for an interpolated value, which had the identical blind spot.
     */
    public function testAnInterpolatedValueInsideAMarkupBearingQueryIsSeen(): void
    {
        self::assertCount(
            1,
            $this->analyse('$sql = "INSERT INTO comments (body) VALUES (\'<p>$body</p>\')";')
        );
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
