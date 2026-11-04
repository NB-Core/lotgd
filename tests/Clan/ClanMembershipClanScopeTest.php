<?php

declare(strict_types=1);

namespace Lotgd\Tests\Clan;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * An officer changes ranks and removes members of their own clan only. The
 * posted account id is the officer's to choose; until the writes named the
 * clan, an officer could post the id of anybody in the game and promote,
 * demote or expel them from a clan that was not theirs.
 */
final class ClanMembershipClanScopeTest extends TestCase
{
    private const LOOKUP = '/SELECT name, login, clanrank\s+FROM accounts\s+WHERE acctid = 2 AND clanid = 5/';

    public function testARankChangeForAPlayerOfAnotherClanWritesNothing(): void
    {
        $run = $this->membership(['setrank' => '20', 'whoacctid' => '2'], []);

        self::assertSame([], $this->writes($run), $this->context($run));
        self::assertMatchesRegularExpression(self::LOOKUP, implode("\n", $this->sql($run)), 'The lookup names the clan.');
    }

    public function testARankChangeInTheOwnClanNamesTheClan(): void
    {
        $run = $this->membership(['setrank' => '20', 'whoacctid' => '2'], [['name' => 'Bob', 'login' => 'bob', 'clanrank' => 10]]);

        $writes = $this->writes($run);
        self::assertCount(1, $writes, $this->context($run));
        self::assertMatchesRegularExpression('/WHERE acctid = 2 AND clanid = 5$/', $writes[0]);
    }

    public function testRemovingAPlayerOfAnotherClanWritesNothing(): void
    {
        $run = $this->membership(['remove' => '2'], []);

        self::assertSame([], $this->writes($run), $this->context($run));
    }

    public function testRemovingAMemberNamesTheClanAndClosesTheirApplicationGrants(): void
    {
        $run = $this->membership(['remove' => '2'], [['name' => 'Bob', 'login' => 'bob', 'clanrank' => 0]], ['grants_enabled' => 1]);

        $writes = $this->writes($run);
        self::assertCount(1, $writes, $this->context($run));
        self::assertMatchesRegularExpression('/WHERE acctid = 2 AND clanid = 5 AND clanrank <= 30$/', $writes[0]);
        self::assertContains('SELECT id, acctid, used_at FROM granted_navs WHERE target_key = :key AND used_at IS NULL', $this->sql($run));
    }

    public function testAcceptingAnApplicantTheOldWayClosesTheirGrants(): void
    {
        $run = $this->membership(['setrank' => '10', 'whoacctid' => '2'], [['name' => 'Bob', 'login' => 'bob', 'clanrank' => 0]], ['grants_enabled' => 1]);

        self::assertContains('SELECT id, acctid, used_at FROM granted_navs WHERE target_key = :key AND used_at IS NULL', $this->sql($run), $this->context($run));
    }

    /**
     * @param array<string,string>             $post
     * @param list<array<string,mixed>>        $found What the clan-scoped lookup finds.
     * @param array<string,int>                $settings
     */
    private function membership(array $post, array $found, array $settings = []): PageOutcome
    {
        return PageRunner::withRequest(
            'clan.php',
            0,
            'POST',
            ['op' => 'membership'],
            $post,
            [
                'accounts_table' => [1 => ['clanid' => 5, 'clanrank' => 30, 'name' => 'Leader', 'lasthit' => date('Y-m-d H:i:s')]],
                '__query_cache' => ['clandata-5' => [['clanid' => 5, 'clanname' => 'Fives', 'clanshort' => 'V', 'clandesc' => '', 'clanmotd' => '']]],
            ],
            $settings + ['guarded_actions' => 1],
            [],
            [],
            [self::LOOKUP => $found]
        );
    }

    /**
     * @return list<string>
     */
    private function sql(PageOutcome $run): array
    {
        return array_map(static fn ($sql): string => trim((string) preg_replace('/\s+/', ' ', (string) $sql)), $run->queries);
    }

    /**
     * The writes on another account: rank changes and removals.
     *
     * @return list<string>
     */
    private function writes(PageOutcome $run): array
    {
        return array_values(array_filter(
            $this->sql($run),
            static fn (string $sql): bool => str_starts_with($sql, 'UPDATE accounts SET clanrank')
        ));
    }

    private function context(PageOutcome $run): string
    {
        return implode("\n", array_slice($this->sql($run), -40));
    }
}
