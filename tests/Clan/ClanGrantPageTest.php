<?php

declare(strict_types=1);

namespace Lotgd\Tests\Clan;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * Accepting a clan applicant through a grant (docs/ReactiveCore.md, Phase 5),
 * run through the page harness: the view, the accepting action in one
 * transaction with the grant's group, and every refusal.
 */
final class ClanGrantPageTest extends TestCase
{
    private const ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const OFFICER = ['clanid' => 5, 'clanrank' => 20, 'name' => 'Officer'];
    private const SETTINGS = ['guarded_actions' => 1, 'grants_enabled' => 1];
    private const VERSION_LOCK = '/SELECT version FROM accounts WHERE acctid = :acctid FOR UPDATE/';
    private const KEY_LOOKUP = '/SELECT target_key FROM granted_navs WHERE id = :id/';
    private const GROUP_LOCK = '/FROM granted_navs WHERE target_key = :key ORDER BY id FOR UPDATE/';
    private const FIND = '/FROM granted_navs WHERE id = :id AND acctid = :acctid AND used_at IS NULL/';
    private const ACCEPT = '/UPDATE accounts SET clanrank = :member/';
    private const APPLICANT_LOCK = '/SELECT name, login FROM accounts WHERE acctid = :applicant AND clanid = :clanid AND clanrank = :applicantrank FOR UPDATE/';
    private const CLAN = ['/FROM clans WHERE clanid=\'5\'/' => [['clanid' => 5, 'clanname' => 'Fives', 'clanshort' => 'V', 'clandesc' => '', 'clanmotd' => '']]];

    public function testTheViewShowsTheApplicationAndAFormThatAccepts(): void
    {
        $run = $this->get([self::FIND => [$this->grant()], '/SELECT name FROM accounts WHERE acctid = :acctid/' => [['name' => 'Nine']]]);

        self::assertStringContainsString('It reads: ', $run->html, $this->context($run));
        self::assertStringContainsString('Nine', $run->html);
        self::assertStringContainsString("action='clan.php?op=grant&amp;grant=5' method='POST'", $run->html);
        self::assertMatchesRegularExpression("/name='action_id' value='[0-9a-f]{32}'/", $run->html);
    }

    public function testAGrantThatIsNotOpenShowsNoForm(): void
    {
        $run = $this->get();

        self::assertStringContainsString('already been decided', $run->html, $this->context($run));
        self::assertStringNotContainsString('action_id', $run->html);
    }

    public function testAcceptingIsOneTransactionWithTheGrantsGroup(): void
    {
        $run = $this->post();

        self::assertStringContainsString('is now a member of your clan', $run->html, $this->context($run));
        self::assertSame(
            ['START TRANSACTION', 'version lock', 'key', 'group lock', 'close', 'applicant lock', 'accept', 'mail', 'log', 'COMMIT', 'debuglog'],
            $this->steps($run)
        );
    }

    public function testAnApplicantWhoWithdrewIsNotAcceptedAndTheGrantStaysAsItWas(): void
    {
        $run = $this->post([self::APPLICANT_LOCK => []]);

        self::assertStringContainsString('withdrawn the application', $run->html, $this->context($run));
        $steps = $this->steps($run);
        self::assertSame('ROLLBACK', end($steps));
        self::assertNotContains('mail', $steps);
        self::assertNotContains('accept', $steps);
        self::assertNotContains('COMMIT', $steps);
    }

    public function testAGrantUsedByAnotherOfficerIsRefused(): void
    {
        $run = $this->post([self::GROUP_LOCK => [['used_at' => '2026-10-21 10:00:00'] + $this->grant()]]);

        self::assertStringContainsString('Another officer has already decided', $run->html, $this->context($run));
        self::assertNotContains('accept', $this->steps($run));
    }

    public function testAnOfficerNoLongerOfTheClanIsRefused(): void
    {
        $run = $this->post(account: ['clanrank' => 10]);

        self::assertStringContainsString('no longer an officer', $run->html, $this->context($run));
        self::assertNotContains('accept', $this->steps($run));
    }

    public function testWithoutAnActionIdNothingIsDecided(): void
    {
        $run = $this->post(post: []);

        self::assertStringContainsString('can no longer be decided here', $run->html, $this->context($run));
        self::assertSame([], $this->steps($run));
    }

    public function testWithoutTheFormTokenNothingIsDecided(): void
    {
        $run = PageRunner::withoutToken('clan.php', 0, ['op' => 'grant', 'grant' => '5'], ['action_id' => self::ID], $this->globals(), self::SETTINGS);

        self::assertStringContainsString('cannot take this form', $run->html, $this->context($run));
        self::assertSame([], $this->steps($run));
    }

