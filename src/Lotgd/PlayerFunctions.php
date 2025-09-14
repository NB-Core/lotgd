<?php

declare(strict_types=1);

/**
 * Utility methods for player maintenance tasks.
 */

namespace Lotgd;

use Lotgd\Settings;
use Lotgd\MySQL\Database;
use Lotgd\Modules\HookHandler;
use Lotgd\Translator;
use Lotgd\Output;
use Lotgd\Random;

class PlayerFunctions
{
    /**
     * Cleanup character data when deleting a player.
     *
     * @param int $id   Account id of the player to clean up
     * @param int $type Type of deletion (see constants)
     *
     * @return bool True if cleanup was performed, false if prevented
     */
    public static function charCleanup(int $id, int $type): bool
    {
        global $session;
        // Run module hooks for character deletion
        $args = HookHandler::hook('delete_character', ['acctid' => $id, 'deltype' => $type]);

        // Allow modules to prevent cleanup
        if ($args['prevent_cleanup'] ?? false) {
            return false;
        }

        // Remove output cache records for this player
        Database::query('DELETE FROM ' . Database::prefix('accounts_output') . " WHERE acctid=$id;");

        // Remove comments from this player
        Database::query('DELETE FROM ' . Database::prefix('commentary') . " WHERE author=$id;");

        // Handle clan cleanup logic
        $sql = 'SELECT clanrank,clanid FROM ' . Database::prefix('accounts') . " WHERE acctid=$id";
        $res = Database::query($sql);
        $row = Database::fetchAssoc($res);
        if ($row['clanid'] != 0 && ($row['clanrank'] == CLAN_LEADER || $row['clanrank'] == CLAN_FOUNDER)) {
            $cid = $row['clanid'];
            $sql = 'SELECT count(acctid) as counter FROM ' . Database::prefix('accounts')
                . " WHERE clanid=$cid AND clanrank >= " . CLAN_LEADER . " AND acctid<>$id ORDER BY clanrank DESC, clanjoindate";
            $res = Database::query($sql);
            $row = Database::fetchAssoc($res);
            if ($row['counter'] == 0) {
                $sql = 'SELECT name,acctid,clanrank FROM ' . Database::prefix('accounts')
                    . " WHERE clanid=$cid AND clanrank > " . CLAN_APPLICANT . " AND acctid<>$id ORDER BY clanrank DESC, clanjoindate";
                $res = Database::query($sql);
                if (Database::numRows($res)) {
                    $row = Database::fetchAssoc($res);
                    if ($row['clanrank'] != CLAN_LEADER && $row['clanrank'] != CLAN_FOUNDER) {
                        $id1 = $row['acctid'];
                        $sql = 'UPDATE ' . Database::prefix('accounts') . ' SET clanrank=' . CLAN_LEADER . " WHERE acctid=$id1";
                        Database::query($sql);
                    }
                    GameLog::log(
                        'Clan ' . $cid . ' has a new leader ' . $row['name'] . ' as there were no others left',
                        'clan',
                        false,
                        $session['user']['acctid'] ?? 0
                    );
                } else {
                    $sql = 'DELETE FROM ' . Database::prefix('clans') . " WHERE clanid=$cid";
                    Database::query($sql);
                    GameLog::log(
                        'Clan ' . $cid . ' has been disbanded as the last member left',
                        'clan',
                        false,
                        $session['user']['acctid'] ?? 0
                    );
                    $sql = 'UPDATE ' . Database::prefix('accounts') . " SET clanid=0,clanrank=0,clanjoindate='" . DATETIME_DATEMIN . "' WHERE clanid=$cid";
                    Database::query($sql);
                }
            }
        }

        // Remove module user preferences
        HookHandler::deleteUserPrefs($id);

        return true;
    }

