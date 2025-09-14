<?php

declare(strict_types=1);

/**
 * Collection of Player versus Player helper routines.
 */

namespace Lotgd;

use Lotgd\Settings;
use Lotgd\MySQL\Database;
use Lotgd\DateTime;
use Lotgd\Mail;
use Lotgd\Util\ScriptName;
use Lotgd\Modules\HookHandler;
use Lotgd\Translator;
use Lotgd\Output;
use Lotgd\Nav as Navigation;
use Lotgd\DebugLog;
use Lotgd\PlayerFunctions;

class Pvp
{
    /**
     * Show the PvP warning or remove immunity when attacking.
     */
    public static function warn(bool $dokill = false): void
    {
        global $session;

        $settings = Settings::getInstance();
        $output = Output::getInstance();

        $days = $settings->getSetting('pvpimmunity', 5);
        $exp = $settings->getSetting('pvpminexp', 1500);
        if (
            $session['user']['age'] <= $days &&
            $session['user']['dragonkills'] == 0 &&
            $session['user']['pk'] == 0 &&
            $session['user']['experience'] <= $exp
        ) {
            if ($dokill) {
                $output->output("`\$Warning!`^ Since you were still under PvP immunity, but have chosen to attack another player, you have lost this immunity!!`n`n");
                $session['user']['pk'] = 1;
            } else {
                $output->output("`\$Warning!`^ Players are immune from Player vs Player (PvP) combat for their first %s days in the game or until they have earned %s experience, or until they attack another player.  If you choose to attack another player, you will lose this immunity!`n`n", $days, $exp);
            }
        }
        HookHandler::hook('pvpwarning', ['dokill' => $dokill]);
    }

    /**
     * Prepare the data for a PvP target.
     *
     * @param int|string $name Account id or login name
     * @return array|false Information about the target or false if not valid
     */
    public static function setupTarget($name)
    {
        global $session;

        $settings = Settings::getInstance();
        $output = Output::getInstance();
        $pvptime = $settings->getSetting('pvptimeout', 600);
        $pvptimeout = date('Y-m-d H:i:s', strtotime("-$pvptime seconds"));

        // Legacy support for numeric id or login name
        if (is_numeric($name)) {
            $where = "acctid=$name";
        } else {
            $where = "login='$name'";
        }
        $sql = "SELECT name AS creaturename, level AS creaturelevel, weapon AS creatureweapon, dragonkills AS dragonkills," .
            "gold AS creaturegold, experience AS creatureexp, maxhitpoints AS creaturehealth, attack AS creatureattack, " .
            "defense AS creaturedefense, loggedin, location, laston, alive, acctid, pvpflag, boughtroomtoday, race FROM " .
            Database::prefix('accounts') . " WHERE $where";
        $result = Database::query($sql);
        if (Database::numRows($result) > 0) {
            $row = Database::fetchAssoc($result);
            if ($session['user']['dragonkills'] < $row['dragonkills']) {
                $diff = $row['dragonkills'] - $session['user']['dragonkills'];
                $row['creatureattack'] = PlayerFunctions::getPlayerAttack($row['acctid']) + round($diff / 2);
                $row['creaturedefense'] = PlayerFunctions::getPlayerDefense($row['acctid']) + round($diff / 2);
            }
            $output->output('As you both cannot use any special gimmicks, the more dragonkills your victim has the stronger he gets.`n');
            if ($row['pvpflag'] > $pvptimeout) {
                $output->output("`\$Oops:`4 That user is currently engaged by someone else, you'll have to wait your turn!");
                return false;
            } elseif (strtotime($row['laston']) > strtotime('-' . $settings->getSetting('LOGINTIMEOUT', 900) . ' sec') && $row['loggedin']) {
                $output->output("`\$Error:`4 That user is now online, and cannot be attacked until they log off again.");
                return false;
            } elseif ($session['user']['playerfights'] > 0) {
                $sql = "UPDATE " . Database::prefix('accounts') . " SET pvpflag='" . date('Y-m-d H:i:s') . "' WHERE acctid={$row['acctid']}";
                Database::query($sql);
                $row['creatureexp'] = round($row['creatureexp'], 0);
                $row['playerstarthp'] = $session['user']['hitpoints'];
                $row['fightstartdate'] = strtotime('now');
                $row = HookHandler::hook('pvpadjust', $row);
                self::warn(true);
                return $row;
            }
            $output->output('`4Judging by how tired you are, you think you had best not engage in battle against other players right now.');
            return false;
        }
        $output->output("`\$Error:`4 That user was not found!  It's likely that their account expired just now.");
        return false;
    }

