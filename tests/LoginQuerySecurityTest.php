<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use PHPUnit\Framework\TestCase;

/**
 * What is left of the login page's guards once each one is asked where it lives.
 *
 * This class used to hold seventeen assertions, every one of them a substring
 * search over login.php: the exact spelling of four queries, the exact text of
 * two catch clauses, the shape of an if statement, and -- in one case -- the
 * presence of a code comment. A test like that fails when the code is
 * reformatted and passes when the code is wrong, which is the wrong way round.
 *
 * Each was rehomed rather than dropped on sight:
 *
 *   - The two that asserted no interpolated value reaches the login query
 *     ("WHERE login = '$name'", "password='$password'") are enforced for the
 *     whole tree by scripts/check-sql-value-interpolation.php, which CI runs
 *     against changed lines. Measured rather than assumed: reintroducing
 *     WHERE login = '$name' into a copy of login.php makes the full scan
 *     report it, so the root-level pages really are covered.
 *   - "if ($c >= 10)" guarded the automatic ban and said nothing about what
 *     the counter counts. That rule is Lotgd\Security\LoginFailureTally now,
 *     with the boundary asserted from both sides and the doubled weight for a
 *     superuser failure asserted at all, which the string never was. What that
 *     string did do, weakly, was witness that the page still consults the
 *     rule; the structural check below replaces that properly.
 *   - "PasswordHelper::verify(" and "hash_equals(" name behaviour that
 *     tests/PasswordHelperTest.php already exercises by running it.
 *   - The query spellings, the catch clauses and the comment guarded nothing
 *     a reformat would not also break.
 *
 * The two below are the ones with no better home, and they are written to
 * check a relationship rather than a spelling wherever that is possible.
 */
final class LoginQuerySecurityTest extends TestCase
{
    private function readLoginScript(): string
    {
        $path = dirname(__DIR__) . '/login.php';

        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }

    /**
     * Every column the faillog INSERT names exists in the schema.
     *
     * This one is worth keeping because it has failed in production before:
     * the column is `id`, and an older revision of the page wrote `lgi`. The
     * INSERT is inside a try/catch whose whole point is that logging must not
     * break the login flow, so a wrong column name does not surface as an
     * error -- it surfaces as failed logins silently going unrecorded, and
     * with them the automatic ban that reads them back.
     *
     * Checked against install/data/tables.php by running it rather than by
     * pinning a spelling, so a rename on either side is caught, and both of
     * login.php's database paths are checked: the Doctrine INSERT and the
     * legacy sprintf() one.
     */
    public function testTheFaillogInsertNamesColumnsTheSchemaActuallyHas(): void
    {
        require_once dirname(__DIR__) . '/install/data/tables.php';

        $faillog = get_all_tables()['faillog'] ?? null;
        self::assertIsArray($faillog, 'install/data/tables.php must still define the faillog table');

        $schemaColumns = self::columnsOf($faillog);
        self::assertContains('id', $schemaColumns, 'the cookie id column the page writes');

        $source = $this->readLoginScript();
        $matches = [];
        // The table expression is skipped rather than parsed: on the Doctrine
        // path it is a concatenated Database::prefix() call, on the legacy one
        // a sprintf placeholder. What each INSERT targets is then decided by
        // its column set, below.
        $found = preg_match_all(
            '/INSERT INTO\b.*?\(\s*(?<columns>[a-z_][a-z_0-9]*(?:\s*,\s*[a-z_][a-z_0-9]*)*)\s*\)\s*VALUES/is',
            $source,
            $matches
        );
        self::assertGreaterThan(0, $found, 'login.php still writes rows');

        $faillogWrites = 0;
        foreach ($matches['columns'] as $columnList) {
            $columns = array_map('trim', explode(',', $columnList));

            // Every INSERT on this page must name a column set that some core
            // table actually has -- which identifies the table without
            // parsing it, and catches a rename on either side.
            $target = self::tableHolding($columns);
            self::assertNotNull(
                $target,
                'login.php writes (' . implode(', ', $columns) . '), which no core table defines'
            );

            if ($target === 'faillog') {
                $faillogWrites++;
            }
        }

        self::assertSame(
            2,
            $faillogWrites,
            'the faillog is written on both the Doctrine path and the legacy one'
        );
    }

    /**
     * @param array<array-key, mixed> $table
     *
     * @return list<string>
     */
    private static function columnsOf(array $table): array
    {
        $columns = [];
        foreach ($table as $key => $definition) {
            if (is_array($definition) && isset($definition['name']) && !str_starts_with((string) $key, 'key-')) {
                $columns[] = (string) $definition['name'];
            }
        }

        return $columns;
    }

    /**
     * The core table that defines every one of these columns, if there is one.
     *
     * @param list<string> $columns
     */
    private static function tableHolding(array $columns): ?string
    {
        require_once dirname(__DIR__) . '/install/data/tables.php';

        foreach (get_all_tables() as $name => $table) {
            if (!is_array($table)) {
                continue;
            }
            if (array_diff($columns, self::columnsOf($table)) === []) {
                return (string) $name;
            }
        }

        return null;
    }

