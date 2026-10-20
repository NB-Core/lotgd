<?php

declare(strict_types=1);

namespace Lotgd\Tests\Page;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * Partial rendering, run for real through the page harness.
 *
 * What is pinned here is the HTTP contract of docs/ReactiveCore.md, Phase 2:
 * which request gets regions, which a page change, and that the decisions
 * that keep the navigation allow-list intact happen where they must -- the
 * early fallback before the page runs, the revision claim before the page
 * runs, the late fallback without running the URL twice.
 */
final class PartialRenderingPageTest extends TestCase
{
    private const PLAYER = [
        'alive' => 1,
        'location' => 'Degolburg',
        'level' => 1,
        'gold' => 10,
        'hitpoints' => 10,
        'maxhitpoints' => 10,
    ];

    private const PARTIAL_REQUEST = ['HTTP_HX_REQUEST' => 'true'];
    private const REVISION_CLAIM = 'SET page_revision = page_revision + 1';

    public function testAnAllowedPageAnswersWithRegionsAndClaimsTheRevision(): void
    {
        $run = $this->request('forest.php', self::PARTIAL_REQUEST + ['HTTP_X_LOTGD_HEAD' => $this->headOf('forest.php')]);

        self::assertSame('1', $run->header('X-Lotgd-Partial'), $this->context($run));
        self::assertSame('1', $run->header('X-Lotgd-Page-Revision'));
        self::assertNull($run->header('HX-Redirect'));
        self::assertStringNotContainsString('<html', $run->html, 'A partial answer is never a document.');
        self::assertStringContainsString('hx-swap-oob="innerHTML:[data-lotgd-region=&quot;nav&quot;]"', $run->html);
        self::assertStringContainsString('<title>The Forest</title>', $run->html);
        self::assertCount(1, $run->statementsContaining(self::REVISION_CLAIM));
        self::assertCount(
            1,
            $run->statementsContaining("allowednavs = ''"),
            'The claim must clear the stored links in the same statement, or a request that reads the row after it could follow them too.'
        );
    }

    public function testAPageWantingAnotherHeadIsShownFromTheStoredCopy(): void
    {
        $run = $this->request('forest.php', self::PARTIAL_REQUEST + ['HTTP_X_LOTGD_HEAD' => 'not-this-head']);

        self::assertSame('badnav.php', $run->header('HX-Redirect'), $this->context($run));
        self::assertNull($run->header('X-Lotgd-Partial'));
    }

    public function testAPageOffTheAllowListBecomesAPageChangeBeforeItRuns(): void
    {
        $run = $this->request('bank.php', self::PARTIAL_REQUEST);

        // The URI is already reduced to the script and its query at this point,
        // which the client resolves against the page it is on.
        self::assertSame('bank.php', $run->header('HX-Redirect'));
        self::assertSame('', $run->html);
        // Bootstrap may touch the settings table; the page itself, the forced
        // navigation and saveUser() write to accounts, and none of them ran.
        self::assertSame([], $run->statementsContaining('accounts'), 'The page must not have run.');
    }

    public function testALostRevisionClaimIsTurnedAwayWithoutSaving(): void
    {
        $run = $this->request('forest.php', self::PARTIAL_REQUEST, ['/' . preg_quote(self::REVISION_CLAIM, '/') . '/' => 0]);

        self::assertSame(409, $run->status);
        self::assertSame(['reload' => 'badnav.php'], json_decode($run->html, true));
        self::assertSame(
            $run->statementsContaining(self::REVISION_CLAIM),
            $run->statementsContaining('UPDATE accounts'),
            'The turned-away request must not save the account, or its navigation would overwrite the winner\'s.'
        );
    }

    public function testALostClaimOnAFullPageGoesToTheStoredPage(): void
    {
        $run = $this->request('forest.php', [], ['/' . preg_quote(self::REVISION_CLAIM, '/') . '/' => 0]);

        self::assertStringContainsString("to location 'badnav.php'", $run->html, $this->context($run));
        self::assertSame(
            $run->statementsContaining(self::REVISION_CLAIM),
            $run->statementsContaining('UPDATE accounts')
        );
    }

    public function testWithoutThePreferenceThePageIsShownFromTheStoredCopy(): void
    {
        $run = $this->request('forest.php', self::PARTIAL_REQUEST, [], ['partialrender' => 0]);

        self::assertSame('badnav.php', $run->header('HX-Redirect'), $this->context($run));
        self::assertSame([], $run->statementsContaining(self::REVISION_CLAIM));
    }

    public function testWithTheSettingOffNothingChanges(): void
    {
        $run = $this->request('forest.php', [], [], ['partialrender' => 1], ['partial_render' => 0]);

        self::assertSame([], $run->statementsContaining(self::REVISION_CLAIM));
        self::assertStringNotContainsString('htmx.min.js', $run->html);
        self::assertStringNotContainsString('lotgd-page-revision', $run->html);
        self::assertStringContainsString('const quickLinks', $run->html);
    }

    public function testAFullPageForAPartialPlayerCarriesTheClientInsteadOfTheQuickKeyScript(): void
    {
        $run = $this->request('forest.php', []);

        self::assertStringContainsString('htmx.min.js', $run->html, $this->context($run));
        self::assertStringContainsString('lotgd.partial.js', $run->html);
        self::assertStringContainsString('<meta name="lotgd-page-revision" content="1">', $run->html);
        self::assertStringNotContainsString('const quickLinks', $run->html);
        self::assertStringContainsString('data-lotgd-region="content"', $run->html);
        self::assertCount(1, $run->statementsContaining(self::REVISION_CLAIM));
    }

    public function testARedirectInsideAPartialRequestIsAPageChange(): void
    {
        // Last seen long ago: forced navigation sends the player to the login.
        $run = $this->request(
            'forest.php',
            self::PARTIAL_REQUEST,
            [],
            ['partialrender' => 1],
            ['partial_render' => 1],
            ['laston' => '2000-01-01 00:00:00']
        );

        self::assertSame('index.php?op=timeout', $run->header('HX-Redirect'), $this->context($run));
        self::assertStringNotContainsString('<html', $run->html);
    }

    /**
     * @param array<string,string> $server
     * @param array<string,int>    $statementResults
     * @param array<string,mixed>  $prefs
     * @param array<string,mixed>  $settings
     * @param array<string,mixed>  $account
     */
    private function request(
        string $page,
        array $server,
        array $statementResults = [],
        array $prefs = ['partialrender' => 1],
        array $settings = ['partial_render' => 1],
        array $account = []
    ): PageOutcome {
        $row = $account + self::PLAYER + [
            'lasthit' => date('Y-m-d H:i:s'),
            'prefs' => serialize($prefs),
            'page_revision' => 0,
        ];

        return PageRunner::withRequest(
            $page,
            0,
            'GET',
            [],
            [],
            ['accounts_table' => [1 => $row]],
            $settings + ['defaultskin' => 'twig:aurora'],
            $server,
            $statementResults
        );
    }

    /**
     * The head signature a full page of $page carries, as the client would send it.
     */
    private function headOf(string $page): string
    {
        $full = $this->request($page, []);
        self::assertSame(1, preg_match('/<meta name="lotgd-head" content="([0-9a-f]+)">/', $full->html, $match), $this->context($full));

        return $match[1];
    }

    private function context(PageOutcome $run): string
    {
        return "status: " . var_export($run->status, true)
            . "\nheaders: " . implode(' | ', $run->headers)
            . "\nbody: " . substr($run->html, 0, 600);
    }
}
