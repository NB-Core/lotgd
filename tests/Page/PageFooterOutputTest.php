<?php

declare(strict_types=1);

namespace Lotgd\Tests\Page;

use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The page end, run for real, after it was split into building a PageState
 * and rendering it.
 *
 * The split must not change what a player gets: the document, its navigation
 * (which is also what lands in allowednavs), the stats and the quick keys for
 * a full page; title and body without navigation for a popup. Both template
 * kinds are run, because the legacy .htm path and the Twig path assemble the
 * state differently. The byte-for-byte comparison against the code before the
 * split was made once, when the split was introduced; this keeps the shape.
 */
final class PageFooterOutputTest extends TestCase
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
     * @return array<string,array{string}>
     */
    public static function skins(): array
    {
        return [
            'twig' => ['twig:aurora'],
            'legacy' => ['jade.htm'],
        ];
    }

    #[DataProvider('skins')]
    public function testAFullPageCarriesNavigationStatsAndQuickKeys(string $skin): void
    {
        $html = $this->render('bank.php', $skin);

        self::assertStringContainsString('<title>Ye Olde Bank', $html);
        foreach (['withdraw', 'deposit', 'borrow'] as $op) {
            self::assertStringContainsString("bank.php?op=$op", $html, "The bank's navigation is missing $op.");
        }
        self::assertStringContainsString('Character Info', $html, 'The stats did not render.');
        self::assertStringContainsString('const quickLinks', $html, 'The quick-key handler did not render.');
        self::assertSame(1, substr_count(strtolower($html), '</html>'), 'The document must be rendered exactly once.');
    }

    #[DataProvider('skins')]
    public function testAPopupCarriesItsBodyButNoNavigation(string $skin): void
    {
        $html = $this->render('petition.php', $skin);

        self::assertStringContainsString('<title>Petition for Help', $html);
        self::assertStringNotContainsString('const quickLinks', $html);
        self::assertStringNotContainsString('Character Info', $html);
        self::assertSame(1, substr_count(strtolower($html), '</html>'));
    }

    private function render(string $page, string $skin): string
    {
        $run = PageRunner::withToken(
            $page,
            0,
            [],
            [],
            ['accounts_table' => [1 => self::PLAYER + ['lasthit' => date('Y-m-d H:i:s')]]],
            ['defaultskin' => $skin]
        );

        return $run->html;
    }
}
