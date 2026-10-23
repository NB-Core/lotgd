<?php

declare(strict_types=1);

namespace Lotgd\Tests\Page;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fights swap in place (docs/ReactiveCore.md, Phase 2): every round is a GET
 * link the page granted, the fight lives in the account, and a partial round
 * claims the page revision like any other page.
 */
final class PartialFightPageTest extends TestCase
{
    private const PARTIAL_REQUEST = ['HTTP_HX_REQUEST' => 'true'];
    private const REVISION_CLAIM = 'SET page_revision = page_revision + 1';

    public function testARoundAnswersWithRegionsAndOffersTheNextOne(): void
    {
        $run = $this->fight('forest.php', ['op' => 'fight'], self::enemy(100000, 0));

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertCount(1, $run->statementsContaining(self::REVISION_CLAIM));
        self::assertStringNotContainsString('<script', $run->html);
        self::assertStringContainsString('forest.php?op=fight', $run->html);
        self::assertStringContainsString('forest.php?op=run', $run->html);
        self::assertStringContainsString('forest.php?op=fight&amp;auto=five', $run->html);
    }

    public function testAnAutoFightWonInPlaceLeadsBackIntoTheForest(): void
    {
        $run = $this->fight('forest.php', ['op' => 'fight', 'auto' => 'full'], self::enemy(1, 0));

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertStringContainsString('forest.php?op=search', $run->html, 'The forest again, after the victory.');
        self::assertStringNotContainsString('forest.php?op=run', $run->html);
    }

    public function testABuffRunsWithTheRoundAndWearsOff(): void
    {
        $buffs = ['test' => [
            'name' => 'Test buff', 'rounds' => 1, 'atkmod' => 1, 'schema' => 'test',
            'roundmsg' => 'The test buff glows.', 'wearoff' => 'The test buff fades.',
        ]];
        $run = $this->fight('forest.php', ['op' => 'fight'], self::enemy(100000, 0), ['bufflist' => serialize($buffs)]);

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertStringContainsString('The test buff glows.', $run->html);
        self::assertStringContainsString('The test buff fades.', $run->html, 'Its last round is counted in the swapped round.');
    }

    public function testADefeatInPlaceEndsInTheNews(): void
    {
        $run = $this->fight('forest.php', ['op' => 'fight', 'auto' => 'full'], self::enemy(100000, 100000), ['hitpoints' => 1]);

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertStringContainsString('news.php', $run->html);
    }

    public function testASearchStartsTheFightInPlace(): void
    {
        $run = $this->fight('forest.php', ['op' => 'search'], ['enemies' => [], 'options' => []]);

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertStringContainsString('Test Wolf', $run->html, 'The creature the search found.');
        self::assertStringContainsString('forest.php?op=fight', $run->html);
    }

    public function testAPvpAttackIsAnsweredInPlace(): void
    {
        $run = $this->fight('pvp.php', ['act' => 'attack', 'name' => 'sleeper'], ['enemies' => [], 'options' => []]);

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertCount(1, $run->statementsContaining(self::REVISION_CLAIM));
        self::assertStringContainsString('Sleeper', $run->html);
        self::assertMatchesRegularExpression('/pvp\.php\?[^"\']*op=fight/', $run->html);
    }

    /**
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function fightPages(): iterable
    {
        yield 'the graveyard' => ['graveyard.php', 'graveyard'];
        yield 'the master' => ['train.php', 'train'];
        yield 'the dragon' => ['dragon.php', 'dragon'];
        yield 'a pvp fight' => ['pvp.php', 'pvp'];
    }

    #[DataProvider('fightPages')]
    public function testEveryCoreFightSwapsItsRoundsIn(string $page, string $type): void
    {
        $account = $page === 'graveyard.php' ? ['alive' => 0, 'hitpoints' => 0, 'gravefights' => 5, 'soulpoints' => 100] : [];
        $run = $this->fight($page, ['op' => 'fight'], self::enemy(100000, 0, $type), $account);

        self::assertSame('1', $run->header('X-Lotgd-Partial'), self::context($run));
        self::assertCount(1, $run->statementsContaining(self::REVISION_CLAIM));
        self::assertMatchesRegularExpression('/' . preg_quote($page, '/') . '\?[^"\']*op=fight/', $run->html);
    }

    /**
     * @return array<string,mixed>
     */
    private static function enemy(int $health, int $attack, string $type = 'forest'): array
    {
        return [
            'enemies' => [[
                'creaturename' => 'Test Beast', 'creaturelevel' => 1, 'creatureweapon' => 'Claws',
                'creatureattack' => $attack, 'creaturedefense' => 0, 'creaturehealth' => $health,
                'creaturemaxhealth' => $health, 'creaturegold' => 5, 'creatureexp' => 5, 'diddamage' => 0,
                'playerstarthp' => 10, 'type' => $type,
            ]],
            'options' => ['type' => $type],
        ];
    }

