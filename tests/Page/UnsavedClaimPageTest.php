<?php

declare(strict_types=1);

namespace Lotgd\Tests\Page;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * A request that claims the page revision and ends without saving the
 * account -- the user editor's "last page hit" iframe, the grotto's
 * keepalive -- gives the claim back, or the page it was loaded from would be
 * left without links and its next form would end on badnav.php.
 */
final class UnsavedClaimPageTest extends TestCase
{
    private const CLAIM = 'SET page_revision = page_revision + 1';
    private const RELEASE = 'SET page_revision = :loaded, allowednavs = :navs';

    public function testTheLastPageHitIframeGivesTheClaimBack(): void
    {
        $run = $this->request('user.php', ['op' => 'lasthit', 'userid' => '1'], ['partialrender' => 1]);

        self::assertCount(1, $run->statementsContaining(self::CLAIM), $this->context($run));
        self::assertCount(1, $run->statementsContaining(self::RELEASE), 'The iframe did not save, so the editor keeps its links.');
        $claim = array_search($run->statementsContaining(self::CLAIM)[0], $run->statements, true);
        $release = array_search($run->statementsContaining(self::RELEASE)[0], $run->statements, true);
        self::assertGreaterThan($claim, $release);
    }

    public function testAPageThatSavesKeepsItsClaim(): void
    {
        $run = $this->request('forest.php', [], ['partialrender' => 1]);

        self::assertCount(1, $run->statementsContaining(self::CLAIM), $this->context($run));
        self::assertSame([], $run->statementsContaining(self::RELEASE));
    }

    public function testWithoutPartialRenderingNothingIsClaimedOrGivenBack(): void
    {
        $run = $this->request('user.php', ['op' => 'lasthit', 'userid' => '1'], ['partialrender' => 0]);

        self::assertSame([], $run->statementsContaining(self::CLAIM), $this->context($run));
        self::assertSame([], $run->statementsContaining(self::RELEASE));
    }

    /**
     * @param array<string,string> $get
     * @param array<string,mixed>  $prefs
     */
    private function request(string $page, array $get, array $prefs): PageOutcome
    {
        $row = [
            'alive' => 1, 'location' => 'Degolburg', 'level' => 1, 'gold' => 10, 'hitpoints' => 10, 'maxhitpoints' => 10,
            'lasthit' => date('Y-m-d H:i:s'), 'prefs' => serialize($prefs), 'page_revision' => 0,
        ];

        return PageRunner::withRequest(
            $page,
            0xFFFFFFFF,
            'GET',
            $get,
            [],
            ['accounts_table' => [1 => $row]],
            ['partial_render' => 1, 'defaultskin' => 'twig:aurora']
        );
    }

    private function context(PageOutcome $run): string
    {
        return 'headers: ' . implode(' | ', $run->headers) . "\nstatements: " . implode("\n", $run->statements)
            . "\nbody: " . substr($run->html, 0, 600);
    }
}
