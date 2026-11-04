<?php

declare(strict_types=1);

namespace Lotgd\Tests\Page;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * The shops and the inn swap in place (docs/ReactiveCore.md, Phase 2):
 * buying, selling, healing, the bartender and the room are granted GET links
 * like a fight's rounds, so a partial answer claims the page revision once
 * and does what the full page did.
 */
final class PartialTownPageTest extends TestCase
{
    private const PARTIAL_REQUEST = ['HTTP_HX_REQUEST' => 'true'];
    private const REVISION_CLAIM = 'SET page_revision = page_revision + 1';

    private const WEAPON = [
        'weaponid' => 7, 'weaponname' => 'Test Sword', 'damage' => 5, 'value' => 100, 'level' => 0,
    ];

    public function testBuyingAWeaponSwapsInAndEquipsIt(): void
    {
        $run = $this->town('weapons.php', ['op' => 'buy', 'id' => '7'], ['/FROM weapons WHERE weaponid = :id/' => [self::WEAPON]]);

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertCount(1, $run->statementsContaining(self::REVISION_CLAIM));
        self::assertStringContainsString('Test Sword', $run->html);
        self::assertStringNotContainsString('<script', $run->html);
    }

    public function testHealingSwapsIn(): void
    {
        $run = $this->town('healer.php', ['op' => 'buy', 'pct' => '100'], [], ['hitpoints' => 3]);

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertCount(1, $run->statementsContaining(self::REVISION_CLAIM));
        self::assertStringNotContainsString('<script', $run->html);
    }

    public function testTheInnsChatSwapsIn(): void
    {
        $run = $this->town('inn.php', ['op' => 'converse'], ['/FROM commentary/' => []]);

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertStringContainsString("name='insertcommentary'", $run->html);
    }

    public function testTheBartendersColourTestFocusesWithoutAScript(): void
    {
        $run = $this->town('inn.php', ['op' => 'bartender', 'act' => 'colors']);

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertStringContainsString("<input name='testtext' id='input' autofocus>", $run->html);
        self::assertStringNotContainsString('<script', $run->html);
    }

    public function testPayingTheGypsyIsAPageChangeToHerTalk(): void
    {
        $run = $this->town('gypsy.php', ['op' => 'pay'], [], ['gold' => 1000]);

        self::assertSame('gypsy.php?op=talk', $run->header('HX-Redirect'), self::context($run));
        self::assertCount(1, $run->statementsContaining(self::REVISION_CLAIM));
    }

    /**
     * @param array<string,string>             $get
     * @param array<string,list<array<string,mixed>>> $queries
     * @param array<string,mixed>              $account
     */
    private function town(string $page, array $get, array $queries = [], array $account = []): PageOutcome
    {
        $settings = ['partial_render' => 1, 'defaultskin' => 'twig:aurora', 'show_error_details' => 1];
        $row = $account + [
            'alive' => 1, 'location' => 'Degolburg', 'level' => 5, 'gold' => 500, 'hitpoints' => 50, 'maxhitpoints' => 50,
            'weapon' => 'Fists', 'weaponvalue' => 0, 'weapondmg' => 0, 'dragonkills' => 0,
            'lasthit' => date('Y-m-d H:i:s'), 'prefs' => serialize(['partialrender' => 1]), 'page_revision' => 0,
        ];
        $globals = ['accounts_table' => [1 => $row]];
        $queries += ['/SELECT max\(level\) AS level FROM weapons/' => [['level' => 0]], '/FROM weapons WHERE level = :level/' => [self::WEAPON]];

        $full = PageRunner::withRequest($page, 0, 'GET', [], [], $globals, $settings, [], [], $queries);
        self::assertSame(1, preg_match('/<meta name="lotgd-head" content="([0-9a-f]+)">/', $full->html, $match), self::context($full));

        return PageRunner::withRequest($page, 0, 'GET', $get, [], $globals, $settings, self::PARTIAL_REQUEST + ['HTTP_X_LOTGD_HEAD' => $match[1]], [], $queries);
    }

    private static function context(PageOutcome $run): string
    {
        $error = preg_match('~<h1>Application Error</h1>\s*<p>(.*?)</p>~s', $run->html, $m) === 1 ? $m[1] : '';

        return 'headers: ' . implode(' | ', $run->headers) . "\nerror: " . $error . "\nbody: " . substr($run->html, 0, 1500);
    }
}