    /**
     * @param array<string,string> $get
     * @param array<string,mixed>  $attackstack
     * @param array<string,mixed>  $account
     */
    private function fight(string $page, array $get, array $attackstack, array $account = []): PageOutcome
    {
        $settings = ['partial_render' => 1, 'defaultskin' => 'twig:aurora', 'autofight' => 1, 'show_error_details' => 1];
        $row = $account + [
            'alive' => 1, 'location' => 'Degolburg', 'level' => 1, 'gold' => 10, 'hitpoints' => 10,
            'maxhitpoints' => 10, 'attack' => 1000, 'defense' => 1000, 'turns' => 10, 'playerfights' => 3,
            'lasthit' => date('Y-m-d H:i:s'), 'prefs' => serialize(['partialrender' => 1]), 'page_revision' => 0,
            'badguy' => serialize($attackstack),
        ];
        $globals = ['accounts_table' => [1 => $row]];
        $queries = [
            '/SELECT \* FROM .*creatures WHERE creaturelevel/' => [[
                'creatureid' => 1, 'creaturename' => 'Test Wolf', 'creaturelevel' => 1, 'creatureweapon' => 'Teeth',
                'creaturelose' => 'The wolf runs.', 'creaturewin' => 'The wolf howls.', 'creaturegold' => 5, 'creatureexp' => 5,
                'creaturehealth' => 100000, 'creatureattack' => 0, 'creaturedefense' => 0, 'creatureaiscript' => '',
                'forest' => 1, 'graveyard' => 0, 'oldcreatureexp' => 0, 'creaturecategory' => '',
            ]],
            '/SELECT name AS creaturename/' => [[
                'creaturename' => 'Sleeper', 'creaturelevel' => 1, 'creatureweapon' => 'Fists', 'dragonkills' => 0,
                'creaturegold' => 5, 'creatureexp' => 5, 'creaturehealth' => 100000, 'creatureattack' => 0,
                'creaturedefense' => 0, 'loggedin' => 0, 'location' => 'Degolburg', 'laston' => '2000-01-01 00:00:00',
                'alive' => 1, 'acctid' => 2, 'pvpflag' => '2000-01-01 00:00:00', 'boughtroomtoday' => 0, 'race' => 'Human',
            ]],
            '/SELECT deathmessage,taunt FROM /' => [['deathmessage' => '{goodguyname} met {badguyname} {where}.', 'taunt' => 1]],
            '/^SELECT taunt FROM /' => [['taunt' => '`5"Ha!", says {badguyname}.']],
            '/SELECT max\(creaturelevel\) as level FROM .*masters/' => [['level' => 1]],
            '/SELECT \* FROM .*masters WHERE/' => [[
                'creatureid' => 1, 'creaturename' => 'Mireraband', 'creaturelevel' => 1, 'creatureweapon' => 'Small Dagger',
                'creaturewin' => 'Well fought.', 'creaturelose' => 'You lose.', 'creaturegold' => 0, 'creatureexp' => 0,
                'creaturehealth' => 100000, 'creatureattack' => 0, 'creaturedefense' => 0,
            ]],
        ];
        $full = PageRunner::withRequest($page, 0, 'GET', $get, [], $globals, $settings, [], [], $queries);
        self::assertSame(1, preg_match('/<meta name="lotgd-head" content="([0-9a-f]+)">/', $full->html, $match), self::context($full));

        return PageRunner::withRequest($page, 0, 'GET', $get, [], $globals, $settings, self::PARTIAL_REQUEST + ['HTTP_X_LOTGD_HEAD' => $match[1]], [], $queries);
    }

    private static function context(PageOutcome $run): string
    {
        return 'headers: ' . implode(' | ', $run->headers) . "\nbody: " . substr($run->html, 0, 3000);
    }
}
