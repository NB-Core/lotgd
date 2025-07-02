<?php
namespace Lotgd;

/**
 * Utility methods for player maintenance tasks.
 */
class PlayerFunctions
{
    /**
     * Cleanup character data when deleting a player.
     *
     * @param int $id   Account id of the player to clean up
     * @param int $type Type of deletion (see constants)
     */
    public static function charCleanup(int $id, int $type): void
    {
        // Run module hooks for character deletion
        modulehook('delete_character', ['acctid' => $id, 'deltype' => $type]);

        // Remove output cache records for this player
        db_query('DELETE FROM ' . db_prefix('accounts_output') . " WHERE acctid=$id;");

        // Remove comments from this player
        db_query('DELETE FROM ' . db_prefix('commentary') . " WHERE author=$id;");

        // Handle clan cleanup logic
        $sql = 'SELECT clanrank,clanid FROM ' . db_prefix('accounts') . " WHERE acctid=$id";
        $res = db_query($sql);
        $row = db_fetch_assoc($res);
        if ($row['clanid'] != 0 && ($row['clanrank'] == CLAN_LEADER || $row['clanrank'] == CLAN_FOUNDER)) {
            $cid = $row['clanid'];
            $sql = 'SELECT count(acctid) as counter FROM ' . db_prefix('accounts')
                . " WHERE clanid=$cid AND clanrank >= " . CLAN_LEADER . " AND acctid<>$id ORDER BY clanrank DESC, clanjoindate";
            $res = db_query($sql);
            $row = db_fetch_assoc($res);
            if ($row['counter'] == 0) {
                $sql = 'SELECT name,acctid,clanrank FROM ' . db_prefix('accounts')
                    . " WHERE clanid=$cid AND clanrank > " . CLAN_APPLICANT . " AND acctid<>$id ORDER BY clanrank DESC, clanjoindate";
                $res = db_query($sql);
                if (db_num_rows($res)) {
                    $row = db_fetch_assoc($res);
                    if ($row['clanrank'] != CLAN_LEADER && $row['clanrank'] != CLAN_FOUNDER) {
                        $id1 = $row['acctid'];
                        $sql = 'UPDATE ' . db_prefix('accounts') . ' SET clanrank=' . CLAN_LEADER . " WHERE acctid=$id1";
                        db_query($sql);
                    }
                    GameLog::log('Clan ' . $cid . ' has a new leader ' . $row['name'] . ' as there were no others left', 'clan');
                } else {
                    $sql = 'DELETE FROM ' . db_prefix('clans') . " WHERE clanid=$cid";
                    db_query($sql);
                    GameLog::log('Clan ' . $cid . ' has been disbanded as the last member left', 'clan');
                    $sql = 'UPDATE ' . db_prefix('accounts') . " SET clanid=0,clanrank=0,clanjoindate='" . DATETIME_DATEMIN . "' WHERE clanid=$cid";
                    db_query($sql);
                }
            }
        }

        // Remove module user preferences
        module_delete_userprefs($id);
    }

    /**
     * Calculate a player's attack rating.
     */
    public static function getPlayerAttack($player = false)
    {
        global $session;
        if ($player !== false) {
            $sql = 'SELECT strength,wisdom,intelligence,attack FROM ' . db_prefix('accounts') . ' WHERE acctid=' . ((int)$player) . ';';
            $result = db_query($sql);
            $row = db_fetch_assoc($result);
            if (!$row) {
                return 0;
            }
            $user = $row;
        } else {
            $user =& $session['user'];
        }
        $strbonus = round((1 / 3) * $user['strength'], 1);
        $speedbonus = round((1 / 3) * self::getPlayerSpeed($player), 1);
        $wisdombonus = round((1 / 6) * $user['wisdom'], 1);
        $intbonus = round((1 / 6) * $user['intelligence'], 1);
        $miscbonus = round($user['attack'] - 9, 1);
        $attack = $strbonus + $speedbonus + $wisdombonus + $intbonus + $miscbonus;
        return max($attack, 0);
    }

    public static function explainedGetPlayerAttack($player = false)
    {
        global $session;
        if ($player !== false) {
            $sql = 'SELECT strength,wisdom,intelligence,attack FROM ' . db_prefix('accounts') . ' WHERE acctid=' . ((int)$player) . ';';
            $result = db_query($sql);
            $row = db_fetch_assoc($result);
            if (!$row) {
                return 0;
            }
            $user = $row;
        } else {
            $user =& $session['user'];
        }
        $strbonus = round((1 / 3) * $user['strength'], 1);
        $speedbonus = round((1 / 3) * self::getPlayerSpeed($player), 1);
        $wisdombonus = round((1 / 6) * $user['wisdom'], 1);
        $intbonus = round((1 / 6) * $user['intelligence'], 1);
        $miscbonus = round($user['attack'] - 9, 1);
        $atk = $strbonus + $speedbonus + $wisdombonus + $intbonus + $miscbonus;
        $weapondmg = (int)$user['weapondmg'];
        $levelbonus = (int)$user['level'] - 1;
        $miscbonus -= $weapondmg + $levelbonus;
        $explained = sprintf_translate('%s STR + %s SPD + %s WIS+ %s INT + %s Weapon + %s Train + %s MISC ', $strbonus, $speedbonus, $wisdombonus, $intbonus, $weapondmg, $levelbonus, $miscbonus);
        return $explained;
    }

