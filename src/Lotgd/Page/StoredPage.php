<?php

declare(strict_types=1);

namespace Lotgd\Page;

use Lotgd\Security\Csrf;

/**
 * A page stored earlier and shown again (badnav.php) for this session.
 *
 * The stored page belongs to the account, not the session: another session
 * of the same player (another browser, the login before this one) may have
 * stored it, with that session's async token in <meta name="lotgd-async">.
 * The page would send that token for as long as it is swapped in place --
 * refused by the stream (async/stream.php) and, with csrf_mode enforced, by
 * every async call. So the token is set to this session's.
 */
final class StoredPage
{
    public static function withSessionToken(string $html): string
    {
        return (string) preg_replace_callback(
            '/<meta name="lotgd-async" content="([^"]*)">/',
            static function (array $match): string {
                $config = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true);
                if (!is_array($config)) {
                    return $match[0];
                }
                // Render-side: showing a page is where a token may be minted.
                $config['csrf'] = Csrf::token(Csrf::SCOPE_ASYNC);

                return '<meta name="lotgd-async" content="'
                    . htmlspecialchars((string) json_encode($config), ENT_QUOTES, 'UTF-8') . '">';
            },
            $html,
            1
        );
    }
}
