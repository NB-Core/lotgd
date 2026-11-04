<?php

declare(strict_types=1);

namespace Lotgd\Tests\Reactive;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * Game Settings in the table of the preferences: one table with a search
 * field and tabs (Forms::showFormTabbed(), built by lotgd.showform.js), and
 * the tab a form was saved on comes back without being stored as a setting.
 */
final class ConfigurationTabbedFormPageTest extends TestCase
{
    private const SETTINGS = ['defaultskin' => 'twig:aurora'];

    public function testTheStandardSettingsAreOneSearchableTableWithoutAScript(): void
    {
        $run = PageRunner::withRequest('configuration.php', 0xFFFFFFFF, 'GET', [], [], [], self::SETTINGS);

        $form = self::showForm($run);
        self::assertContains('Game Setup', $form['sections']);
        self::assertSame(0, $form['start']);
        self::assertStringContainsString("<table id='showFormTable{$form['id']}' role='tabpanel'", $run->html);
        self::assertStringNotContainsString('prepare_form(', $run->html);
    }

    public function testTheExtendedSettingsAreOneSearchableTableToo(): void
    {
        $run = PageRunner::withRequest('configuration.php', 0xFFFFFFFF, 'GET', ['settings' => 'extended'], [], [], self::SETTINGS);

        self::assertNotSame([], self::showForm($run)['sections']);
        self::assertStringNotContainsString('prepare_form(', $run->html);
    }

    public function testSavingKeepsTheTabAndNeverStoresItAsASetting(): void
    {
        $run = PageRunner::withRequest(
            'configuration.php',
            0xFFFFFFFF,
            'POST',
            ['op' => 'save'],
            ['showFormTabIndex' => ['10001' => '3'], 'servername' => 'Test Realm'],
            [],
            self::SETTINGS
        );

        self::assertStringContainsString('Setting servername to Test Realm', $run->html);
        self::assertStringNotContainsString('showFormTabIndex to', $run->html);
        self::assertSame([], preg_grep('/showFormTabIndex/', $run->statements), 'The tab is not a setting.');
        self::assertSame(3, self::showForm($run)['start']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function showForm(PageOutcome $run): array
    {
        self::assertSame(1, preg_match("/data-lotgd-showform='([^']*)'/", $run->html, $match), substr($run->html, 0, 1500));

        return json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
    }
}