    /**
     * Handle a PvP victory scenario.
     */
    public static function victory($badguy, $killedloc, $options = false)
    {
        global $session;

        $settings = Settings::getInstance();
        $output = Output::getInstance();

        $sql = "SELECT gold FROM " . Database::prefix('accounts') . " WHERE acctid='" . (int) $badguy['acctid'] . "'";
        $result = Database::query($sql);
        $row = Database::fetchAssoc($result);
        if (!isset($row['gold'])) {
            $row['gold'] = 0;
        }
        if (!isset($badguy['creaturegold'])) {
            $badguy['creaturegold'] = 0;
        }
        $badguy['creaturegold'] = ((int) $row['gold'] > (int) $badguy['creaturegold'] ? (int) $badguy['creaturegold'] : (int) $row['gold']);

        if ($session['user']['level'] == 15) {
            $output->output('`#***At your level of fighting prowess, the mere reward of beating your foe is sufficient accolade.`n');
        }

        $winamount = round(10 * $badguy['creaturelevel'] * log(max(1, $badguy['creaturegold'])), 0);
        $output->output("`b`\$You have slain %s!`0`b`n", $badguy['creaturename']);
        if ($session['user']['level'] == 15) {
            $winamount = 0;
        }
        $output->output("`#You receive `^%s`# gold!`n", $winamount);
        $session['user']['gold'] += $winamount;

        $exp = round($settings->getSetting('pvpattgain', 10) * $badguy['creatureexp'] / 100, 0);
        if ($settings->getSetting('pvphardlimit', 0)) {
            $max = $settings->getSetting('pvphardlimitamount', 15000);
            if ($exp > $max) {
                $exp = $max;
            }
        }
        if ($session['user']['level'] == 15) {
            $exp = 0;
        }
        $expbonus = round(($exp * (1 + .1 * ($badguy['creaturelevel'] - $session['user']['level']))) - $exp, 0);
        if ($expbonus > 0) {
            $output->output("`#***Because of the difficult nature of this fight, you are awarded an additional `^%s`# experience!`n", $expbonus);
        } elseif ($expbonus < 0) {
            $output->output("`#***Because of the simplistic nature of this fight, you are penalized `^%s`# experience!`n", abs($expbonus));
        }
        $wonexp = $exp + $expbonus;
        $output->output("You receive `^%s`# experience!`n`0", $wonexp);
        $session['user']['experience'] += $wonexp;

        $lostexp = round($badguy['creatureexp'] * $settings->getSetting('pvpdeflose', 5) / 100, 0);

        DebugLog::add("gained $winamount ({$badguy['creaturegold']} base) gold and $wonexp exp (loser lost $lostexp) for killing ", $badguy['acctid']);

        $args = ['pvpmessageadd' => '', 'handled' => false, 'badguy' => $badguy, 'options' => $options];
        $args = HookHandler::hook('pvpwin', $args);

        if ($session['user']['sex'] == SEX_MALE) {
            $msg = "`2While you were in %s, `^%s`2 initiated an attack on you with his `^%s`2, and defeated you!`n`nYou noticed he had an initial hp of `^%s`2 and just before you died he had `^%s`2 remaining.`n`nAs a result, you lost `\$%s%%`2 of your experience (approximately %s points), and `^%s`2 gold.`n%s`nDon't you think it's time for some revenge?`n`n`b`7Technical Notes:`b`nAlthough you might not have been in %s`7 when you got this message, you were in %s`7 when the fight was started, which was at %s according to the server (the fight lasted about %s).";
        } else {
            $msg = "`2While you were in %s, `^%s`2 initiated an attack on you with her `^%s`2, and defeated you!`n`nYou noticed she had an initial hp of `^%s`2 and just before you died she had `^%s`2 remaining.`n`nAs a result, you lost `\$%s%%`2 of your experience (approximately %s points), and `^%s`2 gold.`n%s`nDon't you think it's time for some revenge?`n`n`b`7Technical Notes:`b`nAlthough you might not have been in %s`7 when you got this message, you were in %s`7 when the fight was started, which was at %s according to the server (the fight lasted about %s).";
        }
        $mailmessage = [
            $msg,
            $killedloc, $session['user']['name'],
            $session['user']['weapon'], $badguy['playerstarthp'],
            $session['user']['hitpoints'], $settings->getSetting('pvpdeflose', 5),
            $lostexp, $badguy['creaturegold'], $args['pvpmessageadd'],
            $killedloc, $killedloc,
            date('D, M d h:i a', (int) $badguy['fightstartdate']),
            DateTime::relTime((int) $badguy['fightstartdate'])
        ];

        Mail::systemMail(
            $badguy['acctid'],
            Translator::sprintfTranslate(['`2You were killed while in %s`2', $killedloc]),
            Translator::sprintfTranslate(...$mailmessage)
        );

        $sql = "UPDATE " . Database::prefix('accounts') . " SET alive=0, goldinbank=(goldinbank+IF(gold<{$badguy['creaturegold']},gold-{$badguy['creaturegold']},0)),gold=IF(gold<{$badguy['creaturegold']},0,gold-{$badguy['creaturegold']}), experience=IF(experience>=$lostexp,experience-$lostexp,0) WHERE acctid=" . (int) $badguy['acctid'];
        DebugLog::add($sql, (int) $badguy['acctid'], $session['user']['acctid']);
        Database::query($sql);
        return $args['handled'];
    }

