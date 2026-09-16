<?php

declare(strict_types=1);

namespace Lotgd\Mail;

use Lotgd\Modules\HookHandler;
use Lotgd\Translator;

/**
 * The controls offered around a mail message, as a list rather than as markup.
 *
 * Two reasons this is a class and not a pair of functions in
 * `pages/mail/case_read.php`, where the markup used to be assembled inline.
 *
 * The first is the hook. A module could not contribute a control to the mail
 * read view at all -- that page had no hook of any kind -- and the obvious
 * remedy, letting a module write its own HTML the way `mailform` does, is what
 * produced the defect this work began with: a row of three different elements
 * wearing one anchor-only class, because each author chose their own. A list
 * lets the module say *what* a control does while the core decides how it
 * looks, so a contributed button inherits the row's styling, its escaping and
 * its CSRF handling without asking for any of them.
 *
 * The second is that the hook has to be testable. The page harness in
 * tests/Security/PageCsrf runs a page in a child process with the Database
 * stub, where `Database::$queryCacheResults` is empty -- so `Modules::hook()`
 * finds no rows and every hook silently does nothing. A hook exercised only
 * through that harness would be a hook nothing ever proved fires. Here it can
 * be driven in-process with a fake module, no database, the way
 * tests/Modules/Hooks does it.
 */
final class ReadActions
{
    /**
     * The hook a module adds its own controls through.
     *
     * Hyphenated after `mail-write-notify`, the naming this area already uses.
     */
    public const HOOK = 'mail-read-actions';

    /**
     * Moving between messages: the two neighbours, and the two ways out.
     *
     * Not hooked. This bar is navigation within one mailbox, and a module with
     * somewhere else to send the player means the tab strip, which has had
     * `mailfunctions` for that all along.
     *
     * A neighbour that does not exist still gets an entry. The alternative --
     * leaving it out -- makes the row a different shape at the ends of a
     * mailbox than in the middle, and the player loses the position of every
     * other control as they page through.
     *
     * @return list<array<string,mixed>>
     */
    public static function navigation(array $message, int $previousId, int $nextId): array
    {
        $messageId = (int) ($message['messageid'] ?? 0);

        return [
            self::neighbour($previousId, Translator::translateInline('< Previous')),
            self::neighbour($nextId, Translator::translateInline('Next >')),
            [
                'kind' => 'link',
                'url' => "mail.php?op=write&replyto=$messageId",
                'label' => Translator::translateInline('Reply'),
            ],
            [
                'kind' => 'link',
                'url' => "mail.php?op=address&id=$messageId",
                'label' => Translator::translateInline('Forward'),
            ],
        ];
    }

    /**
     * What can be done to this message, after the modules have had their say.
     *
     * Every entry the core puts here posts, and that is the line the two bars
     * are drawn along rather than a tidier arrangement of the same controls:
     * acting on a message is a POST carrying a token, moving to another one is
     * a GET. A module is not held to it -- the hook below accepts `link` and
     * `disabled` entries too, and a contributed control that merely takes the
     * player somewhere is a reasonable thing to want here.
     *
     * **Modules must append to this list and return it.** `Modules::hook()`
     * assigns each module's return value over the payload
     * (`src/Lotgd/Modules.php:624`) instead of merging it, so a module that
     * returns an empty array takes Delete and Mark Unread with it -- for the
     * caller and for every module after it in priority order. A module that
     * returns something which is not an array is safe: the engine warns and
     * keeps the previous payload. There is no guard that can recover the first
     * case here, which is why it is written down rather than defended against.
     *
     * **A rendered token is not a validated one.** A `post` entry gets a CSRF
     * field like any other button here, but `runmodule.php` validates nothing
     * -- AGENTS.md says so outright -- so a module whose control posts to it
     * is unprotected until its own write branch asks -- with
     * `Forms::isUnverifiedRequest()`, the same check `docs/Hooks.md` shows and
     * the shape core pages use at a write. What this list hands a module is
     * the field; the check stays theirs.
     * Reported by Codex.
     *
     * A module that wants to *remove* the core's own deletion has a sanctioned
     * way to do it that does not depend on this list: `header-mail` carries a
     * `done` flag which suppresses the core's handling of the operation.
     *
     * @return list<array<string,mixed>>
     */
    public static function actions(array $message): array
    {
        $messageId = (int) ($message['messageid'] ?? 0);
        $from = (int) ($message['msgfrom'] ?? 0);

        $actions = [
            [
                'kind' => 'post',
                'url' => "mail.php?op=del&id=$messageId",
                'label' => Translator::translateInline('Delete'),
                'confirm' => Translator::translateInline('Are you sure you wish to delete this message?'),
            ],
            [
                'kind' => 'post',
                'url' => "mail.php?op=unread&id=$messageId",
                'label' => Translator::translateInline('Mark Unread'),
            ],
        ];

        // A message from the System has nobody to report. In a flow container
        // that is simply one control fewer, where the table this replaced
        // needed an empty cell to hold the column open.
        if ($from !== 0) {
            $actions[] = self::report($message, $from);
        }

        return HookHandler::hook(self::HOOK, $actions);
    }

    /**
     * The adjacent-message control, present whether or not there is one.
     *
     * @return array<string,mixed>
     */
    private static function neighbour(int $id, string $label): array
    {
        if ($id <= 0) {
            return ['kind' => 'disabled', 'label' => $label];
        }

        return ['kind' => 'link', 'url' => "mail.php?op=read&id=$id", 'label' => $label];
    }

    /**
     * Hand the message to the administrators.
     *
     * It posts, but it does not change anything: `petition.php` reads this
     * payload only to prefill its form, and the branch that writes a petition
     * is the one where `abuse` is not 'yes'. So this is a navigation carrying a
     * body -- the body being the point, since without it the administrator
     * receives an empty petition.
     *
     * @return array<string,mixed>
     */
    private static function report(array $message, int $from): array
    {
        $report = "Abusive Email Report:\n"
            . 'From: ' . ($message['name'] ?? '') . "\n"
            . 'Subject: ' . ($message['subject'] ?? '') . "\n"
            . 'Sent: ' . ($message['sent'] ?? '') . "\n"
            . 'ID: ' . ($message['messageid'] ?? '') . "\n"
            . "Body:\n" . ($message['body'] ?? '');

        return [
            'kind' => 'post',
            'url' => 'petition.php',
            'label' => Translator::translateInline('Report to Admin'),
            'fields' => [
                'problem' => $report,
                'abuse' => 'yes',
                'abuseplayer' => (string) $from,
            ],
        ];
    }
}
