<?php

declare(strict_types=1);

namespace Lotgd\Tests\Page;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * Forms that post in place (docs/ReactiveCore.md, Phase 2): the bank's
 * bookings, the preferences, the player search, the bartender's colour test
 * and the clan's forms. A listed form is answered with regions and claims the
 * page revision once; any other form sent partially is handed back before
 * the page runs, so the client can send it again as an ordinary page change.
 */
final class PartialFormsPageTest extends TestCase
{
    private const PARTIAL_REQUEST = ['HTTP_HX_REQUEST' => 'true'];
    private const REVISION_CLAIM = 'SET page_revision = page_revision + 1';
    private const ACTION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SETTINGS = ['partial_render' => 1, 'defaultskin' => 'twig:aurora', 'show_error_details' => 1];
    // No module adds preferences.
    private const QUERIES = ["/WHERE infokeys LIKE '%\\|prefs\\|%'/" => []];

    public function testABankDepositPostsInPlaceAndBooksOnce(): void
    {
        $run = $this->partialPost(
            'bank.php',
            ['op' => 'deposit'],
            ['op' => 'depositfinish'],
            ['amount' => '30', 'action_id' => self::ACTION_ID],
            ['guarded_actions' => 1],
            ['/SELECT version FROM accounts WHERE acctid = :acctid FOR UPDATE/' => [['version' => 0]]]
        );

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertStringContainsString('records your deposit of', $run->html);
        self::assertCount(1, $run->statementsContaining(self::REVISION_CLAIM));
        self::assertCount(1, $run->statementsContaining('INSERT INTO action_log'));
    }

    public function testThePreferencesSwapInWithoutAScript(): void
    {
        $run = $this->partialGet('prefs.php', []);

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertStringNotContainsString('<script', $run->html);
        self::assertStringContainsString('data-lotgd-showform=', $run->html);
        self::assertMatchesRegularExpression("/<form action='prefs.php\\?op=save' method='POST' data-lotgd-min-password='\\d+'/", $run->html);
    }

    public function testSavingThePreferencesPostsInPlaceAndKeepsTheTab(): void
    {
        $run = $this->partialPost('prefs.php', [], ['op' => 'save'], ['showFormTabIndex' => ['10001' => '2'], 'oldvalues' => serialize([]), 'mailwidth' => '70']);

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertStringContainsString('Settings saved', $run->html);
        self::assertCount(1, $run->statementsContaining(self::REVISION_CLAIM));
        self::assertSame(2, self::showForm($run)['start']);
    }

    public function testThePlayerSearchPostsInPlace(): void
    {
        $found = ['acctid' => 2, 'name' => 'Bob', 'login' => 'bob', 'alive' => 1, 'hitpoints' => 10, 'location' => 'Degolburg',
            'race' => 'Human', 'sex' => 0, 'level' => 3, 'laston' => date('Y-m-d H:i:s'), 'loggedin' => 0, 'lastip' => '', 'uniqueid' => ''];
        $run = $this->partialPost('list.php', [], ['op' => 'search'], ['name' => 'bob'], [], ['/name LIKE :namePattern/' => [$found]]);

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertStringContainsString('Bob', $run->html);
        self::assertStringNotContainsString('<script', $run->html);
    }

    public function testTheBartendersColourTestPostsInPlace(): void
    {
        $run = $this->partialPost('inn.php', ['op' => 'bartender', 'act' => 'colors'], ['op' => 'bartender', 'act' => 'colors'], ['testtext' => '`@green']);

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertStringContainsString('colLtGreen', $run->html);
    }

    public function testTheClanMessageOfTheDayPostsInPlace(): void
    {
        $row = ['clanid' => 5, 'clanname' => 'Fives', 'clanshort' => 'V', 'clandesc' => '', 'clanmotd' => '', 'customsay' => '', 'motdauthor' => 0, 'descauthor' => 0];
        $clan = ['/FROM clans WHERE clanid=\'5\'/' => [$row]];
        $officer = ['clanid' => 5, 'clanrank' => CLAN_OFFICER];
        $globals = ['__query_cache' => ['clandata-5' => [$row]]] + $this->globals($officer);
        $head = PageRunner::withRequest('clan.php', 0, 'GET', ['op' => 'motd'], [], $globals, self::SETTINGS, [], [], $clan);
        self::assertSame(1, preg_match('/<meta name="lotgd-head" content="([0-9a-f]+)">/', $head->html, $match), self::context($head));

        $run = PageRunner::withRequest(
            'clan.php',
            0,
            'POST',
            ['op' => 'motd'],
            ['clanmotd' => 'Meet at dawn'],
            $globals,
            self::SETTINGS,
            self::PARTIAL_REQUEST + ['HTTP_X_LOTGD_HEAD' => $match[1]],
            [],
            $clan
        );

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertStringContainsString('Updating MoTD', $run->html, self::context($run));
        self::assertCount(1, $run->statementsContaining('SET clanmotd = :clanmotd'));
        self::assertCount(1, $run->statementsContaining(self::REVISION_CLAIM));
    }

