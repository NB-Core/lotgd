<?php

declare(strict_types=1);

/**
 * Miscellaneous server wide helper utilities.
 */

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\Settings;
use Lotgd\Modules\HookHandler;

class ServerFunctions
{
    /**
     * Determine if the game server reached the maximum number of online players.
     *
     * @return bool True when server limit is reached
     */
    public static function isTheServerFull(): bool
    {
        $settings = Settings::getInstance();
        if (abs($settings->getSetting('OnlineCountLast', 0) - strtotime('now')) > 60) {
            $sql = "SELECT count(acctid) as counter FROM " . Database::prefix('accounts') . " WHERE locked=0 AND loggedin=1 AND laston>'" . date('Y-m-d H:i:s', strtotime('-' . $settings->getSetting('LOGINTIMEOUT', 900) . ' seconds')) . "'";
            $result = Database::query($sql);
            $onlinecount = Database::fetchAssoc($result);
            $onlinecount = $onlinecount['counter'];
            $settings->saveSetting('OnlineCount', $onlinecount);
            $settings->saveSetting('OnlineCountLast', strtotime('now'));
        } else {
            $onlinecount = $settings->getSetting('OnlineCount', 0);
        }
        return $onlinecount >= $settings->getSetting('maxonline', 0) && $settings->getSetting('maxonline', 0) != 0;
    }

    /**
     * Reset dragonkill points for all or a subset of players.
     *
     * @param int|array|false $acctid Specific account id(s) or false for all
     *
     * @return void
     */
    public static function resetAllDragonkillPoints(int|array|false $acctid = false): void
    {
        if ($acctid === false) {
            $where = '';
        } elseif (is_array($acctid)) {
            $where = 'WHERE acctid IN (' . implode(',', $acctid) . ')';
        } else {
            $where = "WHERE acctid=$acctid";
        }
        $sql = 'SELECT acctid,dragonpoints FROM ' . Database::prefix('accounts') . " $where";
        $result = Database::query($sql);
        while ($row = Database::fetchAssoc($result)) {
            $dkpoints = $row['dragonpoints'];
            if ($dkpoints == '') {
                continue;
            }
            $dkpoints = unserialize(stripslashes($dkpoints));
            $distribution = array_count_values($dkpoints);
            $sets = [];
            foreach ($distribution as $key => $val) {
                switch ($key) {
                    case 'str':
                        $recalc = (int) $val;
                        $sets[] = "strength=strength-$recalc";
                        break;
                    case 'con':
                        $recalc = (int) $val;
                        $sets[] = "constitution=constitution-$recalc";
                        break;
                    case 'int':
                        $recalc = (int) $val;
                        $sets[] = "intelligence=intelligence-$recalc";
                        break;
                    case 'wis':
                        $recalc = (int) $val;
                        $sets[] = "wisdom=wisdom-$recalc";
                        break;
                    case 'dex':
                        $recalc = (int) $val;
                        $sets[] = "dexterity=dexterity-$recalc";
                        break;
                    case 'hp':
                        $recalc = (int) $val * 5;
                        $sets[] = "maxhitpoints=maxhitpoints-$recalc, hitpoints=hitpoints-$recalc";
                        break;
                    case 'at':
                        $recalc = (int) $val;
                        $sets[] = "attack=attack-$recalc";
                        break;
                    case 'de':
                        $recalc = (int) $val;
                        $sets[] = "defense=defense-$recalc";
                        break;
                }
            }
            $resetactions = count($sets) > 0 ? ',' . implode(',', $sets) : '';
            $sql = 'UPDATE ' . Database::prefix('accounts') . " SET dragonpoints=''$resetactions WHERE acctid=" . $row['acctid'];
            Database::query($sql);
            HookHandler::hook('dragonpointreset', [$row]);
        }
    }

    /**
     * Check if the current request is served over HTTPS.
     *
     * @return bool True when the connection is secure
     */
    public static function isSecureConnection(): bool
    {
        return self::isHttpsRequest();
    }

