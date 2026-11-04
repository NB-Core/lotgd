<?php

declare(strict_types=1);

namespace Lotgd\Page;

use Lotgd\Async\Handler\Timeout;
use Lotgd\Security\Csrf;
use Lotgd\Security\Escape;
use Lotgd\Settings;
use Lotgd\Translator;

/**
 * The notices in the upper right (assets/js/lotgd.notices.js,
 * docs/ReactiveCore.md) for a logged-in player: the last minutes of the
 * session (a countdown, "Stay logged in", timed out), new mail and a new
 * Message of the Day.
 *
 * The page carries what they start from in <meta name="lotgd-session">: the
 * session length, when to warn, whether the browser being open keeps the
 * session alive (never_timeout_if_browser_open), the async token, how often
 * to look for mail and the Message of the Day and what the page shows of
 * them (Notices), whether popup pages open in the dialog, and the texts. A page counts as activity, so
 * the countdown starts at the full length. The meta's content changes from
 * page to page, like every lotgd-* meta, and stays out of the head signature.
 */
final class SessionPanel
{
    /**
     * @param array<string,mixed> $user The session user.
     */
    public static function meta(array $user): string
    {
        if (empty($user['loggedin']) || (int) ($user['acctid'] ?? 0) <= 0) {
            return '';
        }
        $timeout = Timeout::configured();
        $notices = Notices::status($user);
        $popup = (string) ($user['prefs']['popupsize'] ?? '');
        $config = [
            'timeout' => max(1, (int) Settings::getInstance()->getSetting('LOGINTIMEOUT', 900)),
            'warn' => max(0, $timeout->getStartTimeoutShowSeconds()),
            'never' => $timeout->isNeverTimeoutIfBrowserOpen(),
            // Page-render context, so generating here is correct (see Csrf).
            'csrf' => Csrf::token(Csrf::SCOPE_ASYNC),
            // The notices: how often to look, and what this page shows.
            'poll' => Notices::interval(),
            'mail' => $notices['mail'],
            'motd' => $notices['motd'],
            'overlay' => Overlay::skinSupports(),
            'popup' => preg_match('/^\d{2,4}x\d{2,4}$/', $popup) === 1 ? $popup : '550x330',
            'labels' => [
                'title' => Translator::translateInline('Session timeout', 'common'),
                'countdown' => Translator::translateInline('You will be logged out in %s.', 'common'),
                'stay' => Translator::translateInline('Stay logged in', 'common'),
                'expired' => Translator::translateInline('Your session has timed out.', 'common'),
                'login' => Translator::translateInline('Log in again', 'common'),
                'mailTitle' => Translator::translateInline('New mail', 'common'),
                'mailOne' => Translator::translateInline('You have 1 unread message.', 'common'),
                'mailMany' => Translator::translateInline('You have %s unread messages.', 'common'),
                'motdTitle' => Translator::translateInline('Message of the Day', 'common'),
                'motdText' => Translator::translateInline('There is a new Message of the Day.', 'common'),
                'read' => Translator::translateInline('Read', 'common'),
                'dismiss' => Translator::translateInline('Dismiss', 'common'),
            ],
        ];

        return '<meta name="lotgd-session" content="' . Escape::html((string) json_encode($config)) . '">';
    }
}