    public function testWithGrantsOffThereIsNothingHere(): void
    {
        $run = PageRunner::withRequest('clan.php', 0, 'GET', ['op' => 'grant', 'grant' => '5'], [], $this->globals(), ['guarded_actions' => 1, 'grants_enabled' => 0]);

        self::assertStringContainsString('nothing waiting for you here', $run->html, $this->context($run));
    }

    public function testAnApplicationOffersEveryOfficerAGrant(): void
    {
        $run = PageRunner::withRequest(
            'clan.php',
            0,
            'GET',
            ['op' => 'apply', 'to' => '5'],
            [],
            ['accounts_table' => [1 => ['clanid' => 0, 'clanrank' => 0, 'name' => 'Nine', 'lasthit' => date('Y-m-d H:i:s')]]],
            self::SETTINGS,
            [],
            [],
            ['/WHERE clanid=\'5\' AND clanrank>=20/' => [['acctid' => 2], ['acctid' => 3]]]
        );

        $grants = array_values(array_filter($run->queries, static fn ($sql): bool => str_starts_with((string) $sql, 'INSERT INTO granted_navs')));
        self::assertCount(2, $grants, $this->context($run));
        self::assertContains(
            'SELECT id, acctid, used_at FROM granted_navs WHERE target_key = :key AND used_at IS NULL',
            array_map('strval', $run->queries),
            'An earlier application of the same player closes first.'
        );
    }

    /**
     * @param array<string,list<array<string,mixed>>> $queries
     */
    private function get(array $queries = []): PageOutcome
    {
        return PageRunner::withRequest('clan.php', 0, 'GET', ['op' => 'grant', 'grant' => '5'], [], $this->globals(), self::SETTINGS, [], [], $queries + self::CLAN);
    }

    /**
     * @param array<string,list<array<string,mixed>>> $queries
     * @param array<string,int>                       $statements
     * @param array<string,string>                    $post
     * @param array<string,mixed>                     $account
     */
    private function post(array $queries = [], array $statements = [], array $post = ['action_id' => self::ID], array $account = []): PageOutcome
    {
        return PageRunner::withRequest(
            'clan.php',
            0,
            'POST',
            ['op' => 'grant', 'grant' => '5'],
            $post,
            $this->globals($account),
            self::SETTINGS,
            [],
            $statements + [self::ACCEPT => 1],
            $queries + self::CLAN + [
                self::VERSION_LOCK => [['version' => 0]],
                self::KEY_LOOKUP => [['target_key' => 'clan-applicant-9']],
                self::GROUP_LOCK => [$this->grant()],
                self::APPLICANT_LOCK => [['name' => 'Nine', 'login' => 'nine']],
            ]
        );
    }

    /**
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    private function globals(array $account = []): array
    {
        return [
            'accounts_table' => [1 => $account + self::OFFICER + ['lasthit' => date('Y-m-d H:i:s')]],
            '__query_cache' => ['clandata-5' => [['clanid' => 5, 'clanname' => 'Fives', 'clanshort' => 'V', 'clandesc' => '', 'clanmotd' => '']]],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function grant(): array
    {
        return [
            'id' => 5,
            'acctid' => 1,
            'action' => 'clan.accept',
            'target' => '{"applicant":9,"clan":5}',
            'target_key' => 'clan-applicant-9',
            'text' => 'Accept Nine',
            'link' => 'clan.php?op=grant&grant=5',
            'used_at' => null,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ];
    }

    /**
     * @return list<string>
     */
    private function steps(PageOutcome $run): array
    {
        $steps = [];
        foreach ($run->queries as $sql) {
            $sql = (string) preg_replace('/\s+/', ' ', (string) $sql);
            if (in_array($sql, ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true)) {
                $steps[] = $sql;
            } elseif (preg_match(self::VERSION_LOCK, $sql) === 1) {
                $steps[] = 'version lock';
            } elseif (preg_match(self::KEY_LOOKUP, $sql) === 1) {
                $steps[] = 'key';
            } elseif (preg_match(self::GROUP_LOCK, $sql) === 1) {
                $steps[] = 'group lock';
            } elseif (str_starts_with($sql, 'UPDATE granted_navs SET used_at')) {
                $steps[] = 'close';
            } elseif (preg_match(self::APPLICANT_LOCK, $sql) === 1) {
                $steps[] = 'applicant lock';
            } elseif (str_starts_with($sql, 'INSERT INTO debuglog')) {
                $steps[] = 'debuglog';
            } elseif (preg_match(self::ACCEPT, $sql) === 1) {
                $steps[] = 'accept';
            } elseif (str_starts_with($sql, 'INSERT INTO mail')) {
                $steps[] = 'mail';
            } elseif (str_starts_with($sql, 'INSERT INTO action_log')) {
                $steps[] = 'log';
            }
        }

        return $steps;
    }

    private function context(PageOutcome $run): string
    {
        return substr(strip_tags($run->html), 0, 3000) . "\n" . implode("\n", array_map('strval', array_slice($run->queries, -40)));
    }
}
