<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules;

use Lotgd\Security\Csrf;
use Lotgd\Settings;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/mpdf_downloader.php';

/**
 * Which chat the commentary download reads.
 *
 * The download form used to post the section name, and the download read
 * whatever section was posted: a player who had been shown the form once
 * could fetch any chat, staff and other clans' ones included. The form now
 * posts to a link carrying a key that the session maps back to a chat it was
 * shown for.
 *
 * @group modules
 */
final class MpdfDownloaderChatScopeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['session'] = ['user' => ['acctid' => 1]];
        $GLOBALS['forms_output'] = '';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['session'], $GLOBALS['forms_output']);
    }

    /**
     * The key is part of the link the form posts to, so forced navigation
     * accepts it only from the page that showed this chat. A key left in the
     * session from an earlier page, for a chat the player has since lost
     * access to, is a link that page no longer offers.
     */
    public function testTheFormPostsToALinkNamingAKeyAndNotTheChat(): void
    {
        mpdf_downloader_dohook('insertcomment', ['section' => 'village']);

        $form = (string) $GLOBALS['forms_output'];
        $pattern = "/<form action='runmodule\\.php\\?module=mpdf_downloader&op=grab&key=([0-9a-f]{16})' method='POST'/";
        $this->assertStringNotContainsString('village', $form);
        $this->assertStringNotContainsString("name='key'", $form);
        $this->assertStringContainsString("name='csrf_token'", $form, 'only the posted form may use up the key');
        $this->assertMatchesRegularExpression($pattern, $form);
        preg_match($pattern, $form, $match);
        $this->assertSame('village', mpdf_downloader_take_section($match[1]));
    }

    /**
     * The download ends the request before the player's links are stored, so
     * the link that carried the key stays allowed until the next page. The
     * key itself is good once: the same link cannot be used again later, by
     * a player who has lost access to the chat in the meantime.
     */
    public function testAKeyIsGoodForOneDownload(): void
    {
        $key = mpdf_downloader_offer_section('clan-3');

        $this->assertSame('clan-3', mpdf_downloader_take_section($key));
        $this->assertNull(mpdf_downloader_take_section($key));

        $this->assertSame($key, mpdf_downloader_offer_section('clan-3'), 'showing the chat again offers it again');
        $this->assertSame('clan-3', mpdf_downloader_take_section($key));
    }

    /**
     * The key in the link can be worked out from the chat's name, and forced
     * navigation does not look at the method. A link another site makes the
     * browser follow must not use up the player's download.
     */
    #[RunInSeparateProcess]
    public function testOnlyThePostedFormWithItsTokenUsesUpTheKey(): void
    {
        if (!function_exists('httpget')) {
            eval('function httpget($name) { return \\Lotgd\\Http::get($name); }');
        }
        Settings::setInstance(new DummySettings([]));
        $key = mpdf_downloader_offer_section('clan-3');
        Csrf::seed(mpdf_downloader_scope(), 'form-token');
        $_GET = ['op' => 'grab', 'key' => $key];

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $this->assertNull(mpdf_downloader_requested_section(), 'a followed link');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertNull(mpdf_downloader_requested_section(), 'a post without the token');

        $_POST = [Csrf::FIELD => 'form-token'];
        $this->assertSame('clan-3', mpdf_downloader_requested_section(), 'the key was still there');
        $this->assertNull(mpdf_downloader_requested_section(), 'and is used up now');
    }

    public function testAChatThePlayerWasNotShownCannotBeDownloaded(): void
    {
        mpdf_downloader_offer_section('village');

        $this->assertNull(mpdf_downloader_take_section('superuser'));
        $this->assertNull(mpdf_downloader_take_section(substr(hash('sha256', 'superuser'), 0, 16)));
        $this->assertNull(mpdf_downloader_take_section(''));
    }

    public function testOnlyTheMostRecentChatsAreKept(): void
    {
        $first = mpdf_downloader_offer_section('chat-0');
        for ($i = 1; $i <= 20; $i++) {
            mpdf_downloader_offer_section('chat-' . $i);
        }

        $this->assertCount(20, $GLOBALS['session']['mpdf_downloader_sections']);
        $this->assertNull(mpdf_downloader_take_section($first));
        $this->assertSame('chat-20', mpdf_downloader_take_section(substr(hash('sha256', 'chat-20'), 0, 16)));
    }

    public function testAChatShownAgainStaysAvailable(): void
    {
        $village = mpdf_downloader_offer_section('village');
        for ($i = 1; $i <= 19; $i++) {
            mpdf_downloader_offer_section('chat-' . $i);
        }
        mpdf_downloader_offer_section('village');
        mpdf_downloader_offer_section('chat-20');

        $this->assertSame('village', mpdf_downloader_take_section($village));
    }
}
