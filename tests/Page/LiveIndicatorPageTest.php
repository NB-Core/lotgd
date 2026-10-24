<?php

declare(strict_types=1);

namespace Lotgd\Tests\Page;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * The dot beside the mail link (PageParts::liveIndicator()): whether the
 * player's pages change in place, carried in the mail slot so every swap
 * brings it along.
 */
final class LiveIndicatorPageTest extends TestCase
{
    private const SETTINGS = ['partial_render' => 1, 'defaultskin' => 'twig:aurora'];

    public function testOnWhenPagesChangeInPlace(): void
    {
        $run = $this->page(['partialrender' => 1]);

        self::assertMatchesRegularExpression("/<span class='lotgd-live lotgd-live--on' role='img' title='Pages change in place'/", $run->html);
        self::assertStringContainsString('/assets/css/lotgd.partial.css', $run->html);
    }

    public function testOffWithAHintWhenThePlayerTurnedItOff(): void
    {
        $run = $this->page(['partialrender' => 0]);

        self::assertStringContainsString("class='lotgd-live lotgd-live--off'", $run->html);
        self::assertStringContainsString('in your preferences', $run->html);
    }

    public function testOffOnASkinWithoutRegions(): void
    {
        // Every bundled skin declares regions; a copy of jade that does not.
        $name = 'zz-live-indicator-' . bin2hex(random_bytes(4)) . '.htm';
        $skin = dirname(__DIR__, 2) . '/templates/' . $name;
        try {
            self::assertNotFalse(file_put_contents($skin, self::withoutRegions((string) file_get_contents(dirname(__DIR__, 2) . '/templates/jade.htm'))));
            $run = $this->page(['partialrender' => 1], ['defaultskin' => $name]);
        } finally {
            if (is_file($skin)) {
                unlink($skin);
            }
        }

        self::assertStringContainsString("class='lotgd-live lotgd-live--off'", $run->html, substr($run->html, 0, 800));
        self::assertStringContainsString('on this skin', $run->html);
    }

    public function testNothingWhereTheGameDoesNotChangePagesInPlace(): void
    {
        $run = $this->page(['partialrender' => 1], ['partial_render' => 0]);

        self::assertStringNotContainsString('lotgd-live', $run->html);
    }

    public function testASwappedPageCarriesTheDotInTheMailSlot(): void
    {
        $globals = $this->globals(['partialrender' => 1]);
        $full = PageRunner::withRequest('forest.php', 0, 'GET', [], [], $globals, self::SETTINGS);
        self::assertSame(1, preg_match('/<meta name="lotgd-head" content="([0-9a-f]+)">/', $full->html, $match));

        $run = PageRunner::withRequest('forest.php', 0, 'GET', [], [], $globals, self::SETTINGS, ['HTTP_HX_REQUEST' => 'true', 'HTTP_X_LOTGD_HEAD' => $match[1]]);

        self::assertSame('1', $run->header('X-Lotgd-Partial'));
        self::assertMatchesRegularExpression('/data-lotgd-slot=&quot;mail&quot;\]">.*lotgd-live--on/s', $run->html);
    }

    /**
     * The template with `regions` taken out of its `<!--!config-->` section,
     * cut as Template::loadTemplate() cuts it.
     */
    private static function withoutRegions(string $template): string
    {
        $parts = explode('<!--!config-->', $template, 2);
        self::assertCount(2, $parts, 'jade has a config section');
        [$section, $rest] = array_pad(explode('<!--!', $parts[1], 2), 2, null);
        $config = json_decode(trim($section), true);
        self::assertIsArray($config);
        self::assertTrue($config['regions'] ?? false, 'jade declares regions, so the copy shows what taking them out does');
        unset($config['regions']);

        return $parts[0] . '<!--!config-->' . json_encode($config) . "\n" . ($rest === null ? '' : '<!--!' . $rest);
    }

    /**
     * @param array<string,mixed> $prefs
     * @param array<string,mixed> $settings
     */
    private function page(array $prefs, array $settings = []): PageOutcome
    {
        return PageRunner::withRequest('forest.php', 0, 'GET', [], [], $this->globals($prefs), $settings + self::SETTINGS);
    }

    /**
     * @param array<string,mixed> $prefs
     * @return array<string,mixed>
     */
    private function globals(array $prefs): array
    {
        return ['accounts_table' => [1 => [
            'alive' => 1, 'location' => 'Degolburg', 'level' => 1, 'gold' => 10, 'hitpoints' => 10, 'maxhitpoints' => 10,
            'lasthit' => date('Y-m-d H:i:s'), 'prefs' => serialize($prefs), 'page_revision' => 0,
        ]]];
    }
}
