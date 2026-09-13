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
     * The statements that carry $signature.
     *
     * A signature -- "DELETE FROM masters", "UPDATE accounts SET donation" --
     * rather than a table name, because a table name is not specific enough for
     * half of these pages. Every request updates the online counter in
     * `settings` and the player's row in `accounts` whatever it is doing, so
     * "did anything write to accounts" is yes even for a request that was
     * refused; a row watching that table would pass in both directions and say
     * nothing. Naming the statement the operation actually issues keeps every
     * row answerable the same way, and makes the row read as what the page does.
     *
     * Whitespace is normalised first: these queries are assembled from
     * concatenated fragments and span lines, so the text between two words is
     * not reliably a single space.
     *
     * @return list<string>
     */
    public function statementsContaining(string $signature): array
    {
        $normalise = static fn (string $text): string => (string) preg_replace('/\s+/', ' ', $text);
        $needle = $normalise($signature);

        return array_values(array_filter(
            $this->statements,
            static fn (string $statement): bool => stripos($normalise($statement), $needle) !== false
        ));
    }
}
