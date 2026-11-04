<?php

declare(strict_types=1);

namespace Lotgd\Tests\Mail;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * Mail pages in the overlay (docs/ReactiveCore.md, Phase 6b): the content is
 * inserted with innerHTML, so no <script> in it would run. In an overlay
 * request the pages use data-lotgd-* attributes; without the request header
 * the markup is the one mail has always had.
 */
final class MailOverlayMarkupTest extends TestCase
{
    private const OVERLAY = ['HTTP_X_LOTGD_OVERLAY' => '1'];

    /**
     * @return array<string,mixed>
     */
    private static function mailbox(): array
    {
        return [
            'mail_table' => [
                ['messageid' => 5, 'msgto' => 1, 'msgfrom' => 2, 'subject' => 'older',
                 'body' => 'the older body', 'seen' => 1, 'sent' => '2026-01-01 10:00:00'],
                ['messageid' => 7, 'msgto' => 1, 'msgfrom' => 2, 'subject' => 'the subject',
                 'body' => 'the body', 'seen' => 0, 'sent' => '2026-01-02 10:00:00'],
            ],
            'accounts_table' => [
                2 => ['acctid' => 2, 'name' => 'Sender', 'login' => 'sender'],
            ],
        ];
    }

    /**
     * @param array<string,string> $get
     * @param array<string,string> $post
     * @param array<string,mixed>  $settings
     */
    private static function page(array $get, bool $overlay, array $post = [], string $method = 'GET', array $settings = [], string $sender = 'Sender'): PageOutcome
    {
        return PageRunner::withRequest(
            'mail.php',
            0,
            $method,
            $get,
            $post,
            self::mailbox(),
            $settings,
            $overlay ? self::OVERLAY : [],
            [],
            [
                '/SELECT acctid FROM .*accounts WHERE login = :login/' => [['acctid' => 2]],
                '/FROM .*mail.* LEFT JOIN .*accounts.*ORDER BY/s' => [
                    ['subject' => 'the subject', 'messageid' => 7, 'name' => $sender, 'acctid' => 2,
                     'msgfrom' => 2, 'seen' => 0, 'sent' => '2026-01-02 10:00:00'],
                    ['subject' => 'older', 'messageid' => 5, 'name' => $sender, 'acctid' => 2,
                     'msgfrom' => 2, 'seen' => 1, 'sent' => '2026-01-01 10:00:00'],
                ],
            ]
        );
    }

    public function testTheOverlayOnALegacyTemplateCarriesThePopupTitle(): void
    {
        // A legacy popup header is filled into the template, which the overlay discards.
        $run = self::page([], true, [], 'GET', ['defaultskin' => 'jade.htm']);

        self::assertNotSame('', (string) $run->header('X-Lotgd-Overlay-Title'));
    }

    public function testInboxOverlayHasNoScriptAndCarriesTheAttributes(): void
    {
        $html = self::page([], true)->html;

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('check_all', $html);
        self::assertStringNotContainsString('check_name(', $html);
        self::assertStringNotContainsString('<html', $html);
        self::assertStringContainsString('data-lotgd-check-all="msg[]"', $html);
        self::assertStringContainsString('data-lotgd-check-label="Check all"', $html);
        self::assertStringContainsString('data-lotgd-uncheck-label="Uncheck all"', $html);
        self::assertStringContainsString("<select data-lotgd-check-by id='check_name_select'>", $html);
        self::assertStringContainsString('data-lotgd-ids="[&quot;7&quot;,&quot;5&quot;]"', $html);
        self::assertStringContainsString("id='5' name='msg[]'", $html);
    }

    public function testInboxWindowKeepsItsInlineScript(): void
    {
        $html = self::page([], false)->html;

        self::assertStringContainsString('function check_all()', $html);
        self::assertStringContainsString('function check_name(who)', $html);
        self::assertStringContainsString("var container = new Array(new Array('7', '5'));", $html);
        self::assertStringContainsString("<select onchange='check_name()' id='check_name_select'>", $html);
        self::assertStringContainsString("class='button' onClick='check_all()'>", $html);
        self::assertStringNotContainsString('data-lotgd-check-all', $html);
        self::assertStringNotContainsString('data-lotgd-ids', $html);
    }

    public function testASendersNameIsTextInCheckByName(): void
    {
        foreach ([true, false] as $overlay) {
            $html = self::page([], $overlay, [], 'GET', [], "O'><img src=x onerror=alert(1)>")->html;

            self::assertStringNotContainsString('<img src=x', $html, $overlay ? 'dialog' : 'window');
            self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;</option>', $html);
        }
    }

    public function testAddressOverlayFocusesWithTheAttribute(): void
    {
        $html = self::page(['op' => 'address'], true)->html;

        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString("name='to' id='to' autofocus value=", $html);
    }

    public function testAddressWindowKeepsItsFocusScript(): void
    {
        $html = self::page(['op' => 'address'], false)->html;

        self::assertStringContainsString('document.getElementById("to").focus();</script>', $html);
        self::assertStringNotContainsString('autofocus', $html);
    }

    public function testWriteOverlayHasNoScriptAndCarriesTheAttributes(): void
    {
        $html = self::page(['op' => 'write', 'replyto' => '7'], true)->html;

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<html', $html);
        self::assertStringNotContainsString('onClick', $html);
        self::assertStringNotContainsString('onKeyUp', $html);
        self::assertStringContainsString('data-lotgd-resize="rows:+1" data-lotgd-target=\'textarea1\'', $html);
        self::assertStringContainsString('data-lotgd-resize="rows:-1"', $html);
        self::assertStringContainsString('data-lotgd-resize="cols:-1"', $html);
        self::assertStringContainsString('data-lotgd-resize="cols:+1"', $html);
        self::assertStringContainsString("data-lotgd-count='sizemsg'", $html);
        self::assertStringContainsString("<div id='sizemsg' data-lotgd-count-max='1024'", $html);
        self::assertStringContainsString('data-lotgd-count-under="', $html);
        self::assertStringContainsString('data-lotgd-count-over="', $html);
        self::assertStringContainsString("data-lotgd-su-warning='to' data-lotgd-su-target='warning'", $html);
    }

    public function testWriteWindowKeepsItsInlineScripts(): void
    {
        $html = self::page(['op' => 'write', 'replyto' => '7'], false)->html;

        self::assertStringContainsString('var superusers = ', $html);
        self::assertStringContainsString('function increase(target, value)', $html);
        self::assertStringContainsString('function cincrease(target, value)', $html);
        self::assertStringContainsString('function sizeCount(box)', $html);
        self::assertStringContainsString('function check_su_warning()', $html);
        self::assertStringContainsString("onClick=\"increase(textarea1,1);\"", $html);
        self::assertStringContainsString("onKeyUp='sizeCount(this);'", $html);
        self::assertStringNotContainsString('data-lotgd-', $html);
    }

    public function testSendAndCloseAsksTheClientToCloseTheDialog(): void
    {
        $outcome = self::page(
            ['op' => 'send'],
            true,
            ['to' => 'sender', 'subject' => 'hi', 'body' => 'text', 'sendclose' => 'Send and Close'],
            'POST'
        );

        self::assertSame('1', $outcome->header('X-Lotgd-Overlay-Close'));
        self::assertStringNotContainsString('<script', $outcome->html);
    }
}
