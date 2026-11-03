<?php

declare(strict_types=1);

namespace Lotgd\Tests\Formatting;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * Mail and clan texts close the bold and italic they open, and show the codes
 * literally where the game turns formatting off.
 *
 * Before, both rendered `b and `i unfiltered: a mail body ending in an open
 * `b bolded the rest of the page.
 */
final class FormattingPagesTest extends TestCase
{
    private const CLAN = [
        'clanid' => 5, 'clanname' => 'Fives', 'clanshort' => 'V',
        'clanmotd' => '`bMOTD-OPEN', 'clandesc' => '`iDESC-OPEN', 'motdauthor' => 0, 'descauthor' => 0,
        'customsay' => '',
    ];

    public function testAPlayersMailClosesItsBoldAndItalic(): void
    {
        $html = $this->readMail(2, ['formatting_mail' => 1])->html;

        self::assertStringContainsString('<b>BODY-OPEN <i>ITALIC</i></b>', $html);
        self::assertStringContainsString('<b>SUBJECT-OPEN</b>', $html);
    }

    public function testWithMailFormattingOffTheCodesShowAsTyped(): void
    {
        $html = $this->readMail(2, ['formatting_mail' => 0])->html;

        self::assertStringContainsString('`bBODY-OPEN `iITALIC', $html);
        self::assertStringNotContainsString('<b>BODY-OPEN', $html);
    }

    public function testTheInboxClosesASubjectsBold(): void
    {
        $inbox = [['subject' => '`bSUBJECT-OPEN', 'messageid' => 7, 'name' => 'Sender', 'acctid' => 2,
                   'msgfrom' => 2, 'seen' => 0, 'sent' => '2026-01-02 10:00:00']];
        $run = PageRunner::withRequest('mail.php', 0, 'GET', [], [], $this->mailbox(2), ['formatting_mail' => 1], [], [], ['/WHERE msgto = :msgto ORDER BY/' => $inbox]);

        self::assertStringContainsString('<b>SUBJECT-OPEN</b>', $run->html, $run->html);
    }

    public function testTheClanHallClosesItsMessageAndDescription(): void
    {
        $html = $this->clanHall(['formatting_clan' => 1])->html;

        self::assertStringContainsString('<b>MOTD-OPEN</b>', $html);
        self::assertStringContainsString('<i>DESC-OPEN</i>', $html);
    }

    public function testWithClanFormattingOffTheCodesShowAsTyped(): void
    {
        $html = $this->clanHall(['formatting_clan' => 0])->html;

        self::assertStringContainsString('`bMOTD-OPEN', $html);
        self::assertStringNotContainsString('<b>MOTD-OPEN', $html);
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function readMail(int $from, array $settings): PageOutcome
    {
        return PageRunner::withToken('mail.php', 0, ['op' => 'read', 'id' => '7'], [], $this->mailbox($from), $settings);
    }

    /**
     * @return array<string,mixed>
     */
    private function mailbox(int $from): array
    {
        return [
            'mail_table' => [
                ['messageid' => 7, 'msgto' => 1, 'msgfrom' => $from, 'subject' => '`bSUBJECT-OPEN',
                 'body' => '`bBODY-OPEN `iITALIC', 'seen' => 0, 'sent' => '2026-01-02 10:00:00'],
            ],
            'accounts_table' => [
                2 => ['acctid' => 2, 'name' => 'Sender', 'login' => 'sender'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function clanHall(array $settings): PageOutcome
    {
        return PageRunner::withRequest(
            'clan.php',
            0,
            'GET',
            [],
            [],
            [
                'accounts_table' => [1 => ['clanid' => 5, 'clanrank' => 10, 'name' => 'Member', 'lasthit' => date('Y-m-d H:i:s')]],
                '__query_cache' => ['clandata-5' => [self::CLAN]],
            ],
            $settings,
            [],
            [],
            ['/FROM clans WHERE clanid=\'5\'/' => [self::CLAN], '/FROM commentary/' => []]
        );
    }
}
