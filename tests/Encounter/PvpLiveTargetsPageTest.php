<?php

declare(strict_types=1);

namespace Lotgd\Tests\Encounter;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * pvp.php offers the warriors who are awake a live duel: a section below the
 * sleeping targets, whose forms post to the duelling ground with its token.
 */
final class PvpLiveTargetsPageTest extends TestCase
{
    private const SETTINGS = [
        'live_encounters' => 1, 'realtime_enabled' => 1, 'grants_enabled' => 1, 'guarded_actions' => 1,
        'partial_render' => 1, 'defaultskin' => 'twig:aurora', 'pvp' => 1,
    ];

    private const ROWS = '/SELECT acctid, level, age, dragonkills, pk, experience, playerfights, alive FROM accounts WHERE acctid IN/';

    private const ONLINE = [
        '/SELECT p\.acctid, a\.name, a\.level, p\.place FROM presence p/' => [
            ['acctid' => 1, 'name' => 'Me', 'level' => 3, 'place' => 'forest'],
            ['acctid' => 2, 'name' => 'Two', 'level' => 4, 'place' => 'village'],
            ['acctid' => 3, 'name' => 'Three', 'level' => 3, 'place' => 'forest'],
        ],
    ];

    public function testAWarriorInThePeacefulPlacesCanBeChallengedBothWays(): void
    {
        $run = $this->page(self::SETTINGS, self::ONLINE + [
            self::ROWS => [
                ['acctid' => 1, 'level' => 3, 'age' => 30, 'dragonkills' => 1, 'pk' => 0, 'experience' => 2000, 'playerfights' => 3, 'alive' => 1],
                ['acctid' => 2, 'level' => 4, 'age' => 30, 'dragonkills' => 1, 'pk' => 0, 'experience' => 2000, 'playerfights' => 3, 'alive' => 1],
            ],
        ]);

        self::assertStringContainsString('Warriors about now', $run->html);
        $forms = $this->forms($run, 2);
        self::assertCount(2, $forms, 'Serious and friendly.');
        self::assertStringContainsString("name='stakes' value='serious'", $forms[0]);
        self::assertStringContainsString("name='stakes' value='friendly'", $forms[1]);
        foreach ($forms as $form) {
            self::assertStringContainsString("name='action_id'", $form);
            self::assertMatchesRegularExpression("/name='form_csrf_token' value='[^']+'/", $form);
        }
        self::assertSame([], $this->forms($run, 3), 'Not someone in the forest.');
        self::assertSame([], $this->forms($run, 1), 'Not oneself.');
        self::assertCount(1, preg_grep('/FROM accounts WHERE acctid IN/', $run->queries), 'Every row read at once, not twice per warrior.');
        self::assertSame([], preg_grep('/^SELECT level, age, dragonkills/', $run->queries), 'No read per warrior.');
    }

    public function testAProtectedWarriorIsOnlyOfferedAFriendlyDuel(): void
    {
        $run = $this->page(self::SETTINGS, self::ONLINE + [
            self::ROWS => [
                ['acctid' => 1, 'level' => 3, 'age' => 30, 'dragonkills' => 1, 'pk' => 0, 'experience' => 2000, 'playerfights' => 3, 'alive' => 1],
                ['acctid' => 2, 'level' => 4, 'age' => 1, 'dragonkills' => 0, 'pk' => 0, 'experience' => 0, 'playerfights' => 3, 'alive' => 1],
            ],
        ]);

        $forms = $this->forms($run, 2);
        self::assertCount(1, $forms);
        self::assertStringContainsString("name='stakes' value='friendly'", $forms[0]);
    }

    public function testWithoutLiveEncountersThereIsNoSection(): void
    {
        $run = $this->page(['live_encounters' => 0] + self::SETTINGS, self::ONLINE);

        self::assertStringNotContainsString('Warriors about now', $run->html);
        self::assertStringNotContainsString('encounter.php?op=challenge', $run->html);
    }

    /**
     * @return list<string>
     */
    private function forms(PageOutcome $run, int $target): array
    {
        preg_match_all("~<form action='encounter.php\\?op=challenge' method='POST'[^>]*>(?:(?!</form>).)*name='target' value='{$target}'(?:(?!</form>).)*</form>~s", $run->html, $matches);

        return $matches[0];
    }

    /**
     * @param array<string,mixed>                     $settings
     * @param array<string,list<array<string,mixed>>> $queries
     */
    private function page(array $settings, array $queries): PageOutcome
    {
        return PageRunner::withRequest('pvp.php', 0, 'GET', [], [], ['accounts_table' => [1 => [
            'alive' => 1, 'location' => 'Degolburg', 'level' => 3, 'gold' => 10, 'hitpoints' => 10, 'maxhitpoints' => 10,
            'playerfights' => 3, 'lasthit' => date('Y-m-d H:i:s'), 'prefs' => serialize(['partialrender' => 1]), 'page_revision' => 0,
        ]]], $settings, [], [], $queries);
    }
}
