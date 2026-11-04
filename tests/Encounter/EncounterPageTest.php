<?php

declare(strict_types=1);

namespace Lotgd\Tests\Encounter;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * encounter.php through the page harness: the duelling ground lists who is
 * around inside the challenge form, and a challenge is a guarded POST.
 */
final class EncounterPageTest extends TestCase
{
    private const SETTINGS = [
        'live_encounters' => 1, 'realtime_enabled' => 1, 'grants_enabled' => 1, 'guarded_actions' => 1,
        'partial_render' => 1, 'defaultskin' => 'twig:aurora',
    ];

    public function testTheGroundListsWhoIsAroundInsideTheChallengeForm(): void
    {
        $run = $this->page('GET', [], [], self::SETTINGS, [
            '/SELECT p\.acctid, a\.name, a\.level, p\.place FROM presence p/' => [
                ['acctid' => 1, 'name' => 'Me', 'level' => 3, 'place' => 'arena'],
                ['acctid' => 2, 'name' => 'Two', 'level' => 4, 'place' => 'village'],
                ['acctid' => 3, 'name' => 'Three', 'level' => 2, 'place' => 'forest'],
            ],
        ]);

        self::assertMatchesRegularExpression(
            "~<form action='encounter.php\?op=challenge' method='POST'>.*name='action_id'.*<div data-lotgd-region='presence'>~s",
            $run->html
        );
        self::assertStringContainsString("name='target' value='2'", $run->html);
        self::assertStringNotContainsString("name='target' value='1'", $run->html, 'Not oneself.');
        self::assertStringNotContainsString("name='target' value='3'", $run->html, 'Not someone in the forest.');
        self::assertStringContainsString("data-lotgd-region='encounter'", $run->html);
        self::assertMatchesRegularExpression('/<meta name="lotgd-region-versions" content="[^"]*&quot;presence&quot;:\d+/', $run->html);
        self::assertMatchesRegularExpression('/<meta name="lotgd-region-versions" content="[^"]*&quot;encounter&quot;:\d+/', $run->html);
        $version = array_key_first(preg_grep('/COALESCE\(MAX\(changed_us\), 0\) FROM presence/', $run->queries));
        $list = array_key_first(preg_grep('/SELECT p\.acctid, a\.name/', $run->queries));
        self::assertNotNull($version);
        self::assertLessThan($list, $version, 'The version is read before the list it stands for.');
        self::assertDoesNotMatchRegularExpression('~<script(?![^>]*\bsrc=)~', (string) preg_replace('~^.*<body~s', '', $run->html), 'No inline script: the page swaps in place.');
    }

    public function testTheControlsOfAPendingChallengeNameItsStateForTheClient(): void
    {
        $row = [
            'id' => 7, 'kind' => 'duel', 'challenger' => 1, 'opponent' => 2, 'state' => 'pending', 'stakes' => 'friendly',
            'data' => null, 'grant_id' => 0, 'created' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + 60),
            'changed_us' => 1,
        ];
        $run = $this->page('GET', ['op' => 'view', 'id' => '7'], [], self::SETTINGS, [
            '/FROM encounters WHERE id = :id$/' => [$row],
            '/WHERE challenger = :acctid OR opponent = :acctid ORDER BY id DESC LIMIT 1/' => [$row],
        ]);

