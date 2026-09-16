<?php

declare(strict_types=1);

namespace Lotgd\Tests\Mail;

use Lotgd\Tests\Security\PageCsrf\PageOutcome;
use Lotgd\Tests\Security\PageCsrf\PageRunner;
use PHPUnit\Framework\TestCase;

/**
 * The controls around a mail message, asked by rendering them.
 *
 * pages/mail/case_read.php had no test of any kind, and what it emitted was
 * three different HTML elements wearing one class: an `<a>`, a `<button>` from
 * Forms::postButton(), and a hand-written `<input type='submit'>`. The class
 * was `motd`, which every theme in this repository defines only as `a.motd` --
 * anchor-scoped. So the rule styled the links and did nothing whatever for the
 * buttons beside them, and Delete, Mark Unread and Report to Admin rendered as
 * bare browser chrome.
 *
 * That is why these assertions are about the markup rather than about a screen
 * shot or a class name in a stylesheet. The defect was not that the CSS was
 * wrong -- the CSS was fine -- but that the markup asked it a question it could
 * not answer. A test of the markup is a test of exactly the thing that broke.
 *
 * The page is executed rather than read: this suite has spent a long time
 * replacing assertions that grep the source, and "the file contains the string
 * mail-nav" would pass just as well if the branch emitting it were
 * unreachable.
 *
 * @see PageRunner for what it takes to make one of these pages execute at all.
 */
final class MailReadActionBarTest extends TestCase
{
    /**
     * The class pair every control in these bars is supposed to wear.
     *
     * `button` is the half that does the work: it is the one class every theme
     * defines unqualified, so it lands on an `<a>`, a `<button>` and a `<span>`
     * alike.
     */
    private const ACTION_CLASS = "class='button mail-nav__link'";

    /**
     * A mailbox with a message either side of the one under test.
     *
     * Three messages rather than one, so "previous" and "next" are answerable
     * in both directions -- and so the two edges, a message with nothing before
     * it and one with nothing after it, are reachable without a second fixture.
     *
     * @return array<string,mixed>
     */
    private static function mailbox(int $msgfrom = 2): array
    {
        return [
            'mail_table' => [
                ['messageid' => 5, 'msgto' => 1, 'msgfrom' => $msgfrom, 'subject' => 'older',
                 'body' => 'the older body', 'seen' => 1, 'sent' => '2026-01-01 10:00:00'],
                ['messageid' => 7, 'msgto' => 1, 'msgfrom' => $msgfrom, 'subject' => 'the subject',
                 'body' => 'the body', 'seen' => 0, 'sent' => '2026-01-02 10:00:00'],
                ['messageid' => 9, 'msgto' => 1, 'msgfrom' => $msgfrom, 'subject' => 'newer',
                 'body' => 'the newer body', 'seen' => 1, 'sent' => '2026-01-03 10:00:00'],
            ],
            'accounts_table' => [
                2 => ['acctid' => 2, 'name' => 'Sender', 'login' => 'sender'],
            ],
        ];
    }

    private static function read(int $id, int $msgfrom = 2): PageOutcome
    {
        return PageRunner::withToken(
            'mail.php',
            0,
            ['op' => 'read', 'id' => (string) $id],
            [],
            self::mailbox($msgfrom)
        );
    }

    /**
     * The bars the page under test renders, in order.
     *
     * mail.php emits a bar of its own above every mail page -- the Inbox/Write
     * tab strip that this change borrowed its markup from -- so the first one
     * is not ours. Dropping it here rather than in each assertion keeps the
     * count in those assertions meaning what it says.
     *
     * @return list<string>
     */
    private static function actionBars(PageOutcome $outcome): array
    {
        self::assertMatchesRegularExpression(
            '/mail-nav/',
            $outcome->html,
            'The mail page rendered no navigation bar at all, so nothing below this can be concluded'
        );

        preg_match_all("/<div class='mail-nav'>.*?<\/div>/s", $outcome->html, $matches);

        return array_slice($matches[0], 1);
    }

    /**
     * The tab strip above every mail page, which mail.php owns.
     *
     * Asserted here because this is the only place it is rendered by the real
     * page. Its entries come from the `mailfunctions` hook, and it is built
     * through the same Forms::actionBar() as the bars below -- so a module
     * contributing a malformed pair loses its own tab instead of the page,
     * which is what it cost before that routing (mail.php is strict_types, and
     * a direct typed call turned a stringifiable label into a TypeError).
     */
    public function testTheTabStripStillOffersInboxAndWrite(): void
    {
        $outcome = self::read(7);

        preg_match_all("/<div class='mail-nav'>.*?<\/div>/s", $outcome->html, $matches);
        $tabs = $matches[0][0] ?? '';

        self::assertStringContainsString("<a href='mail.php' class='button mail-nav__link'>Inbox</a>", $tabs);
        self::assertStringContainsString("<a href='mail.php?op=address' class='button mail-nav__link'>Write</a>", $tabs);
        self::assertStringNotContainsString('<form', $tabs, 'the tab strip navigates; it does not act');
    }

    public function testBothBarsAreRenderedAndNeitherIsATable(): void
    {
        $bars = self::actionBars(self::read(7));

        self::assertCount(2, $bars, 'the read view should render one bar above the message and one below');

        foreach ($bars as $bar) {
            // The two tables this replaced were invalid in different ways --
            // one wrote cellspacing into a style attribute, where it is not a
            // property at all; the other used the presentational attributes --
            // and the lower one was a three-row grid rather than the single
            // row it appeared to be.
            self::assertStringNotContainsString('<table', $bar);
            self::assertStringNotContainsString('nowrap', $bar);
        }
    }

