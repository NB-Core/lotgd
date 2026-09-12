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
 *     superuser failure asserted at all, which the string never was.
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
        $source = $this->readLoginScript();

        self::assertStringNotContainsString(
            '$exception->getMessage()',
            $source,
            'the login page must not render or echo what the database said'
        );
        self::assertStringContainsString(
            '`4Error, your login was incorrect`0',
            $source,
            'a failure says the same thing whether the account exists or not'
        );
    }
}