        // The region says which encounter and state it shows; the controls
        // outside it say which they belong to, and hold the withdraw form.
        self::assertStringContainsString("<span hidden data-lotgd-encounter='7' data-lotgd-encounter-state='pending'></span>", $run->html);
        self::assertMatchesRegularExpression(
            "~<div data-lotgd-encounter-controls='7' data-lotgd-encounter-state='pending'>\\s*<form action='encounter.php\\?op=cancel&(amp;)?id=7'.*?</form>\\s*</div>~s",
            $run->html
        );
    }

    public function testASpectatorWatchesADuelBeingFoughtInItsOwnRegion(): void
    {
        $row = $this->duelRow('accepted', 2, 3);
        $run = $this->page('GET', ['op' => 'watch', 'id' => '7'], [], self::SETTINGS, ['/FROM encounters WHERE id = :id$/' => [$row]]);

        self::assertStringContainsString("<div data-lotgd-region='duelwatch'><div class='lotgd-duel lotgd-duel-watch'>", $run->html);
        self::assertStringNotContainsString('data-lotgd-duel-choice', $run->html, 'No buttons for a spectator.');
        self::assertMatchesRegularExpression('/<meta name="lotgd-region-versions" content="[^"]*&quot;duelwatch&quot;:5/', $run->html);
    }

    public function testAChallengeIsNoDuelToWatch(): void
    {
        $run = $this->page('GET', ['op' => 'watch', 'id' => '7'], [], self::SETTINGS, ['/FROM encounters WHERE id = :id$/' => [$this->duelRow('pending', 2, 3)]]);

        self::assertStringContainsString('There is no such duel to watch.', $run->html);
        self::assertStringNotContainsString("data-lotgd-region='duelwatch'", $run->html);
    }

    public function testADuelThatIsOverOffersItsPlayersARematch(): void
    {
        $row = $this->duelRow('ended', 1, 2);
        $run = $this->page('GET', ['op' => 'view', 'id' => '7'], [], self::SETTINGS, [
            '/FROM encounters WHERE id = :id$/' => [$row],
            '/WHERE challenger = :acctid OR opponent = :acctid ORDER BY id DESC LIMIT 1/' => [$row],
        ]);

        self::assertMatchesRegularExpression(
            "~<div data-lotgd-encounter-controls='7' data-lotgd-encounter-state='ended'>\\s*<form action='encounter.php\\?op=rematch&(amp;)?id=7'~",
            $run->html
        );
    }

    public function testWhileTheDuelIsFoughtTheRematchWaitsHidden(): void
    {
        $row = $this->duelRow('accepted', 1, 2);
        $run = $this->page('GET', ['op' => 'view', 'id' => '7'], [], self::SETTINGS, [
            '/FROM encounters WHERE id = :id$/' => [$row],
            '/WHERE challenger = :acctid OR opponent = :acctid ORDER BY id DESC LIMIT 1/' => [$row],
        ]);

        self::assertStringContainsString("<div data-lotgd-encounter-controls='7' data-lotgd-encounter-state='ended' hidden>", $run->html);
    }

    public function testTheGroundListsTheDuelsBeingFought(): void
    {
        $run = $this->page('GET', [], [], self::SETTINGS, [
            '/FROM encounters e INNER JOIN accounts c/' => [
                ['id' => 7, 'challenger' => 2, 'opponent' => 3, 'stakes' => 'serious', 'challenger_name' => 'Two', 'opponent_name' => 'Three'],
            ],
        ]);

        self::assertStringContainsString('Duels being fought', $run->html);
        self::assertStringContainsString("href='encounter.php?op=watch&amp;id=7", $run->html);
    }

    public function testAPlayerWhoCanPlayLiveSeesNoHint(): void
    {
        $run = $this->page('GET', [], [], self::SETTINGS);

        self::assertStringNotContainsString('lotgd-live-hint', $run->html);
    }

    public function testAPlayerWithoutPartialRenderingLearnsToTurnItOn(): void
    {
        $globals = $this->globals();
        $globals['accounts_table'][1]['prefs'] = serialize(['partialrender' => 0]);
        $run = PageRunner::withRequest('encounter.php', 0, 'GET', [], [], $globals, self::SETTINGS);

        self::assertStringContainsString("<p class='lotgd-live-hint'>", $run->html);
        self::assertStringContainsString('Change pages in place', $run->html);
        self::assertStringContainsString("<a href='prefs.php' class='nav'>", $run->html);
    }

    public function testABundledLegacySkinPlaysLive(): void
    {
        $run = $this->page('GET', [], [], ['defaultskin' => 'jade.htm'] + self::SETTINGS);

        self::assertStringNotContainsString('lotgd-live-hint', $run->html);
        self::assertStringContainsString('<meta name="lotgd-head"', $run->html, 'The partial client runs on jade.');
    }

    public function testAPlayerOnASkinWithoutRegionsLearnsToChooseAnother(): void
    {
        // A legacy template of one's own, from before regions: jade without its config section.
        $file = dirname(__DIR__, 2) . '/templates/zz_noregions_test.htm';
        $jade = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/jade.htm');
        file_put_contents($file, explode('<!--!config-->', $jade, 2)[0]);
        try {
            $run = $this->page('GET', [], [], ['defaultskin' => 'zz_noregions_test.htm'] + self::SETTINGS);
        } finally {
            unlink($file);
        }

        self::assertStringContainsString("<p class='lotgd-live-hint'>", $run->html);
        self::assertStringContainsString('your skin cannot do that yet', $run->html);
    }

    public function testWithPagesInPlaceOffForTheGameThePlayerLearnsItIsTheAdministrators(): void
    {
        $run = $this->page('GET', [], [], ['partial_render' => 0] + self::SETTINGS);

        self::assertStringContainsString("<p class='lotgd-live-hint'>", $run->html);
        self::assertStringContainsString('An administrator can turn it on', $run->html);
        self::assertStringNotContainsString('Change pages in place', $run->html, 'The preferences cannot help.');
        self::assertStringNotContainsString("<a href='prefs.php' class='nav'>", $run->html);
    }

    public function testWithTheSettingOffTheGroundIsClosed(): void
    {
        $run = $this->page('GET', [], [], ['live_encounters' => 0] + self::SETTINGS);

        self::assertStringContainsString('The duelling ground is closed', $run->html);
        self::assertStringNotContainsString('op=challenge', $run->html);
    }

    public function testAChallengeWithoutAnActionIdDoesNothing(): void
    {
        $run = PageRunner::withRequest('encounter.php', 0, 'POST', ['op' => 'challenge'], ['target' => '2'], $this->globals(), self::SETTINGS);

        self::assertStringContainsString('This form was already used', $run->html);
        self::assertSame([], preg_grep('/^INSERT INTO encounters/', $run->queries));
    }

    /**
     * @param array<string,string>                     $get
     * @param array<string,string>                     $post
     * @param array<string,mixed>                      $settings
     * @param array<string,list<array<string,mixed>>>  $queries
     */
    private function page(string $method, array $get, array $post, array $settings, array $queries = []): PageOutcome
    {
        return PageRunner::withRequest('encounter.php', 0, $method, $get, $post, $this->globals(), $settings, [], [], $queries);
    }

    /**
     * @return array<string,mixed>
     */
    private function duelRow(string $state, int $challenger, int $opponent): array
    {
        $data = [
            'players' => [
                (string) $challenger => ['name' => 'One', 'hp' => 20, 'maxhp' => 30, 'attack' => 10, 'defense' => 10, 'level' => 3],
                (string) $opponent => ['name' => 'Two', 'hp' => 25, 'maxhp' => 30, 'attack' => 10, 'defense' => 10, 'level' => 3],
            ],
            'round' => 2,
            'log' => [],
        ];
        if ($state === 'ended') {
            $data['result'] = ['winner' => $challenger, 'loser' => $opponent, 'reason' => 'knockout', 'rounds' => 2];
        }

        return [
            'id' => 7, 'kind' => 'duel', 'challenger' => $challenger, 'opponent' => $opponent, 'state' => $state, 'stakes' => 'friendly',
            'data' => (string) json_encode($data), 'grant_id' => 0, 'created' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + 600), 'changed_us' => 5,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function globals(): array
    {
        return ['accounts_table' => [1 => [
            'alive' => 1, 'location' => 'Degolburg', 'level' => 3, 'gold' => 10, 'hitpoints' => 10, 'maxhitpoints' => 10,
            'lasthit' => date('Y-m-d H:i:s'), 'prefs' => serialize(['partialrender' => 1]), 'page_revision' => 0,
        ]]];
    }
}