    public static function getPlayerDefense($player = false)
    {
        global $session;
        if ($player !== false) {
            $sql = 'SELECT constitution,wisdom,defense FROM ' . db_prefix('accounts') . ' WHERE acctid=' . ((int)$player) . ';';
            $result = db_query($sql);
            $row = db_fetch_assoc($result);
            if (!$row) {
                return 0;
            }
            $user = $row;
        } else {
            $user =& $session['user'];
        }
        $wisdombonus = round((1 / 4) * $user['wisdom'], 1);
        $constbonus = round((3 / 8) * $user['constitution'], 1);
        $speedbonus = round((3 / 8) * self::getPlayerSpeed($player), 1);
        $miscbonus = round($user['defense'] - 9, 1);
        $defense = $wisdombonus + $speedbonus + $constbonus + $miscbonus;
        return max($defense, 0);
    }

    public static function explainedGetPlayerDefense($player = false)
    {
        global $session;
        if ($player !== false) {
            $sql = 'SELECT constitution,wisdom,defense FROM ' . db_prefix('accounts') . ' WHERE acctid=' . ((int)$player) . ';';
            $result = db_query($sql);
            $row = db_fetch_assoc($result);
            if (!$row) {
                return 0;
            }
            $user = $row;
        } else {
            $user =& $session['user'];
        }
        $wisdombonus = round((1 / 4) * $user['wisdom'], 1);
        $constbonus = round((3 / 8) * $user['constitution'], 1);
        $speedbonus = round((3 / 8) * self::getPlayerSpeed($player), 1);
        $miscbonus = round($user['defense'] - 9, 1);
        $defense = $wisdombonus + $speedbonus + $constbonus + $miscbonus;
        $armordef = (int)$user['armordef'];
        $levelbonus = (int)$user['level'] - 1;
        $miscbonus -= $armordef + $levelbonus;
        $explained = sprintf_translate('%s WIS + %s CON + %s SPD + %s Armor + %s Train + %s MISC ', $wisdombonus, $constbonus, $speedbonus, $armordef, $levelbonus, $miscbonus);
        return $explained;
    }

    public static function getPlayerSpeed($player = false)
    {
        global $session;
        if ($player !== false) {
            $sql = 'SELECT dexterity,intelligence FROM ' . db_prefix('accounts') . ' WHERE acctid=' . ((int)$player) . ';';
            $result = db_query($sql);
            $row = db_fetch_assoc($result);
            if (!$row) {
                return 0;
            }
            $user = $row;
        } else {
            $user =& $session['user'];
        }
        $speed = round((1 / 2) * $user['dexterity'] + (1 / 4) * $user['intelligence'] + (5 / 2), 1);
        return max($speed, 0);
    }

    public static function getPlayerPhysicalResistance($player = false)
    {
        global $session;
        if ($player !== false) {
            $sql = 'SELECT constitution,wisdom,defense FROM ' . db_prefix('accounts') . ' WHERE acctid=' . ((int)$player) . ';';
            $result = db_query($sql);
            $row = db_fetch_assoc($result);
            if (!$row) {
                return 0;
            }
            $user = $row;
        } else {
            $user =& $session['user'];
        }
        $defense = round(log($user['wisdom']) + $user['constitution'] * 0.08 + log($user['defense']), 1);
        return max($defense, 0);
    }

    public static function isPlayerOnline($player = false)
    {
        static $checked_users = [];
        if ($player === false) {
            global $session;
            $user =& $session['user'];
        } elseif (isset($checked_users[$player])) {
            $user =& $checked_users[$player];
        } else {
            $sql = 'SELECT acctid,laston,loggedin FROM ' . db_prefix('accounts') . ' WHERE acctid=' . ((int)$player) . ';';
            $result = db_query($sql);
            $row = db_fetch_assoc($result);
            $row = modulehook('is-player-online', $row);
            if (!$row) {
                return false;
            }
            $checked_users[$player] = $row;
            $user =& $row;
        }
        if (isset($user['laston']) && isset($user['loggedin'])) {
            if (strtotime('-' . getsetting('LOGINTIMEOUT', 900) . ' seconds') > strtotime($user['laston']) && strtotime($user['laston']) > 0) {
                return false;
            }
            if (!$user['loggedin']) {
                return false;
            }
            return true;
        }
        return false;
    }

    public static function massIsPlayerOnline($players = false)
    {
        $users = [];
        if ($players === false || $players == [] || !is_array($players)) {
            return [];
        } else {
            $sql = 'SELECT acctid,laston,loggedin FROM ' . db_prefix('accounts') . ' WHERE acctid IN (' . addslashes(implode(',', $players)) . ')';
            $result = db_query($sql);
            $rows = [];
            while ($user = db_fetch_assoc($result)) {
                $rows[] = $user;
            }
            $rows = modulehook('warriorlist', $rows);
            foreach ($rows as $user) {
                $users[$user['acctid']] = 1;
                if (isset($user['laston']) && isset($user['loggedin'])) {
                    if (strtotime('-' . getsetting('LOGINTIMEOUT', 900) . ' seconds') > strtotime($user['laston']) && $user['laston'] > '') {
                        $users[$user['acctid']] = 0;
                    }
                    if (!$user['loggedin']) {
                        $users[$user['acctid']] = 0;
                    }
                } else {
                    $users[$user['acctid']] = 0;
                }
            }
        }
        return $users;
    }

    public static function getPlayerDragonkillmod($withhitpoints = false)
    {
        global $session;
        $dragonpoints = array_count_values($session['user']['dragonpoints']);
        $dk = 0;
        foreach ($dragonpoints as $key => $val) {
            switch ($key) {
                case 'wis':
                    $dk += 0.2 * $val;
                    break;
                case 'con':
                case 'str':
                case 'int':
                case 'dex':
                    $dk += 0.3 * $val;
                    break;
                case 'at':
                case 'de':
                    $dk += $val;
                    break;
            }
        }
        if ($withhitpoints) {
            $dk += (int)(($session['user']['maxhitpoints'] - ($session['user']['level'] * 10)) / 5);
        }
        return $dk;
    }
}
