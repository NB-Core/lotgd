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
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? 80) == 443);
    }
}