    public function testEveryControlWearsTheClassThatWorksOnAnyElement(): void
    {
        foreach (self::actionBars(self::read(7)) as $bar) {
            preg_match_all('/<(a|button|span|input)\b[^>]*>/', $bar, $controls);

            self::assertNotEmpty($controls[0], 'a bar with no controls in it is not a bar');

            foreach ($controls[0] as $control) {
                if (str_starts_with($control, '<input')) {
                    // The hidden fields postButton carries; not controls.
                    continue;
                }

                self::assertStringContainsString(self::ACTION_CLASS, $control);
            }
        }
    }

    /**
     * The regression this whole change is about.
     *
     * `motd` on an anchor is merely redundant here; on anything else it is the
     * defect, because no theme defines a selector that can match it. Asserting
     * the absence on non-anchors rather than everywhere says which of the two
     * this test is guarding.
     */
    public function testNothingButAnAnchorIsStyledAsMotd(): void
    {
        foreach (self::actionBars(self::read(7)) as $bar) {
            preg_match_all('/<(?!a\b)[a-z]+\b[^>]*>/i', $bar, $controls);

            foreach ($controls[0] as $control) {
                self::assertStringNotContainsString(
                    'motd',
                    $control,
                    "motd is defined only as a.motd in every theme, so it styles nothing here: $control"
                );
            }
        }
    }

    public function testTheTopBarNavigatesAndTheBottomBarActs(): void
    {
        [$top, $bottom] = self::actionBars(self::read(7));

        foreach (['Previous', 'Next', 'Reply', 'Forward'] as $label) {
            self::assertStringContainsString($label, $top, "the top bar should offer $label");
            self::assertStringNotContainsString(
                $label,
                $bottom,
                "$label is navigation and used to be repeated in the lower bar"
            );
        }

        foreach (['Delete', 'Mark Unread', 'Report to Admin'] as $label) {
            self::assertStringContainsString($label, $bottom, "the bottom bar should offer $label");
            self::assertStringNotContainsString($label, $top);
        }

        // Navigation is a GET and acting is a POST, which is the line the two
        // bars are drawn along -- not merely a tidier arrangement of the same
        // controls.
        self::assertStringNotContainsString('<form', $top);
        self::assertSame(3, substr_count($bottom, "method='POST'"));
    }

    public function testEveryActingControlCarriesAToken(): void
    {
        [, $bottom] = self::actionBars(self::read(7));

        self::assertSame(
            substr_count($bottom, '<form'),
            substr_count($bottom, "name='form_csrf_token'"),
            'every posting control in the action bar should carry a token'
        );
    }

    public function testDeleteAsksFirst(): void
    {
        [, $bottom] = self::actionBars(self::read(7));

        self::assertMatchesRegularExpression(
            '/op=del.*?confirm\(/s',
            $bottom,
            'deleting a message should ask, the way every other delete in the tree does'
        );
    }

    /**
     * Report to Admin carries the message with it.
     *
     * It posts to petition.php, which only *prefills* its form from this
     * payload -- the branch that writes is the one where abuse is not 'yes' --
     * so this is a navigation carrying a body rather than a state change. The
     * body is the point: without it the admin gets an empty petition.
     */
    public function testReportToAdminCarriesTheMessageItReports(): void
    {
        [, $bottom] = self::actionBars(self::read(7));

        self::assertMatchesRegularExpression("/<form action='petition\.php'/", $bottom);
        self::assertStringContainsString("name='abuse' value='yes'", $bottom);
        self::assertStringContainsString("name='abuseplayer' value='2'", $bottom);
        // The field NAME, not merely the text. petition.php prefills from
        // Http::post('problem') (pages/petition/petition_default.php:19), so a
        // renamed field reaches the administrator as an empty petition -- and
        // asserting only that the body appears somewhere in the markup passes
        // just as well when it arrives under a name nothing reads. Measured:
        // renaming the field left this test green until this line was added.
        self::assertMatchesRegularExpression(
            "/name='problem' value='Abusive Email Report:.*the body'/s",
            $bottom,
            'the report should reach petition.php under the name it reads'
        );
    }

    /**
     * A message from the System has nobody to report.
     *
     * The old markup filled the gap with an empty table cell, because the row
     * was a table and a cell had to be there. In a flow container there is no
     * hole to fill, so the control is simply absent -- and the rest of the bar
     * is unaffected, which is what this asserts rather than the absence alone.
     */
    public function testASystemMessageOffersNoReportButStillOffersTheRest(): void
    {
        [, $bottom] = self::actionBars(self::read(7, msgfrom: 0));

        self::assertStringNotContainsString('petition.php', $bottom);
        self::assertStringNotContainsString('&nbsp;', $bottom);
        self::assertStringContainsString('Delete', $bottom);
        self::assertStringContainsString('Mark Unread', $bottom);
    }

    /**
     * The edges of the mailbox keep their place in the row.
     *
     * With no adjacent message the label used to be emitted as bare text with
     * no element around it. That is not a control, and it left the row a
     * different shape at the two ends of the mailbox than in the middle.
     */
    public function testAnAbsentNeighbourStillOccupiesTheBar(): void
    {
        [$oldest] = self::actionBars(self::read(5));

        self::assertMatchesRegularExpression(
            "/<span class='button mail-nav__link' aria-disabled='true'>[^<]*Previous/",
            $oldest,
            'the oldest message has nothing before it, and the label should say so in place'
        );
        self::assertStringContainsString("<a href='mail.php?op=read&amp;id=7'", $oldest);

        [$newest] = self::actionBars(self::read(9));

        self::assertMatchesRegularExpression(
            "/<span class='button mail-nav__link' aria-disabled='true'>[^<]*Next/",
            $newest
        );
        self::assertStringContainsString("<a href='mail.php?op=read&amp;id=7'", $newest);
    }
}
