<?php

declare(strict_types=1);

namespace Lotgd\Tests\Nav;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The navigation the pages render: the way back first, under "Navigation",
 * and what the page offers below it under a heading of its own.
 *
 * A section is placed where it is first opened and keeps that place, and a
 * link added before any heading lands in an unnamed section at the very top.
 * So the order a player sees is decided by the order the page opens its
 * sections, and that is easy to get wrong without noticing: the inn listed
 * "Give him 10 gold" in the same section as the ways out, and with the
 * preference that sorts the links of a section it came first.
 *
 * The pages are run, not read: whether a section comes first depends on every
 * file the request includes on the way, and on the branch it takes.
 *
 * @group nav
 */
final class PageNavigationOrderTest extends TestCase
{
    private const PLAYER = [
        'alive' => 1,
        'location' => 'Degolburg',
        'level' => 1,
        'gold' => 10,
        'hitpoints' => 10,
        'maxhitpoints' => 10,
    ];

    /**
     * One row per page and branch: the request, and the sections expected at
     * the top of its navigation, in order, each with exactly its links.
     *
     * @return array<string, array{0: string, 1: int, 2: array<string,string>, 3: array<string,mixed>, 4: array<string,mixed>, 5: bool, 6: array<string, list<string>>}>
     */
    public static function pages(): array
    {
        $dead = ['alive' => 0, 'hitpoints' => 0];

        return [
            'inn, asking for a room' => [
                'inn.php', 0, ['op' => 'room'], [], [], true,
                ['Navigation' => ['Return to Degolburg', 'Return to the Inn'], 'Actions' => ['Give him 10 gold']],
            ],
            'forest, at the dragon\'s cave' => [
                'forest.php', 0, ['op' => 'dragon'], [], [], true,
                ['Navigation' => ['Run away like a baby'], 'Actions' => ['Enter the cave']],
            ],
            'bartender, offering a bribe' => [
                'inn.php', 0, ['op' => 'bartender', 'act' => 'bribe'], [], [], true,
                [
                    'Navigation' => ['Return to Degolburg', 'Return to the Inn'],
                    'Cedrik' => ['1 gem', '2 gems', '3 gems', '10 gold', '50 gold', '100 gold'],
                ],
            ],
            'healer, wounded' => [
                'healer.php', 0, [], ['hitpoints' => 5], [], true,
                ['Navigation' => ['Back to the Forest', 'Return to Degolburg']],
            ],
            'bank' => [
                'bank.php', 0, [], [], [], true,
                ['Navigation' => ['Return to Degolburg'], 'Money' => ['Withdraw', 'Deposit', 'Take out a Loan']],
            ],
            'warrior list for an attack' => [
                'pvp.php', 0, [], ['playerfights' => 3], [], true,
                ['Navigation' => ['Return to Degolburg'], 'Actions' => ['Refresh List of Warriors']],
            ],
            'mercenary camp' => [
                'mercenarycamp.php', 0, [], [], [], true,
                ['Navigation' => ['Return to Degolburg']],
            ],
            'clan registrar, listing the clans' => [
                'clan.php', 0, ['op' => 'list'], [], [], true,
                ['Navigation' => ['Return to Degolburg', 'Return to the Lobby'], 'Clan Options' => ['List Clans']],
            ],
            'about' => [
                'about.php', 0, [], [], [], true,
                ['Navigation' => ['Return to the news']],
            ],
            'graveyard' => [
                'graveyard.php', 0, [], $dead + ['gravefights' => 5], [], true,
                ['Navigation' => ['Return to the Shades'], 'Torment' => ['Look for Something to Torment']],
            ],
            'mausoleum' => [
                'graveyard.php', 0, ['op' => 'enter'], $dead, [], true,
                ['Navigation' => ['Land of the Shades', 'Return to the Graveyard']],
            ],
            'preferences, deleting the character without the form\'s token' => [
                'prefs.php', 0, ['op' => 'suicide'], [], ['selfdelete' => 1], false,
                ['Navigation' => ['Return to Degolburg', 'Return to the Prefs']],
            ],
            'companion editor, adding one' => [
                'companions.php', SU_EDIT_MOUNTS, ['op' => 'add'], [], [], true,
                [
                    'Navigation' => ['Return to the Grotto', 'Return to the Mundane', 'Companion Editor Home'],
                    'Companion Editor' => ['Add a companion'],
                ],
            ],
            'game settings, SMTP test without an admin address' => [
                'configuration.php', SU_EDIT_CONFIG, ['op' => 'testsmtp'], [], [], true,
                ['Navigation' => ['Return to the Grotto', 'Return to the Mundane', 'Back to settings list']],
            ],
        ];
    }

    /**
     * @param array<string,string>        $get
     * @param array<string,mixed>         $account
     * @param array<string,mixed>         $settings
     * @param array<string, list<string>> $expected
     */
    #[DataProvider('pages')]
    public function testTheWayBackComesFirst(
        string $page,
        int $superuser,
        array $get,
        array $account,
        array $settings,
        bool $withToken,
        array $expected
    ): void {
        $globals = ['accounts_table' => [1 => $account + self::PLAYER + ['lasthit' => date('Y-m-d H:i:s')]]];
        $run = $withToken
            ? PageRunner::withToken($page, $superuser, $get, [], $globals, $settings)
            : PageRunner::withoutToken($page, $superuser, $get, [], $globals, $settings);

        $sections = self::sections($run);

        $this->assertSame(
            array_keys($expected),
            array_slice(array_keys($sections), 0, count($expected)),
            "The sections at the top of $page"
        );
        foreach ($expected as $header => $links) {
            $this->assertSame($links, $sections[$header], "The links under \"$header\" on $page");
        }
    }

    /**
     * The rendered navigation as heading => links, in the order shown. Links
     * before the first heading are listed under ''.
     *
     * @return array<string, list<string>>
     */
    private static function sections(PageOutcome $run): array
    {
        $start = strpos($run->html, 'class="nav-container"');
        self::assertNotFalse($start, 'The page rendered no navigation: ' . substr(strip_tags($run->html), 0, 500));
        $end = strpos($run->html, '</div>', $start);
        $container = substr($run->html, $start, $end === false ? null : $end - $start);

        preg_match_all(
            '#<span class="navhead">(.*?)</span>|<a [^>]*class="nav"[^>]*>(.*?)</a>#s',
            $container,
            $matches,
            PREG_SET_ORDER
        );

        $sections = [];
        $current = '';
        foreach ($matches as $match) {
            if (($match[2] ?? '') === '') {
                $current = trim(str_replace('—', '', self::text($match[1])));
                $sections[$current] ??= [];
                continue;
            }
            // The access key is shown in front when the label does not contain it.
            $sections[$current][] = (string) preg_replace('/^\(.\) /', '', self::text($match[2]));
        }

        return $sections;
    }

    private static function text(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