    /**
     * The ban is still issued from inside the branch the tally decides.
     *
     * Reported by Codex on this PR, and correct: moving the rule into
     * LoginFailureTally left it tested in isolation and unwitnessed in place.
     * Proved before fixing -- replacing the guard with `if (false)` disables
     * the game's only automatic ban and the whole suite stays green.
     *
     * Asked from the token stream rather than as a substring search, because
     * the useful question is structural: the ban INSERT must lie *inside* the
     * block warrantsBan() guards. That fails when the guard is deleted, when
     * the condition is replaced, and when the INSERT is lifted out of the
     * branch -- none of which a search for "warrantsBan" would notice.
     */
    public function testTheBanIsIssuedInsideTheBranchTheTallyGuards(): void
    {
        $tokens = token_get_all($this->readLoginScript());

        $guardIndex = null;
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_STRING && $token[1] === 'warrantsBan') {
                $guardIndex = $index;
                break;
            }
        }

        self::assertNotNull(
            $guardIndex,
            'login.php must still ask LoginFailureTally whether this address earns a ban'
        );

        // And it must weigh the rows it actually read back. Naming the call is
        // not enough: handing it a literal empty array leaves every assertion
        // about the call site true while the ban can never fire again.
        $weighed = self::argumentOf($tokens, 'fromRecentFailures');
        self::assertNotNull($weighed, 'the tally must be given the failure rows');
        self::assertTrue(
            self::isAssignedFromAQuery($tokens, $weighed),
            "login.php weighs $weighed, which is never assigned from a database read"
        );

        [$blockStart, $blockEnd] = self::blockGuardedFrom($tokens, $guardIndex);
        self::assertNotNull($blockEnd, 'the warrantsBan() call must open a block');

        $guarded = '';
        for ($index = $blockStart; $index <= $blockEnd; $index++) {
            $token = $tokens[$index];
            $guarded .= is_array($token) ? $token[1] : $token;
        }

        self::assertStringContainsString('INSERT INTO', $guarded, 'the ban is written inside the branch');
        self::assertStringContainsString('bans', $guarded, 'and it is written to the bans table');
    }

    /**
     * The single variable passed to $method, or null if it is not one variable.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function argumentOf(array $tokens, string $method): ?string
    {
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== $method) {
                continue;
            }

            $argument = null;
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                $inner = $tokens[$cursor];
                if (is_array($inner) && $inner[0] === T_WHITESPACE) {
                    continue;
                }
                if ($inner === '(') {
                    continue;
                }
                if ($inner === ')') {
                    return $argument;
                }
                if (is_array($inner) && $inner[0] === T_VARIABLE && $argument === null) {
                    $argument = $inner[1];
                    continue;
                }

                // A literal, a call, a second argument -- not a plain variable.
                return null;
            }
        }

        return null;
    }

    /**
     * Is $variable ever assigned from something that reads the database?
     *
     * Deliberately loose about *which* read: the point is that the value being
     * weighed comes from a query rather than from a literal, which is the
     * difference between a live guard and a decorative one.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isAssignedFromAQuery(array $tokens, string $variable): bool
    {
        $readers = ['fetchAllAssociative', 'fetchAssoc', 'fetchAssociative', 'executeQuery', 'query'];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== $variable) {
                continue;
            }

            // Look ahead over the assignment, to the end of the statement.
            $sawAssignment = false;
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                $inner = $tokens[$cursor];
                if ($inner === ';') {
                    break;
                }
                if ($inner === '=') {
                    $sawAssignment = true;
                    continue;
                }
                if (!$sawAssignment) {
                    continue;
                }
                if (is_array($inner) && $inner[0] === T_STRING && in_array($inner[1], $readers, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The token range of the block opened after $fromIndex, by brace depth.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: int, 1: int|null}
     */
    private static function blockGuardedFrom(array $tokens, int $fromIndex): array
    {
        $count = count($tokens);
        $depth = 0;
        $start = null;

        for ($index = $fromIndex; $index < $count; $index++) {
            $token = $tokens[$index];
            if ($token === '{') {
                if ($start === null) {
                    $start = $index;
                }
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    return [$start, $index];
                }
            }
        }

        return [$start ?? $fromIndex, null];
    }

    /**
     * A database error never reaches the visitor.
     *
     * Read from the source, and there is no honest way around that: the rule
     * is about what the page does *not* do, at four catch sites, and nothing
     * short of running login.php end to end could observe it. Narrow enough
     * to be worth the compromise -- an exception message from the login path
     * would hand an unauthenticated caller the table prefix, the column names
     * and often the query.
     */
    public function testADatabaseErrorIsNeverShownToTheVisitor(): void
    {
        foreach (self::statementsCalling($this->readLoginScript(), 'getMessage') as $statement) {
            foreach (self::VISITOR_FACING as $sink) {
                self::assertStringNotContainsString(
                    $sink,
                    $statement,
                    "an exception message reaches the visitor through $sink: " . trim($statement)
                );
            }
        }

        self::assertStringContainsString(
            '`4Error, your login was incorrect`0',
            $this->readLoginScript(),
            'a failure says the same thing whether the account exists or not'
        );
    }

    /**
     * Ways text reaches the person at the browser, as login.php spells them.
     *
     * @var list<string>
     */
    private const VISITOR_FACING = [
        "\$session['message']",
        'echo ',
        'print ',
        '->output(',
        '->outputNotl(',
        'appoencode(',
    ];

    /**
     * The text of each statement that calls $method.
     *
     * A statement rather than the whole file, because the rule is about where
     * the message *goes*. Forbidding the call outright -- which is what the
     * first version of this did -- also forbids handing it to debuglog(), and
     * a test that fails on server-side logging is a test people route around.
     * Reported by Copilot on this PR.
     *
     * @return list<string>
     */
    private static function statementsCalling(string $source, string $method): array
    {
        $statements = [];
        $current = '';

        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if ($text === ';' || $text === '{' || $text === '}') {
                if (str_contains($current, $method)) {
                    $statements[] = $current;
                }
                $current = '';
                continue;
            }

            $current .= $text;
        }

        if (str_contains($current, $method)) {
            $statements[] = $current;
        }

        return $statements;
    }
}