    public function testAFormOffTheListIsHandedBackBeforeThePageRuns(): void
    {
        $run = PageRunner::withRequest(
            'prefs.php',
            0,
            'POST',
            ['op' => 'forcechangeemail'],
            ['email' => 'new@example.com'],
            $this->globals(),
            self::SETTINGS,
            self::PARTIAL_REQUEST
        );

        self::assertSame('1', $run->header('X-Lotgd-Resubmit'), self::context($run));
        self::assertNull($run->header('HX-Redirect'), 'A redirect would turn the POST into a GET and lose the form.');
        self::assertSame('', $run->html);
        self::assertSame([], $run->statementsContaining('accounts'), 'The page must not have run.');
    }

    public function testAFullPageCarriesTheListOfFormsThatPostInPlace(): void
    {
        $run = PageRunner::withRequest('bank.php', 0, 'GET', ['op' => 'deposit'], [], $this->globals(), self::SETTINGS);

        self::assertSame(1, preg_match('/<meta name="lotgd-partial-forms" content="([^"]*)">/', $run->html, $match), self::context($run));
        $forms = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
        self::assertContains('depositfinish', $forms['bank.php']['POST']);
        self::assertSame(['save'], $forms['prefs.php']['POST']);
    }

    /**
     * @param array<string,string> $get
     */
    private function partialGet(string $page, array $get): PageOutcome
    {
        return PageRunner::withRequest(
            $page,
            0,
            'GET',
            $get,
            [],
            $this->globals(),
            self::SETTINGS,
            self::PARTIAL_REQUEST + ['HTTP_X_LOTGD_HEAD' => $this->headOf($page, $get)],
            [],
            self::QUERIES
        );
    }

    /**
     * @param array<string,string>                    $shownWith The query of the page that shows the form.
     * @param array<string,string>                    $get
     * @param array<string,mixed>                     $post
     * @param array<string,mixed>                     $settings
     * @param array<string,list<array<string,mixed>>> $queries
     */
    private function partialPost(string $page, array $shownWith, array $get, array $post, array $settings = [], array $queries = []): PageOutcome
    {
        return PageRunner::withRequest(
            $page,
            0,
            'POST',
            $get,
            $post,
            $this->globals(),
            $settings + self::SETTINGS,
            self::PARTIAL_REQUEST + ['HTTP_X_LOTGD_HEAD' => $this->headOf($page, $shownWith, $settings)],
            [],
            $queries + self::QUERIES
        );
    }

    /**
     * @param array<string,string> $get
     * @param array<string,mixed>  $settings
     */
    private function headOf(string $page, array $get, array $settings = []): string
    {
        $full = PageRunner::withRequest($page, 0, 'GET', $get, [], $this->globals(), $settings + self::SETTINGS, [], [], self::QUERIES);
        self::assertSame(1, preg_match('/<meta name="lotgd-head" content="([0-9a-f]+)">/', $full->html, $match), self::context($full));

        return $match[1];
    }

    /**
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    private function globals(array $account = []): array
    {
        return ['accounts_table' => [1 => $account + [
            'alive' => 1, 'location' => 'Degolburg', 'level' => 5, 'gold' => 500, 'gems' => 10, 'goldinbank' => 50,
            'hitpoints' => 50, 'maxhitpoints' => 50, 'dragonkills' => 1,
            'lasthit' => date('Y-m-d H:i:s'), 'prefs' => serialize(['partialrender' => 1]), 'page_revision' => 0,
        ]]];
    }

    /**
     * @return array<string,mixed>
     */
    private static function showForm(PageOutcome $run): array
    {
        self::assertSame(1, preg_match("/data-lotgd-showform='([^']*)'/", $run->html, $match), self::context($run));

        return json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
    }

    private static function context(PageOutcome $run): string
    {
        $error = preg_match('~<h1>Application Error</h1>\s*<p>(.*?)</p>~s', $run->html, $m) === 1 ? $m[1] : '';

        return 'headers: ' . implode(' | ', $run->headers) . "\nerror: " . $error . "\nbody: " . substr($run->html, 0, 1500);
    }
}
