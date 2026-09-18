<?php

declare(strict_types=1);

namespace Lotgd\Tests\Mail;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * A refused mail operation says so.
 *
 * The guard at the top of mail.php blanks $op and $_POST, which drops the
 * request onto the inbox. That is the right thing to do and this file does not
 * change it -- but on its own it is indistinguishable from having pressed
 * nothing at all: a message the player spent five minutes writing does not
 * arrive, and the page that swallowed it looks exactly as it did before. It
 * cost the maintainer of a live game a morning to work out what had happened,
 * and he was reading the server's raw response by hand; a player has no such
 * recourse.
 *
 * What the notice deliberately does *not* do is say which of the three states
 * the token was in -- absent, wrong, or a request that was not a POST at all.
 * That distinction is the operator's and belongs in a log.
 *
 * The refusal itself is covered by tests/Security/PageCsrf/GuardedPageCsrfTest,
 * one row per guarded operation. Repeated here for the one operation this file
 * drives, because the notice and the refusal have to hold at the same time: a
 * page that printed the notice and then went ahead and deleted the message
 * would satisfy either test alone.
 */
final class RejectedOperationTellsThePlayerTest extends TestCase
{
    /**
     * A fragment of the notice, from the middle of the sentence.
     *
     * Mid-sentence because the leading backtick code is rendered as a colour
     * span, so the first words of the notice do not sit next to the rest of it
     * in the markup.
     */
    private const NOTICE = 'security token of the form was missing or no longer valid';

    /**
     * @param array<string,string> $get
     */
    private static function post(array $get, bool $withToken): PageOutcome
    {
        $run = $withToken ? PageRunner::withToken(...) : PageRunner::withoutToken(...);

        return $run('mail.php', 0, $get, [], []);
    }

    /**
     * Deleting, because it is the operation whose effect can be measured.
     *
     * The first half of this test is the control, and it is not decoration: the
     * first version of this file asserted "no INSERT INTO mail" on a refused
     * *send*, and the accepted send does not reach an INSERT in this harness
     * either -- so the assertion was true for both requests and measured
     * nothing whatever. A deletion does write, here, with a token.
     */
    public function testARefusedDeletionIsBothAnnouncedAndPrevented(): void
    {
        $accepted = self::post(['op' => 'del', 'id' => '7'], withToken: true);

        self::assertCount(
            1,
            $accepted->statementsContaining('DELETE FROM mail'),
            'control: with a token the deletion goes through, so "no deletion" below means something'
        );

        $refused = self::post(['op' => 'del', 'id' => '7'], withToken: false);

        self::assertSame(
            [],
            $refused->statementsContaining('DELETE FROM mail'),
            'the notice must accompany a refusal, not replace it'
        );
        self::assertSame(400, $refused->status);
        self::assertStringContainsString(
            self::NOTICE,
            $refused->html,
            'a refused operation should tell the player why nothing happened'
        );
    }

    /**
     * Sending, which is the case that was reported.
     *
     * Only the rendering is asserted here. The accepted send does not reach a
     * write in this harness, so there is no control to be had for "nothing was
     * sent" -- and an assertion without one is what the test above exists to
     * avoid making twice.
     */
    public function testARefusedSendIsAnnouncedToo(): void
    {
        $composed = ['op' => 'send'];

        $refused = self::post($composed, withToken: false);

        self::assertStringContainsString(self::NOTICE, $refused->html);
        self::assertSame(400, $refused->status);

        $accepted = self::post($composed, withToken: true);

        self::assertStringNotContainsString(
            self::NOTICE,
            $accepted->html,
            'a request that was not refused must not be told that it was'
        );
        self::assertNotSame(400, $accepted->status);
    }

    /**
     * Reading the inbox is not an operation, so it is never announced as one.
     *
     * This is the case a flag set at the wrong scope gets wrong: a notice keyed
     * off the presence of the guard rather than off its verdict shows up here,
     * on the plainest request the page has.
     */
    public function testPlainlyLookingAtTheInboxSaysNothing(): void
    {
        $inbox = self::post([], withToken: true);

        self::assertStringNotContainsString(self::NOTICE, $inbox->html);
        self::assertStringContainsString(
            'mail-nav',
            $inbox->html,
            'precondition: the inbox rendered at all'
        );
    }
}
