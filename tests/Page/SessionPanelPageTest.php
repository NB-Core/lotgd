<?php

declare(strict_types=1);

namespace Lotgd\Tests\Page;

use Lotgd\Page\SessionPanel;
use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * The session panel (Lotgd\Page\SessionPanel, assets/js/lotgd.timeout.js):
 * every logged-in player's full page says how long the session lasts, when
 * to warn and what to say, whether the page changes in place or not, and the
 * <head> stays the same from page to page.
 */
final class SessionPanelPageTest extends TestCase
{
    private const SETTINGS = ['defaultskin' => 'twig:aurora', 'LOGINTIMEOUT' => 600];

    public function testAClassicPlayersPageCarriesThePanel(): void
    {
        $run = PageRunner::withRequest('forest.php', 0, 'GET', [], [], [], self::SETTINGS);

        $session = self::session($run);
        self::assertSame(600, $session['timeout']);
        self::assertGreaterThan(0, $session['warn']);
        self::assertFalse($session['never']);
        self::assertNotSame('', $session['csrf']);
        self::assertSame('Stay logged in', $session['labels']['stay']);
        self::assertStringContainsString('%s', $session['labels']['countdown']);
        self::assertMatchesRegularExpression('~<script[^>]+src="[^"]*assets/js/lotgd\.timeout\.js~', $run->html);
        self::assertStringNotContainsString("id='notify'", $run->html);
    }

    public function testTheHeadIsTheSameFromPageToPage(): void
    {
        $settings = self::SETTINGS + ['partial_render' => 1, 'show_error_details' => 1];
        $globals = ['accounts_table' => [1 => [
            'alive' => 1, 'location' => 'Degolburg', 'level' => 5, 'gold' => 500, 'gems' => 10, 'goldinbank' => 50,
            'hitpoints' => 50, 'maxhitpoints' => 50, 'dragonkills' => 1,
            'lasthit' => date('Y-m-d H:i:s'), 'prefs' => serialize(['partialrender' => 1]), 'page_revision' => 0,
        ]]];

        $heads = array_map(static function (string $page) use ($settings, $globals): string {
            $run = PageRunner::withRequest($page, 0, 'GET', [], [], $globals, $settings);
            self::assertSame(1, preg_match('/<meta name="lotgd-head" content="([0-9a-f]+)">/', $run->html, $match), substr($run->html, 0, 1500));
            self::session($run);

            return $match[1];
        }, ['bank.php', 'forest.php']);

        self::assertSame($heads[0], $heads[1]);
    }

    public function testNobodyLoggedInGetsNoPanel(): void
    {
        self::assertSame('', SessionPanel::meta([]));
        self::assertSame('', SessionPanel::meta(['acctid' => 3, 'loggedin' => 0]));
    }

    /**
     * @return array<string,mixed>
     */
    private static function session(PageOutcome $run): array
    {
        self::assertSame(1, preg_match('/<meta name="lotgd-session" content="([^"]*)">/', $run->html, $match), substr($run->html, 0, 1500));
        $session = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
        self::assertIsArray($session);

        return $session;
    }
}