    /**
     * Calculate a player's attack rating.
     */
    public static function getPlayerAttack(int|false $player = false): float
    {
        global $session;
        if ($player !== false) {
            $sql = 'SELECT strength,wisdom,intelligence,attack FROM ' . Database::prefix('accounts') . ' WHERE acctid=' . ((int)$player) . ';';
            $result = Database::query($sql);
            $row = Database::fetchAssoc($result);
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

    public static function explainedGetPlayerAttack(int|false $player = false): string
    {
        global $session;
        if ($player !== false) {
            $sql = 'SELECT strength,wisdom,intelligence,attack FROM ' . Database::prefix('accounts') . ' WHERE acctid=' . ((int)$player) . ';';
            $result = Database::query($sql);
            $row = Database::fetchAssoc($result);
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
        $explained = Translator::getInstance()->sprintfTranslate('%s STR + %s SPD + %s WIS+ %s INT + %s Weapon + %s Train + %s MISC ', $strbonus, $speedbonus, $wisdombonus, $intbonus, $weapondmg, $levelbonus, $miscbonus);
        return $explained;
    }

    public static function getPlayerDefense(int|false $player = false): float
    {
        global $session;
        if ($player !== false) {
            $sql = 'SELECT constitution,wisdom,defense FROM ' . Database::prefix('accounts') . ' WHERE acctid=' . ((int)$player) . ';';
            $result = Database::query($sql);
            $row = Database::fetchAssoc($result);
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

    public static function explainedGetPlayerDefense(int|false $player = false): string
    {
        global $session;
        if ($player !== false) {
            $sql = 'SELECT constitution,wisdom,defense FROM ' . Database::prefix('accounts') . ' WHERE acctid=' . ((int)$player) . ';';
            $result = Database::query($sql);
            $row = Database::fetchAssoc($result);
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
        $explained = Translator::getInstance()->sprintfTranslate('%s WIS + %s CON + %s SPD + %s Armor + %s Train + %s MISC ', $wisdombonus, $constbonus, $speedbonus, $armordef, $levelbonus, $miscbonus);
        return $explained;
    }

    public static function getPlayerSpeed(int|false $player = false): float
    {
        global $session;
        if ($player !== false) {
            $sql = 'SELECT dexterity,intelligence FROM ' . Database::prefix('accounts') . ' WHERE acctid=' . ((int)$player) . ';';
            $result = Database::query($sql);
            $row = Database::fetchAssoc($result);
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

    public static function getPlayerPhysicalResistance(int|false $player = false): float
    {
        global $session;
        if ($player !== false) {
            $sql = 'SELECT constitution,wisdom,defense FROM ' . Database::prefix('accounts') . ' WHERE acctid=' . ((int)$player) . ';';
            $result = Database::query($sql);
            $row = Database::fetchAssoc($result);
            if (!$row) {
                return 0;
            }
            $user = $row;
        } else {
            $user =& $session['user'];
        }
        $defense = round(log((int)$user['wisdom']) + (int)$user['constitution'] * 0.08 + log((int)$user['defense']), 1);
        return max($defense, 0);
    }

    public static function isPlayerOnline(int|false $player = false): bool
    {
        static $checked_users = [];
        $settings = Settings::getInstance();
        if ($player === false) {
            global $session;
            $user =& $session['user'];
        } elseif (isset($checked_users[$player])) {
            $user =& $checked_users[$player];
        } else {
            $sql = 'SELECT acctid,laston,loggedin FROM ' . Database::prefix('accounts') . ' WHERE acctid=' . ((int)$player) . ';';
            $result = Database::query($sql);
            $row = Database::fetchAssoc($result);
            $row = HookHandler::hook('is-player-online', $row);
            if (!$row) {
                return false;
            }
            $checked_users[$player] = $row;
            $user =& $row;
        }
        if (isset($user['laston']) && isset($user['loggedin'])) {
            if (strtotime('-' . $settings->getSetting('LOGINTIMEOUT', 900) . ' seconds') > strtotime($user['laston']) && strtotime($user['laston']) > 0) {
                return false;
            }
            if (!$user['loggedin']) {
                return false;
            }
            return true;
        }
        return false;
    }

    public static function massIsPlayerOnline(array|false $players = false): array
    {
        $users = [];
        $settings = Settings::getInstance();
        if ($players === false || $players == [] || !is_array($players)) {
            return [];
        } else {
            $sql = 'SELECT acctid,laston,loggedin FROM ' . Database::prefix('accounts') . ' WHERE acctid IN (' . addslashes(implode(',', $players)) . ')';
            $result = Database::query($sql);
            $rows = [];
            while ($user = Database::fetchAssoc($result)) {
                $rows[] = $user;
            }
            $rows = HookHandler::hook('warriorlist', $rows);
            foreach ($rows as $user) {
                $users[$user['acctid']] = 1;
                if (isset($user['laston']) && isset($user['loggedin'])) {
                    if (strtotime('-' . $settings->getSetting('LOGINTIMEOUT', 900) . ' seconds') > strtotime($user['laston']) && $user['laston'] > '') {
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

    public static function getPlayerDragonkillmod(bool $withhitpoints = false): float
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

    /**
     * Calculate experience required for the next level.
     */
    public static function expForNextLevel(int $curlevel, int $curdk): float
    {
        $stored = DataCache::getInstance()->datacache('exparraydk' . $curdk);
        if ($stored !== false && is_array($stored)) {
            $exparray = $stored;
        } else {
            $settings  = Settings::getInstance();
            $expstring = $settings->getSetting('exp-array', '100,400,1002,1912,3140,4707,6641,8985,11795,15143,19121,23840,29437,36071,43930');
            if ($expstring == '') {
                return 0;
            }
            $exparray = explode(',', $expstring);
            if (count($exparray) < $settings->getSetting('maxlevel', 15)) {
                for ($i = count($exparray) - 1; $i < $settings->getSetting('maxlevel', 15); $i++) {
                    $exparray[] = $exparray[count($exparray) - 1] * 1.3;
                }
            }
            foreach ($exparray as $key => $val) {
                $exparray[$key] = round($val + ($curdk / 4) * ($key + 1) * 100, 0);
            }
            if ($settings->getSetting('maxlevel', 15) > count($exparray)) {
                for ($i = count($exparray); $i < $settings->getSetting('maxlevel', 15); $i++) {
                    $exparray[$i] = round($exparray[$i - 1] * 1.2);
                }
            }
            DataCache::getInstance()->updatedatacache('exparraydk' . $curdk, $exparray);
        }
        if (count($exparray) > $curlevel) {
            $exprequired = $exparray[max(0, $curlevel - 1)];
        } else {
            $exprequired = array_pop($exparray);
        }
        return $exprequired;
    }

    public static function applyTempStat(string $name, int|float $value, string $type = 'add'): bool
    {
        global $session, $temp_user_stats;
        $output = Output::getInstance();
        if ($type == 'add') {
            if (!isset($temp_user_stats['add'])) {
                $temp_user_stats['add'] = [];
            }
            $temp =& $temp_user_stats['add'];
            if (!isset($temp[$name])) {
                $temp[$name] = $value;
            } else {
                $temp[$name] += $value;
            }
            if (!$temp_user_stats['is_suspended']) {
                if (isset($session['user'][$name])) {
                    $session['user'][$name] += $value;
                } else {
                    $output->debug("Temp stat $name is not supported to $type.");
                    unset($temp[$name]);
                    return false;
                }
            }
            return true;
        }
        $output->debug("Temp stat type $type is not supported.");
        return false;
    }

    public static function checkTempStat(string $name, bool|int $color = false): string|int|float
    {
        global $temp_user_stats, $session;
        $v = $temp_user_stats['add'][$name] ?? 0;
        if ($color === false) {
            return ($v == 0 ? '' : $v);
        }
        $settings = Settings::getInstance();
        $point    = $settings->getSetting('moneydecimalpoint', '.');
        $sep      = $settings->getSetting('moneythousandssep', ',');

        if ($v > 0) {
            return " `&(" . number_format($session['user'][$name] - $v, 1, $point, $sep)
                . "`@+" . number_format($v, 1, $point, $sep) . "`&)";
        }

        return $v == 0
            ? ''
            : " `&(" . number_format($session['user'][$name] + $v, 1, $point, $sep)
                . "`$-" . number_format($v, 1, $point, $sep) . "`&)";
    }

    public static function suspendTempStats(): bool
    {
        global $session, $temp_user_stats;
        if (!$temp_user_stats['is_suspended']) {
            foreach ($temp_user_stats as $type => $collection) {
                if ($type == 'add') {
                    foreach ($collection as $attribute => $value) {
                        $session['user'][$attribute] -= $value;
                    }
                }
            }
            $temp_user_stats['is_suspended'] = true;
            return true;
        }
        return false;
    }

    public static function restoreTempStats(): bool
    {
        global $session, $temp_user_stats;
        if ($temp_user_stats['is_suspended']) {
            foreach ($temp_user_stats as $type => $collection) {
                if ($type == 'add') {
                    foreach ($collection as $attribute => $value) {
                        $session['user'][$attribute] += $value;
                    }
                }
            }
            $temp_user_stats['is_suspended'] = false;
            return true;
        }
        return false;
    }

    public static function validDkTitle(string $title, int $dks, bool $gender): bool
    {
        $sql = 'SELECT dk,male,female FROM ' . Database::prefix('titles') . " WHERE dk <= $dks ORDER by dk DESC";
        $res = Database::query($sql);
        $d = -1;
        while ($row = Database::fetchAssoc($res)) {
            if ($d == -1) {
                $d = $row['dk'];
            }
            if ($row['dk'] != $d) {
                break;
            }
            if ($gender && ($row['female'] == $title)) {
                return true;
            }
            if (!$gender && ($row['male'] == $title)) {
                return true;
            }
        }
        return false;
    }

    public static function getDkTitle(int $dks, bool $gender, string|false $ref = false): string
    {
        $refdk = -1;
        if ($ref !== false) {
            $sql = 'SELECT max(dk) as dk FROM ' . Database::prefix('titles') . " WHERE dk<='$dks' and ref='$ref'";
            $res = Database::query($sql);
            $row = Database::fetchAssoc($res);
            $refdk = $row['dk'];
        }
        $sql = 'SELECT max(dk) as dk FROM ' . Database::prefix('titles') . " WHERE dk<='$dks'";
        $res = Database::query($sql);
        $row = Database::fetchAssoc($res);
        $anydk = $row['dk'];
        $useref = '';
        $targetdk = $anydk;
        if ($refdk >= $anydk) {
            $useref = "AND ref='$ref'";
            $targetdk = $refdk;
        }
        $sql = 'SELECT male,female FROM ' . Database::prefix('titles') . " WHERE dk='$targetdk' $useref ORDER BY RAND(" . Random::e_rand() . ") LIMIT 1";
        $res = Database::query($sql);
        $row = ['male' => 'God', 'female' => 'Goddess'];
        if (Database::numRows($res) != 0) {
            $row = Database::fetchAssoc($res);
        }
        return ($gender == SEX_MALE) ? $row['male'] : $row['female'];
    }
}
