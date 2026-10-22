<?php

declare(strict_types=1);

namespace Lotgd\Tests\Reactive;

use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * Game Settings shows the live features above the form, and its button turns
 * on what can run -- only with the page's token.
 */
final class ConfigurationStatusPageTest extends TestCase
{
    private const SETTINGS = ['defaultskin' => 'twig:aurora'];

    public function testTheSettingsPageShowsTheLiveFeaturesAndTheButton(): void
    {
        $run = PageRunner::withRequest('configuration.php', 0xFFFFFFFF, 'GET', [], [], [], self::SETTINGS);

        self::assertStringContainsString("<table class='lotgd-reactive-status'", $run->html);
        self::assertStringContainsString('Live duels and presence', $run->html);
        self::assertMatchesRegularExpression("~<form action='configuration.php\\?op=enablereactive' method='POST'[^>]*>\\s*<input type='hidden' name='form_csrf_token'~", $run->html);
        self::assertStringContainsString('Turn on the recommended live features', $run->html);
        self::assertLessThan(strpos($run->html, "action='configuration.php?op=save'"), strpos($run->html, 'lotgd-reactive-status'), 'Above the form.');
    }

    public function testTheButtonTurnsOnTheRecommendedSettingsAndLogsThem(): void
    {
        $run = PageRunner::withRequest('configuration.php', 0xFFFFFFFF, 'POST', ['op' => 'enablereactive'], [], [], self::SETTINGS);

        foreach (['guarded_actions', 'partial_render', 'realtime_enabled', 'grants_enabled', 'live_encounters'] as $key) {
            self::assertStringContainsString("Setting {$key} to 1", $run->html);
        }
        self::assertCount(5, preg_grep('/^INSERT INTO gamelog/', $run->statements));
    }

    public function testWithoutTheTokenNothingIsTurnedOn(): void
    {
        $run = PageRunner::withoutToken('configuration.php', 0xFFFFFFFF, ['op' => 'enablereactive'], ['x' => '1'], [], self::SETTINGS);

        self::assertSame(400, $run->status);
        // The one gamelog row is the refusal itself (SecurityLog).
        self::assertCount(1, preg_grep('/^INSERT INTO gamelog/', $run->statements));
        self::assertDoesNotMatchRegularExpression('/Setting [a-z_]+ to 1/', $run->html);
    }
}
