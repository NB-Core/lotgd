<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * bio.php looks the character up by the `char` of its URL. That value used
 * to go into the SQL as it came (`login = '$char'`); it is a bound parameter
 * now, an integer for an account id and a string for a login.
 */
final class BioLookupBindingTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function lookups(string $char): array
    {
        $run = PageRunner::withRequest('bio.php', 0, 'GET', ['char' => $char], [], ['accounts_table' => [1 => ['alive' => 1, 'lasthit' => date('Y-m-d H:i:s')]]], []);

        return array_values(array_filter(
            array_map('strval', $run->queries),
            static fn (string $sql): bool => str_contains($sql, 'LEFT JOIN clans') && str_contains($sql, 'FROM accounts')
        ));
    }

    public function testALoginWithAQuoteIsAParameterNotSql(): void
    {
        $lookups = $this->lookups("x' OR '1'='1");

        self::assertNotEmpty($lookups, 'bio.php must have looked the character up.');
        foreach ($lookups as $sql) {
            self::assertStringContainsString('WHERE login = :char', $sql);
            self::assertStringNotContainsString("'1'='1", $sql);
        }
    }

    public function testAnAccountIdIsLookedUpByIdAsAParameter(): void
    {
        $lookups = $this->lookups('7');

        self::assertNotEmpty($lookups);
        self::assertStringContainsString('WHERE acctid = :char', $lookups[0]);
    }
}