    /**
     * Handle a PvP defeat.
     */
    public static function defeat($badguy, $killedloc, $taunt, $options = false)
    {
        global $session;

        $settings = Settings::getInstance();
        $output = Output::getInstance();

        Navigation::add('Daily news', 'news.php');
        $killedin = $badguy['location'];
        $badguy['acctid'] = (int) $badguy['acctid'];
        $badguy['creaturegold'] = (int) $badguy['creaturegold'];

        $winamount = round(10 * $session['user']['level'] * log(max(1, $session['user']['gold'])), 0);
        if ($badguy['creaturelevel'] == 15) {
            $wonamount = 0;
        }

        $sql = "SELECT level FROM " . Database::prefix('accounts') . " WHERE acctid={$badguy['acctid']}";
        $result = Database::query($sql);
        $row = Database::fetchAssoc($result);

        $wonexp = round($session['user']['experience'] * $settings->getSetting('pvpdefgain', 10) / 100, 0);
        if ($settings->getSetting('pvphardlimit', 0)) {
            $max = $settings->getSetting('pvphardlimitamount', 15000);
            if ($wonexp > $max) {
                $wonexp = $max;
            }
        }
        if ($badguy['creaturelevel'] == 15) {
            $wonexp = 0;
        }

        $lostexp = round($session['user']['experience'] * $settings->getSetting('pvpattlose', 15) / 100, 0);

        $args = ['pvpmsgadd' => '', 'taunt' => $taunt, 'handled' => false, 'badguy' => $badguy, 'options' => $options];
        $args = HookHandler::hook('pvploss', $args);

        $msg = '`^%s`2 attacked you while you were in %s`2, but you were victorious!`n`n';
        if ($row['level'] < $badguy['creaturelevel']) {
            $output->output('`cThis player has leveled down!!!`c');
            $msg .= 'You would have received `^%s`2 experience and `^%s`2 gold, `$however it seems you lost it all when you got back to level 1...';
        } elseif ($badguy['creaturelevel'] == 15) {
            $msg .= 'At your level of fighting prowess, the mere reward of beating your foe is sufficient accolade.  You received `^%s`2 experience and `^%s`2 gold';
        } else {
            $msg .= 'You received `^%s`2 experience and `^%s`2 gold';
        }
        $msg .= '!`n%s`n`0';
        Mail::systemMail(
            $badguy['acctid'],
            Translator::sprintfTranslate(['`2You were successful while you were in %s`2', $killedloc]),
            Translator::sprintfTranslate($msg, $session['user']['name'], $killedloc, $wonexp, $winamount, $args['pvpmsgadd'])
        );

        if ($row['level'] >= $badguy['creaturelevel']) {
            $sql = "UPDATE " . Database::prefix('accounts') . " SET gold=gold+" . $winamount . ", experience=experience+" . $wonexp . " WHERE acctid=" . (int) $badguy['acctid'];
            DebugLog::add($sql);
            Database::query($sql);
        }

        $session['user']['alive'] = 0;
        DebugLog::add("lost {$session['user']['gold']} ($winamount to winner) gold and $lostexp exp ($wonexp to winner) being slain by ", $badguy['acctid']);
        $session['user']['gold'] = 0;
        $session['user']['hitpoints'] = 0;
        $session['user']['experience'] = round($session['user']['experience'] * (100 - $settings->getSetting('pvpattlose', 15)) / 100, 0);
        $output->output("`b`&You have been slain by `%%s`&!!!`n", $badguy['creaturename']);
        $output->output("`4All gold on hand has been lost!`n");
        $output->output("`4%s%% of experience has been lost!`n", $settings->getSetting('pvpattlose', 15));
        $output->output('You may begin fighting again tomorrow.');
        return $args['handled'];
    }

