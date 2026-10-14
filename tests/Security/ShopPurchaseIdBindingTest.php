<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The id a shop's buy link names reaches its query as a bound integer.
 *
 * armor.php put the request string between quotes in its SQL, unescaped.
 * Forced navigation only lets a player request the buy links the shop listed,
 * so it was not reachable as it stood, but it was the only thing in the way.
 * The pages are run with a request the shop never offered and the statements
 * they sent are read back.
 *
 * @group security
 */
final class ShopPurchaseIdBindingTest extends TestCase
{
    private const INJECTED = "7' OR '1'='1";

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function shops(): array
    {
        return [
            'armour' => ['armor.php', 'SELECT * FROM armor WHERE armorid = :id'],
            'weapons' => ['weapons.php', 'SELECT * FROM weapons WHERE weaponid = :id'],
        ];
    }

    #[DataProvider('shops')]
    public function testTheIdIsBoundNotWrittenIntoTheQuery(string $page, string $expected): void
    {
        $run = PageRunner::withToken(
            $page,
            0,
            ['op' => 'buy', 'id' => self::INJECTED],
            [],
            ['accounts_table' => [1 => ['alive' => 1, 'location' => 'Degolburg', 'lasthit' => date('Y-m-d H:i:s')]]]
        );

        $this->assertContains($expected, $run->queries, "$page looks the item up with a placeholder");
        foreach ($run->queries as $query) {
            $this->assertStringNotContainsString("OR '1'='1", $query, "$page wrote the request into its SQL");
        }
    }
}
