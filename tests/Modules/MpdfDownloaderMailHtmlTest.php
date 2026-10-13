<?php

declare(strict_types=1);

namespace Lotgd\Tests\Modules;

use Lotgd\Settings;
use Lotgd\Tests\Stubs\DummySettings;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/mpdf_downloader.php';

/**
 * The HTML the mail PDF export hands to mPDF.
 *
 * A message is plain text a player wrote. It was passed to mPDF as HTML, so a
 * sender could put markup in it, and mPDF fetches the images and stylesheets
 * that markup names, from the server, when the recipient exports the message.
 *
 * @group modules
 */
final class MpdfDownloaderMailHtmlTest extends TestCase
{
    protected function setUp(): void
    {
        Settings::setInstance(new DummySettings(['charset' => 'UTF-8']));
    }

    protected function tearDown(): void
    {
        Settings::setInstance(null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(string $subject, string $body): array
    {
        return [
            'subject' => $subject,
            'body' => $body,
            'sender' => "<b>Sender</b>",
            'receiver' => 'Player',
            'sent' => '2026-10-13 10:00:00',
        ];
    }

    public function testMarkupInAMessageIsText(): void
    {
        $html = mpdf_downloader_mailHtml(self::message(
            '<img src="http://10.0.0.1/a.png">',
            'Hi <img src="http://10.0.0.1/b.png"> <link rel="stylesheet" href="http://10.0.0.1/c.css">'
        ));

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;img src=&quot;http://10.0.0.1/b.png&quot;&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;Sender&lt;/b&gt;', $html);
    }

    public function testLineBreaksAreKept(): void
    {
        $html = mpdf_downloader_mailHtml(self::message('Hello', "first\nsecond`nthird"));

        $this->assertStringContainsString("first<br>\nsecond<br>\nthird", $html);
    }

    public function testADeletedSendersMessageStillRenders(): void
    {
        $row = self::message('Hello', 'text');
        $row['sender'] = null;

        $this->assertStringContainsString('<strong>From:</strong> <br>', mpdf_downloader_mailHtml($row));
    }
}