    /**
     * Return the list of possible PvP targets.
     *
     * @param string|false $location Location filter or false for player's location
     * @param string|false $link     Base link for attack operations
     * @param string|false $extra    Extra query arguments
     * @param string|false $sql      Custom SQL query to use
     */
    public static function listTargets($location = false, $link = false, $extra = false, $sql = false): void
    {
        global $session;

        $settings = Settings::getInstance();
        $output = Output::getInstance();
        $pvptime = $settings->getSetting('pvptimeout', 600);
        $pvptimeout = date('Y-m-d H:i:s', strtotime("-$pvptime seconds"));

        if ($location === false) {
            $location = $session['user']['location'];
        }
        if ($link === false) {
            $link = ScriptName::current() . '.php';
        }
        if ($extra === false) {
            $extra = '?act=attack';
        }

        $days = $settings->getSetting('pvpimmunity', 5);
        $exp = $settings->getSetting('pvpminexp', 1500);
        $clanrankcolors = ['`!', '`#', '`^', '`&', '`$'];
        $id = $session['user']['acctid'];
        $levdiff = $settings->getSetting('pvprange', 2);
        $lev1 = $session['user']['level'] - $levdiff + 1;
        $lev2 = $session['user']['level'] + $levdiff;
        $last = date('Y-m-d H:i:s', strtotime('-' . $settings->getSetting('LOGINTIMEOUT', 900) . ' sec'));

        if ($sql === false) {
            $loc = addslashes($location);
            $sql = "SELECT acctid, name, race, alive, location, sex, level, laston, " .
                "loggedin, login, pvpflag, clanshort, clanrank, dragonkills, " .
                Database::prefix('accounts') . ".clanid FROM " .
                Database::prefix('accounts') . " LEFT JOIN " .
                Database::prefix('clans') . " ON " . Database::prefix('clans') . ".clanid=" .
                Database::prefix('accounts') . ".clanid WHERE (locked=0) " .
                "AND (slaydragon=0) AND " .
                "(age>$days OR dragonkills>0 OR pk>0 OR experience>$exp) " .
                ($levdiff == -1 ? '' : "AND (level>=$lev1 AND level<=$lev2)") .
                " AND (alive=1) " .
                "AND (laston<'$last' OR loggedin=0)" .
                " AND (acctid<>$id) " .
                "AND location='$loc' " .
                "ORDER BY location='$loc' DESC, location, level DESC, " .
                "experience DESC, dragonkills DESC";
        }
        $result = Database::query($sql);

        $pvp = [];
        while ($row = Database::fetchAssoc($result)) {
            $pvp[] = $row;
        }

        $pvp = HookHandler::hook('pvpmodifytargets', $pvp);

        Translator::getInstance()->setSchema('pvp');
        $n = Translator::translateInline('Name');
        $l = Translator::translateInline('Level');
        $loc = Translator::translateInline('Location');
        $ops = Translator::translateInline('Ops');
        $bio = Translator::translateInline('Bio');
        $att = Translator::translateInline('Attack');

        $output->rawOutput("<table border='0' cellpadding='3' cellspacing='0'>");
        $output->rawOutput("<tr class='trhead'><td>$n</td><td>$l</td><td>$loc</td><td>$ops</td></tr>");
        $j = 0;
        $num = count($pvp);
        for ($i = 0; $i < $num; $i++) {
            $row = $pvp[$i];
            if (isset($row['silentinvalid']) && $row['silentinvalid']) {
                continue;
            }
            $j++;
            $biolink = "bio.php?char=" . $row['acctid'] . "&ret=" . urlencode($_SERVER['REQUEST_URI']);
            Navigation::add('', $biolink);
            $output->rawOutput("<tr class='" . ($j % 2 ? 'trlight' : 'trdark') . "'>");
            $output->rawOutput('<td>');
            if ($row['clanshort'] > '' && $row['clanrank'] > CLAN_APPLICANT) {
                $output->outputNotl(
                    '%s&lt;`2%s%s&gt;`0 ',
                    $clanrankcolors[ceil($row['clanrank'] / 10)],
                    $row['clanshort'],
                    $clanrankcolors[ceil($row['clanrank'] / 10)],
                    true
                );
            }
            $output->outputNotl('`@%s`0', $row['name']);
            $output->rawOutput('</td>');
            $output->rawOutput('<td>');
            $output->outputNotl('%s', $row['level']);
            $output->rawOutput('</td>');
            $output->rawOutput('<td>');
            $output->outputNotl('%s', $row['location']);
            $output->rawOutput('</td>');
            $output->rawOutput("<td>[ <a href='$biolink'>$bio</a> | ");
            if ($row['pvpflag'] > $pvptimeout) {
                $output->output("`i(Attacked too recently)`i");
            } elseif ($location != $row['location'] && (!isset($row['anylocation']) || !$row['anylocation'])) {
                $output->output("`i(Can't reach them from here)`i");
            } elseif (isset($row['invalid']) && $row['invalid'] != '') {
                if ($row['invalid'] == 1) {
                    $row['invalid'] = 'Unable to attack';
                }
                $output->output('`i`4(%s`4)`i', $row['invalid']);
            } else {
                $output->rawOutput("<a href='$link$extra&name=" . $row['acctid'] . "'>$att</a>");
                Navigation::add('', "$link$extra&name=" . $row['acctid']);
            }
            $output->rawOutput(' ]</td>');
            $output->rawOutput('</tr>');
        }

        $sql = "SELECT count(location) as counter, location FROM " . Database::prefix('accounts') .
            " WHERE (locked=0) " .
            "AND (slaydragon=0) AND " .
            "(age>$days OR dragonkills>0 OR pk>0 OR experience>$exp) " .
            ($levdiff == -1 ? '' : "AND (level>=$lev1 AND level<=$lev2)") .
            " AND (alive=1) " .
            "AND (laston<'$last' OR loggedin=0) AND (acctid<>$id) " .
            "AND location!='$loc' GROUP BY location ORDER BY location; ";
        $result = Database::query($sql);

        if ($j == 0) {
            $noone = Translator::translateInline('`iThere are no available targets.`i');
            $output->outputNotl("<tr><td align='center' colspan='4'>$noone</td></tr>", true);
        }
        $output->rawOutput('</table>', true);

        if (Database::numRows($result) != 0) {
            $output->output('`n`n`&As you listen to different people around you talking, you glean the following additional information:`n');
            while ($row = Database::fetchAssoc($result)) {
                $loc = $row['location'];
                $count = $row['counter'];
                $args = HookHandler::hook('pvpcount', ['count' => $count, 'loc' => $loc]);
                if (isset($args['handled']) && $args['handled']) {
                    continue;
                }
                if ($count == 1) {
                    $output->output('`&There is `^%s`& person sleeping in %s whom you might find interesting.`0`n', $count, $loc);
                } else {
                    $output->output('`&There are `^%s`& people sleeping in %s whom you might find interesting.`0`n', $count, $loc);
                }
            }
        }
        Translator::getInstance()->setSchema();
    }
}
