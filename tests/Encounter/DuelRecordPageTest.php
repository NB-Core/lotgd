<?php

declare(strict_types=1);

namespace Lotgd\Tests\Encounter;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * A player's duel record where players look: a bio line, and the hall of
 * fame's duels category -- both only with live encounters on.
 */
final class DuelRecordPageTest extends TestCase
{
    private const SETTINGS = [
        'live_encounters' => 1, 'realtime_enabled' => 1, 'grants_enabled' => 1, 'guarded_actions' => 1,
        'partial_render' => 1, 'defaultskin' => 'twig:aurora',
    ];

    public function testTheHallOfFameRanksTheDuelsWon(): void
    {
        $run = $this->page('hof.php', ['op' => 'duels'], self::SETTINGS);

        self::assertStringContainsString('The warriors with the most duels won', $run->html);
        self::assertStringContainsString('hof.php?op=duels&amp;subop=most', $run->html, 'Its nav.');
        $ranking = preg_grep('/FROM accounts a INNER JOIN \(SELECT acctid, SUM\(won\)/', array_merge($run->queries, $run->statements));
        self::assertNotSame([], $ranking, 'Ranked from the recorded winners and losers.');
    }

    public function testWithoutLiveEncountersTheHallOfFameHasNoDuels(): void
    {
        $run = $this->page('hof.php', ['op' => 'duels'], ['live_encounters' => 0] + self::SETTINGS);

        self::assertStringNotContainsString('duels won', $run->html);
        self::assertStringNotContainsString('hof.php?op=duels&amp;subop', $run->html);
    }

    public function testABioShowsTheDuelRecord(): void
    {
        $run = $this->page('bio.php', ['char' => '1'], self::SETTINGS, [
            '/SELECT login, name, level, sex, title, specialty, hashorse, acctid/' => [[
                'login' => 'admin', 'name' => 'Admin', 'level' => 3, 'sex' => 0, 'title' => '', 'specialty' => '', 'hashorse' => 0,
                'acctid' => 1, 'resurrections' => 0, 'bio' => '', 'dragonkills' => 0, 'race' => '', 'clanname' => '', 'clanshort' => '',
                'clanrank' => 0, 'clanid' => 0, 'laston' => date('Y-m-d H:i:s'), 'loggedin' => 1,
            ]],
            // One accomplishment: the harness would otherwise answer the news query with an account row.
            '/FROM news/' => [['newsid' => 1, 'newstext' => 'A deed', 'newsdate' => date('Y-m-d'), 'tlschema' => 'news', 'arguments' => '', 'accountid' => 1]],
            '/AS won, COALESCE\(SUM\(winner = :acctid AND stakes = :serious\), 0\) AS serious/' => [['won' => 4, 'serious' => 1, 'lost' => 2]],
        ]);

        self::assertMatchesRegularExpression('/Duels: .*4 won.*1 serious.*2 lost/s', $run->html);
    }

    /**
     * @param array<string,string>                     $get
     * @param array<string,mixed>                      $settings
     * @param array<string,list<array<string,mixed>>>  $queries
     */
    private function page(string $script, array $get, array $settings, array $queries = []): PageOutcome
    {
        return PageRunner::withRequest($script, 0, 'GET', $get, [], ['accounts_table' => [1 => [
            'alive' => 1, 'location' => 'Degolburg', 'level' => 3, 'gold' => 10, 'hitpoints' => 10, 'maxhitpoints' => 10,
            'lasthit' => date('Y-m-d H:i:s'), 'prefs' => serialize(['partialrender' => 1]), 'page_revision' => 0,
        ]]], $settings, [], [], $queries);
    }
}
