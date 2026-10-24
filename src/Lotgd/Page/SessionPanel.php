<?php

declare(strict_types=1);

namespace Lotgd\Page;

use Lotgd\Async\Handler\Timeout;
use Lotgd\Security\Csrf;
use Lotgd\Security\Escape;
use Lotgd\Settings;
use Lotgd\Translator;

/**
 * The session panel (assets/js/lotgd.timeout.js, docs/ReactiveCore.md):
 * a notice in the upper right that counts down the last minutes of a
 * logged-in player's session, keeps it alive on request, and says so once it
 * has timed out.
 *
 * The page carries what the panel starts from in <meta name="lotgd-session">:
 * the session length, when to show the notice, whether the browser being
 * open keeps the session alive (never_timeout_if_browser_open), the async
 * token for "Stay logged in", and the texts. A page counts as activity, so
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
        $config = [
            'timeout' => max(1, (int) Settings::getInstance()->getSetting('LOGINTIMEOUT', 900)),
            'warn' => max(0, $timeout->getStartTimeoutShowSeconds()),
            'never' => $timeout->isNeverTimeoutIfBrowserOpen(),
            // Page-render context, so generating here is correct (see Csrf).
            'csrf' => Csrf::token(Csrf::SCOPE_ASYNC),
            'labels' => [
                'title' => Translator::translateInline('Session timeout', 'common'),
                'countdown' => Translator::translateInline('You will be logged out in %s.', 'common'),
                'stay' => Translator::translateInline('Stay logged in', 'common'),
                'expired' => Translator::translateInline('Your session has timed out.', 'common'),
                'login' => Translator::translateInline('Log in again', 'common'),
            ],
        ];

        return '<meta name="lotgd-session" content="' . Escape::html((string) json_encode($config)) . '">';
    }
}
