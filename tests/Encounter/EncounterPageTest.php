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
    private function globals(): array
    {
        return ['accounts_table' => [1 => [
            'alive' => 1, 'location' => 'Degolburg', 'level' => 3, 'gold' => 10, 'hitpoints' => 10, 'maxhitpoints' => 10,
            'lasthit' => date('Y-m-d H:i:s'), 'prefs' => serialize(['partialrender' => 1]), 'page_revision' => 0,
        ]]];
    }
}
