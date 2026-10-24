<?php

declare(strict_types=1);

namespace Lotgd\Page;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\Security\Csrf;
use Lotgd\Security\Escape;

/**
 * A page stored earlier and shown again (badnav.php) for this session.
 *
 * The stored page belongs to the account, not the session: another session
 * of the same player (another browser, the login before this one) may have
 * stored it, with that session's async token in <meta name="lotgd-async"> and
 * <meta name="lotgd-session"> (the session panel's "Stay logged in").
 * The page would send that token for as long as it is swapped in place --
 * refused by the stream (async/stream.php) and, with csrf_mode enforced, by
 * every async call. So the token is set to this session's.
 */
final class StoredPage
{
    public static function withSessionToken(string $html): string
    {
        return (string) preg_replace_callback(
            '/<meta name="(lotgd-async|lotgd-session)" content="([^"]*)">/',
            static function (array $match): string {
                $config = json_decode(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'), true);
                if (!is_array($config)) {
                    return $match[0];
                }
                // Render-side: showing a page is where a token may be minted.
                $config['csrf'] = Csrf::token(Csrf::SCOPE_ASYNC);

                return '<meta name="' . $match[1] . '" content="'
                    . htmlspecialchars((string) json_encode($config), ENT_QUOTES, 'UTF-8') . '">';
            },
            $html,
            2
        );
    }

    /**
     * The page an account last stored, uncompressed; '' when there is none
     * (navigation fixed, never played).
     */
    public static function forAccount(int $acctid): string
    {
        $stored = Database::getDoctrineConnection()->executeQuery(
            'SELECT output FROM ' . Database::prefix('accounts_output') . ' WHERE acctid = :acctid',
            ['acctid' => $acctid],
            ['acctid' => ParameterType::INTEGER]
        )->fetchOne();
        if (!is_string($stored) || $stored === '') {
            return '';
        }
        $page = @gzuncompress($stored);

        return is_string($page) ? $page : '';
    }

    /**
     * Another account's stored page as a sealed preview for an editor page:
     * a sandboxed frame with the page inline (srcdoc), so showing it makes no
     * request of its own -- which would pass the navigation check and claim
     * the editor's page revision -- and the page's own scripts never run.
     * Its links and form controls do nothing; its async token, which belongs
     * to the other player's session, is left out.
     */
    public static function preview(string $html, string $title): string
    {
        $html = (string) preg_replace('/<meta name="lotgd-async" content="[^"]*">/', '', $html);
        $inert = '<style>a,button,input,select,textarea,label,summary{pointer-events:none!important}</style>';
        $html = preg_match('/<head(\s[^>]*)?>/i', $html) === 1
            ? (string) preg_replace('/<head(\s[^>]*)?>/i', '$0' . $inert, $html, 1)
            : $inert . $html;

        return "<iframe sandbox loading='lazy' referrerpolicy='no-referrer' title='" . Escape::html($title)
            . "' width='100%' height='400' srcdoc='" . Escape::html($html) . "'></iframe>";
    }
}
