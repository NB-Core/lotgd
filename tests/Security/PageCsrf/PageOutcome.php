<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security\PageCsrf;

/**
 * What one run of a page did to the database.
 *
 * Statements rather than output, because output is what a page says and
 * statements are what it does. A refused operation is allowed to say anything
 * it likes; it is not allowed to write.
 */
final class PageOutcome
{
    /**
     * @param list<string> $statements Everything that went through executeStatement().
     * @param list<string> $queries    Everything that went through executeQuery().
     */
    public function __construct(
        public readonly array $statements,
        public readonly array $queries,
        public readonly int|false $status,
    ) {
    }

    /**
     * Statements that write to $table, ignoring the bootstrap's own traffic.
     *
     * Every request updates the online counter and the session row whatever it
     * is doing, so "did anything get written" is always yes. The question worth
     * asking is whether *this page's* table was touched.
     *
     * @return list<string>
     */
    public function writesTo(string $table): array
    {
        $pattern = '/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+`?' . preg_quote($table, '/') . '`?\b/i';

        return array_values(array_filter(
            $this->statements,
            static fn (string $statement): bool => preg_match($pattern, $statement) === 1
        ));
    }
}
