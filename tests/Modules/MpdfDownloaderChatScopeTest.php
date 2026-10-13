<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/mpdf_downloader.php';

/**
 * Which chat the commentary download reads.
 *
 * The download form used to post the section name, and the download read
 * whatever section was posted: a player who had been shown the form once
 * could fetch any chat, staff and other clans' ones included. The form now
 * posts a key that the session maps back to a chat it was shown for.
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

    public function testTheFormPostsAKeyAndNotTheChat(): void
    {
        mpdf_downloader_dohook('insertcomment', ['section' => 'village']);

        $form = (string) $GLOBALS['forms_output'];
        $this->assertStringNotContainsString('village', $form);
        $this->assertMatchesRegularExpression("/name='key' value='([0-9a-f]{16})'/", $form);
        preg_match("/name='key' value='([0-9a-f]{16})'/", $form, $match);
        $this->assertSame('village', mpdf_downloader_offered_section($match[1]));
    }

    public function testAChatThePlayerWasNotShownCannotBeDownloaded(): void
    {
        mpdf_downloader_offer_section('village');

        $this->assertNull(mpdf_downloader_offered_section('superuser'));
        $this->assertNull(mpdf_downloader_offered_section(substr(hash('sha256', 'superuser'), 0, 16)));
        $this->assertNull(mpdf_downloader_offered_section(''));
    }

    public function testOnlyTheMostRecentChatsAreKept(): void
    {
        $first = mpdf_downloader_offer_section('chat-0');
        for ($i = 1; $i <= 20; $i++) {
            mpdf_downloader_offer_section('chat-' . $i);
        }

        $this->assertNull(mpdf_downloader_offered_section($first));
        $this->assertSame('chat-20', mpdf_downloader_offered_section(substr(hash('sha256', 'chat-20'), 0, 16)));
        $this->assertCount(20, $GLOBALS['session']['mpdf_downloader_sections']);
    }

    public function testAChatShownAgainStaysAvailable(): void
    {
        $village = mpdf_downloader_offer_section('village');
        for ($i = 1; $i <= 19; $i++) {
            mpdf_downloader_offer_section('chat-' . $i);
        }
        mpdf_downloader_offer_section('village');
        mpdf_downloader_offer_section('chat-20');

        $this->assertSame('village', mpdf_downloader_offered_section($village));
    }
}
