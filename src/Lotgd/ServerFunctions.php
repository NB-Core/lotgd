<?php

declare(strict_types=1);

/**
 * Miscellaneous server wide helper utilities.
 */

namespace Lotgd;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\Settings;
use Lotgd\Modules\HookHandler;
use Lotgd\Security\RuntimeHardening;

class ServerFunctions
{
    /**
     * Determine if the game server reached the maximum number of online players.
     *
     * @return bool True when server limit is reached
     */
    public static function isTheServerFull(): bool
    {
        $connection = Database::getDoctrineConnection();
        $settings = Settings::getInstance();
        if (abs($settings->getSetting('OnlineCountLast', 0) - strtotime('now')) > 60) {
            $lastOnThreshold = date('Y-m-d H:i:s', strtotime('-' . $settings->getSetting('LOGINTIMEOUT', 900) . ' seconds'));
            $onlinecount = $connection->executeQuery(
                'SELECT count(acctid) as counter FROM ' . Database::prefix('accounts')
                . ' WHERE locked = :locked AND loggedin = :loggedIn AND laston > :lastOnThreshold',
                [
                    'locked' => 0,
                    'loggedIn' => 1,
                    'lastOnThreshold' => $lastOnThreshold,
                ],
                [
                    'locked' => ParameterType::INTEGER,
                    'loggedIn' => ParameterType::INTEGER,
                    'lastOnThreshold' => ParameterType::STRING,
                ]
            )->fetchAssociative();
            $onlinecount = (int) ($onlinecount['counter'] ?? $onlinecount['total_count'] ?? 0);
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
        $connection = Database::getDoctrineConnection();
        $params = [];
        $types = [];
        if ($acctid === false) {
            $where = '';
        } elseif (is_array($acctid)) {
            $where = 'WHERE acctid IN (:acctids)';
            $params['acctids'] = array_map('intval', $acctid);
            $types['acctids'] = ArrayParameterType::INTEGER;
        } else {
            $where = 'WHERE acctid = :acctid';
            $params['acctid'] = $acctid;
            $types['acctid'] = ParameterType::INTEGER;
        }
        $result = $connection->executeQuery(
            'SELECT acctid,dragonpoints FROM ' . Database::prefix('accounts') . " $where",
            $params,
            $types
        );
        while ($row = $result->fetchAssociative()) {
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
            // $resetactions only contains whitelisted static column fragments
            // emitted by the switch above; values are cast to integers before
            // interpolation. SQL identifiers cannot be parameterized in DBAL.
            $resetactions = count($sets) > 0 ? ',' . implode(',', $sets) : '';
            $connection->executeStatement(
                'UPDATE ' . Database::prefix('accounts') . " SET dragonpoints=''$resetactions WHERE acctid = :acctid",
                ['acctid' => (int) $row['acctid']],
                ['acctid' => ParameterType::INTEGER]
            );
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
        $trustForwardedRaw = getenv('LOTGD_TRUST_FORWARDED_HEADERS');
        $trustForwardedValue = $trustForwardedRaw === false ? '1' : (string) $trustForwardedRaw;

        $options = RuntimeHardening::buildOptions([
            'SECURITY_TRUST_FORWARDED_PROTO' => !in_array(
                strtolower(trim($trustForwardedValue)),
                ['0', 'false', 'no'],
                true
            ),
            'SECURITY_TRUSTED_PROXIES' => getenv('LOTGD_TRUSTED_PROXY_IPS') ?: '',
        ]);

        return RuntimeHardening::isHttpsRequest($_SERVER, $options);
    }
}