    /**
     * Determine whether the request should be treated as HTTPS.
     *
     * Supports direct TLS detection and common reverse-proxy forwarded
     * protocol headers.
     *
     * @return bool
     */
    public static function isHttpsRequest(): bool
    {
        if (self::shouldTrustForwardedHeaders($_SERVER)) {
            $forwardedProto = self::extractForwardedProto($_SERVER);
            if ($forwardedProto === 'https') {
                return true;
            }

            $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
            if ($forwardedSsl === 'on') {
                return true;
            }

            $frontEndHttps = strtolower(trim((string) ($_SERVER['HTTP_FRONT_END_HTTPS'] ?? '')));
            if ($frontEndHttps === 'on') {
                return true;
            }

            $requestScheme = strtolower(trim((string) ($_SERVER['REQUEST_SCHEME'] ?? '')));
            if ($requestScheme === 'https') {
                return true;
            }
        }

        $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));

        return ($https !== '' && $https !== 'off' && $https !== '0')
            || (($_SERVER['SERVER_PORT'] ?? 80) == 443);
    }

    /**
     * Extract the original protocol from common proxy forwarding headers.
     *
     * Supported forms:
     * - HTTP_X_FORWARDED_PROTO / FORWARDED_PROTO
     * - RFC 7239 Forwarded header (`proto=https`)
     *
     * @param array<string, mixed> $server
     *
     * @return string Normalized protocol token or empty string when unknown.
     */
    private static function extractForwardedProto(array $server): string
    {
        $candidate = (string) (
            $server['HTTP_X_FORWARDED_PROTO']
            ?? $server['X_FORWARDED_PROTO']
            ?? $server['HTTP_X_FORWARDED_PROTOCOL']
            ?? $server['HTTP_FORWARDED_PROTO']
            ?? $server['FORWARDED_PROTO']
            ?? $server['HTTP_X_URL_SCHEME']
            ?? ''
        );
        if ($candidate !== '') {
            // Reverse proxies may send comma-separated protocol hops.
            return strtolower(trim(explode(',', $candidate)[0]));
        }

        $forwarded = (string) ($server['HTTP_FORWARDED'] ?? '');
        if ($forwarded === '') {
            return '';
        }

        if (preg_match('/(?:^|[;,\\s])proto=([^;,\\s]+)/i', $forwarded, $match) !== 1) {
            return '';
        }

        return strtolower(trim($match[1], " \t\n\r\0\x0B\"'"));
    }

    /**
     * Decide whether forwarded headers should be trusted for TLS detection.
     *
     * `LOTGD_TRUST_FORWARDED_HEADERS` defaults to enabled (`1`) for backward
     * compatibility. Set it to `0` in direct-ingress deployments.
     *
     * Optionally scope trust to known proxy source IPs via
     * `LOTGD_TRUSTED_PROXY_IPS` (comma-separated exact IP list). When this
     * allowlist is configured, forwarded headers are ignored unless
     * `REMOTE_ADDR` matches one of the listed IPs.
     *
     * @param array<string, mixed> $server
     */
    private static function shouldTrustForwardedHeaders(array $server): bool
    {
        $trustForwardedRaw = getenv('LOTGD_TRUST_FORWARDED_HEADERS');
        $trustForwardedValue = $trustForwardedRaw === false ? '1' : (string) $trustForwardedRaw;
        $trustForwarded = strtolower(trim($trustForwardedValue));
        if (in_array($trustForwarded, ['0', 'false', 'no'], true)) {
            return false;
        }

        $trustedProxyIpsRaw = getenv('LOTGD_TRUSTED_PROXY_IPS');
        $trustedProxyIps = $trustedProxyIpsRaw === false ? '' : trim((string) $trustedProxyIpsRaw);
        if ($trustedProxyIps === '') {
            return true;
        }

        $remoteAddr = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if ($remoteAddr === '') {
            // CLI/test contexts may not provide REMOTE_ADDR. Keep behavior
            // deterministic there by trusting forwarded headers.
            return PHP_SAPI === 'cli';
        }

        $allowed = array_map('trim', explode(',', $trustedProxyIps));
        $allowed = array_filter($allowed, static fn (string $ip): bool => $ip !== '');

        return in_array($remoteAddr, $allowed, true);
    }
}
