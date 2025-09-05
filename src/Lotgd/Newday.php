<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Translator;
use Lotgd\MySQL\Database;
use Lotgd\GameLog;
use Lotgd\ExpireChars;
use Lotgd\Modules\HookHandler;
use Lotgd\DataCache;

class Newday
{
    public static function dbCleanup(): void
    {
        savesetting("lastdboptimize", date("Y-m-d H:i:s"));
        // Fetch all table names at once to avoid leaving an unbuffered
        // result active which can cause "Cannot execute queries while other
        // unbuffered queries are active" errors with PDO MySQL.
        $rows = Database::getDoctrineConnection()
            ->fetchAllAssociative('SHOW TABLES');

        $tables = [];
        $start = getmicrotime();
        foreach ($rows as $row) {
            foreach ($row as $val) {
                Database::query("OPTIMIZE TABLE $val");
                $tables[] = $val;
            }
        }
        $time = round(getmicrotime() - $start, 2);
        GameLog::log('Optimized tables: ' . join(', ', $tables) . " in $time seconds.", 'maintenance');
    }

    public static function commentCleanup(): void
    {
        $settings = Settings::getInstance();

        $timestamp = self::calculateExpirationTimestamp('2 month');
        Database::query('DELETE FROM ' . Database::prefix('referers') . " WHERE last < '$timestamp'");
        GameLog::log('Deleted ' . Database::affectedRows() . ' records from ' . Database::prefix('referers') . " older than $timestamp.", 'maintenance');

        $timestamp = date('Y-m-d H:i:s', strtotime('now'));
        $sql = 'INSERT IGNORE INTO ' . Database::prefix('debuglog_archive') .
            ' SELECT * FROM ' . Database::prefix('debuglog') . " WHERE date <'$timestamp'";
        $ok = Database::query($sql);
        if ($ok) {
            $sql = 'DELETE FROM ' . Database::prefix('debuglog') . " WHERE date <'$timestamp'";
            Database::query($sql);
        $timestamp = self::calculateExpirationTimestamp($settings->getSetting('expiredebuglog', 18) . ' days');
            $sql = 'DELETE FROM ' . Database::prefix('debuglog_archive') . " WHERE date <'$timestamp'";
            if ($settings->getSetting('expiredebuglog', 18) > 0) {
                Database::query($sql);
            }
            GameLog::log('Moved ' . Database::affectedRows() . ' from ' . Database::prefix('debuglog') . ' to ' . Database::prefix('debuglog_archive') . " older than $timestamp.", 'maintenance');
        } else {
            GameLog::log('ERROR, problems with moving the debuglog to the archive', 'maintenance');
        }

        $timestamp = self::calculateExpirationTimestamp($settings->getSetting('oldmail', 14) . ' days');
        $sql = 'DELETE FROM ' . Database::prefix('mail') . " WHERE sent<'$timestamp'";
        Database::query($sql);
        GameLog::log('Deleted ' . Database::affectedRows() . ' records from ' . Database::prefix('mails') . " older than $timestamp.", 'maintenance');
        DataCache::massinvalidate('mail');

        if ((int) $settings->getSetting('expirecontent', 180) > 0) {
            $timestamp = self::calculateExpirationTimestamp($settings->getSetting('expirecontent', 180) . ' days');
            $sql = 'DELETE FROM ' . Database::prefix('news') . " WHERE newsdate<'$timestamp'";
            GameLog::log('Deleted ' . Database::affectedRows() . ' records from ' . Database::prefix('news') . " older than $timestamp.", 'comment expiration');
            Database::query($sql);
        }

        $timestamp = self::calculateExpirationTimestamp($settings->getSetting('expiregamelog', 30) . ' days');
        $sql = 'DELETE FROM ' . Database::prefix('gamelog') . " WHERE date < '$timestamp' ";
        if ($settings->getSetting('expiregamelog', 30) > 0) {
            Database::query($sql);
            GameLog::log('Cleaned up ' . Database::prefix('gamelog') . ' table removing ' . Database::affectedRows() . " older than $timestamp.", 'maintenance');
        }

        $sql = 'DELETE FROM ' . Database::prefix('commentary') . " WHERE postdate<'" . self::calculateExpirationTimestamp($settings->getSetting('expirecontent', 180) . ' days') . "'";
        if ($settings->getSetting('expirecontent', 180) > 0) {
            $timestamp = self::calculateExpirationTimestamp($settings->getSetting('expirecontent', 180) . ' days');
            Database::query($sql);
            GameLog::log('Deleted ' . Database::affectedRows() . ' records from ' . Database::prefix('commentary') . " older than $timestamp.", 'comment expiration');
        }

        $sql = 'DELETE FROM ' . Database::prefix('moderatedcomments') . " WHERE moddate<'" . self::calculateExpirationTimestamp($settings->getSetting('expirecontent', 180) . ' days') . "'";
        if ($settings->getSetting('expirecontent', 180) > 0) {
            $timestamp = self::calculateExpirationTimestamp($settings->getSetting('expirecontent', 180) . ' days');
            Database::query($sql);
            GameLog::log('Deleted ' . Database::affectedRows() . ' records from ' . Database::prefix('moderatedcomments') . " older than $timestamp.", 'comment expiration');
        }

        $sql = 'DELETE FROM ' . Database::prefix('faillog') . " WHERE date<'" . self::calculateExpirationTimestamp($settings->getSetting('expirefaillog', 1) . ' days') . "'";
        if ($settings->getSetting('expirefaillog', 1) > 0) {
            Database::query($sql);
            $timestamp = self::calculateExpirationTimestamp($settings->getSetting('expirecontent', 180) . ' days');
            GameLog::log('Deleted ' . Database::affectedRows() . ' records from ' . Database::prefix('faillog') . " older than $timestamp.", 'maintenance');
        }
    }

    private static function calculateExpirationTimestamp(string $offset): string
    {
        return date('Y-m-d H:i:s', strtotime("-$offset"));
    }

    public static function charCleanup(): void
    {
        ExpireChars::expire();
    }

    public static function runOnce(): void
    {
        $settings = Settings::getInstance();

        HookHandler::hook('newday-runonce', []);

        if ($settings->getSetting('usedatacache', 0)) {
            $path = $settings->getSetting('datacachepath', '/tmp');
            $handle = @opendir($path);
            if ($handle === false) {
                trigger_error("Unable to open datacache directory: $path", E_USER_WARNING);
            } else {
                while (($file = readdir($handle)) !== false) {
                    if (substr($file, 0, strlen(DATACACHE_FILENAME_PREFIX)) == DATACACHE_FILENAME_PREFIX) {
                        $fn = $path . '/' . $file;
                        $fn = str_replace('//', DIRECTORY_SEPARATOR, $fn);
                        $fn = str_replace('\\\\', DIRECTORY_SEPARATOR, $fn);
                        if (is_file($fn) && filemtime($fn) < strtotime('-24 hours')) {
                            unlink($fn);
                        }
                    }
                }
                closedir($handle);
            }
        }

        if (! $settings->getSetting('newdaycron', 0)) {
            self::dbCleanup();
            self::commentCleanup();
            self::charCleanup();
        }
    }

    public static function dragonPointRecalc(array $labels, int $dkills, int &$dp): void
    {
        global $session;
        $pdks = [];
        $pdkneg = false;
        foreach ($labels as $type => $label) {
            $head = explode(',', $label);
            if (count($head) > 1) {
                continue;
            }
            $pdks[$type] = (int) httppost($type);
        }
        HookHandler::hook('pdkpointrecalc');
        $pdktotal = 0;
        foreach ($labels as $type => $label) {
            $head = explode(',', $label);
            if (count($head) > 1) {
                continue;
            }
            $pdktotal += (int) ($pdks[$type] ?? 0);
            if ((int) ($pdks[$type] ?? 0) < 0) {
                $pdkneg = true;
            }
        }
        if ($pdktotal == $dkills - $dp && !$pdkneg) {
            $dp += $pdktotal;
            $session['user']['maxhitpoints'] += 5 * ($pdks['hp'] ?? 0);
            $session['user']['attack'] += $pdks['at'] ?? 0;
            $session['user']['defense'] += $pdks['de'] ?? 0;
            $session['user']['strength'] += $pdks['str'] ?? 0;
            $session['user']['dexterity'] += $pdks['dex'] ?? 0;
            $session['user']['intelligence'] += $pdks['int'] ?? 0;
            $session['user']['constitution'] += $pdks['con'] ?? 0;
            $session['user']['wisdom'] += $pdks['wis'] ?? 0;
            foreach ($labels as $type => $label) {
                $head = explode(',', $label);
                if (count($head) > 1) {
                    continue;
                }
                $count = $pdks[$type] ?? 0;
                while ($count > 0) {
                    $session['user']['dragonpoints'][] = $type;
                    --$count;
                }
            }
        } else {
            output("`\$Error: Please spend the correct total amount of dragon points.`n`n");
        }
    }

    public static function dragonPointSpend(array $labels, array $canbuy, int $dkills, int $dp, string $resline): void
    {
        global $session;
        if ($dkills - $dp > 1) {
            page_header('Dragon Points');
            output("`@You earn one dragon point each time you slay the dragon.");
            output('Advancements made by spending dragon points are permanent!');
            output("`n`nYou have `^%s`@ unspent dragon points.", $dkills - $dp);
            output('How do you wish to spend them?`n`n');
            output('Be sure that your allocations add up to your total unspent dragon points.');
            $text = "<script type='text/javascript' language='Javascript'>\n";
            $text .= "<!--\n";
            $text .= "function pointsLeft() {\n";
            $text .= "var form = document.getElementById(\\\"dkForm\\\");\n";
            foreach ($labels as $type => $label) {
                $head = explode(',', $label);
                if (count($head) > 1) {
                    continue;
                }
                if (isset($canbuy[$type]) && $canbuy[$type]) {
                    $text .= "var $type = parseInt(form.$type.value);\n";
                }
            }
            foreach ($labels as $type => $label) {
                $head = explode(',', $label);
                if (count($head) > 1) {
                    continue;
                }
                if (isset($canbuy[$type]) && $canbuy[$type]) {
                    $text .= "if (isNaN($type)) $type = 0;\n";
                }
            }
            $text .= "var val = $dkills - $dp ";
            foreach ($labels as $type => $label) {
                $head = explode(',', $label);
                if (count($head) > 1) {
                    continue;
                }
                if (isset($canbuy[$type]) && $canbuy[$type]) {
                    $text .= "- $type";
                }
            }
            $text .= ";\n";
            $text .= "var absval = Math.abs(val);\n";
            $text .= "var points = 'points';\n";
            $text .= "if (absval == 1) points = 'point';\n";
            $text .= "if (val >= 0)\n";
            $text .= "document.getElementById(\\\"amtLeft\\\").innerHTML = \"<span class='colLtWhite'>You have </span><span class='colLtYellow'>\"+absval+\"</span><span class='colLtWhite'> \"+points+\" left to spend.</span><br />\";\n";
            $text .= "else\n";
            $text .= "document.getElementById(\\\"amtLeft\\\").innerHTML = \"<span class='colLtWhite'>You have spent </span><span class='colLtRed'>\"+absval+\"</span><span class='colLtWhite'> \"+points+\" too many!</span><br />\";\n";
            $text .= "}\n";
            $text .= "// -->\n";
            $text .= "</script>\n";
            rawoutput($text);
            addnav('Reset', "newday.php?pdk=0$resline");
            $link = appendcount("newday.php?pdk=1$resline");
            rawoutput("<form id='dkForm' action='$link' method='POST'>");
            addnav('', $link);
            rawoutput("<br><table cellpadding='0' cellspacing='0' border='0' width='200'>");
            foreach ($labels as $type => $label) {
                $head = explode(',', $label);
                if (count($head) > 1) {
                    rawoutput("<tr><td colspan='2' nowrap>");
                    output("`b`4%s`0`b`n", Translator::translateInline($head[0]));
                    rawoutput("</td></tr>");
                    continue;
                }
                if (isset($canbuy[$type]) && $canbuy[$type]) {
                    rawoutput("<tr><td nowrap>");
                    output($label);
                    output_notl(":");
                    rawoutput("</td><td>");
                    rawoutput("<input id='$type' name='$type' size='4' maxlength='4' value='{$canbuy[$type]}' onKeyUp='pointsLeft();' onBlur='pointsLeft();' onFocus='pointsLeft();'>");
                    rawoutput("</td></tr>");
                }
            }
            rawoutput("<tr><td colspan='2'>&nbsp;</td></tr><tr><td colspan='2' align='center'>");
            $click = Translator::translateInline('Spend');
            rawoutput("<input id='dksub' type='submit' class='button' value='$click'>");
            rawoutput("</td></tr><tr><td colspan='2'>&nbsp;</td></tr><tr><td colspan='2' align='center'>");
            rawoutput('<div id="amtLeft"></div>');
            rawoutput('</td></tr></table></form>');
            $count = 0;
            foreach ($labels as $type => $label) {
                $head = explode(',', $label);
                if (count($head) > 1) {
                    continue;
                }
                if ($count > 0) {
                    break;
                }
                if (isset($canbuy[$type]) && $canbuy[$type]) {
                    rawoutput("<script language='JavaScript'>document.getElementById('$type').focus();</script>");
                    $count++;
                }
            }
        } else {
            page_header('Dragon Points');
            $dist = [];
            foreach ($labels as $type => $label) {
                $head = explode(',', $label);
                if (count($head) > 1) {
                    addnav($head[0]);
                    continue;
                }
                $dist[$type] = 0;
                if (isset($canbuy[$type]) && $canbuy[$type]) {
                    addnav($label, "newday.php?dk=$type$resline");
                }
            }
            output("`@You have `&1`@ unspent dragon point.");
            output('How do you wish to spend it?`n`n');
            output('You earn one dragon point each time you slay the dragon.');
            output('Advancements made by spending dragon points are permanent!');
            $player_dkpoints = count($session['user']['dragonpoints']);
            for ($i = 0; $i < $player_dkpoints; $i++) {
                if (isset($dist[$session['user']['dragonpoints'][$i]])) {
                    $dist[$session['user']['dragonpoints'][$i]]++;
                } else {
                    $dist['unknown']++;
                }
            }
            output("`n`nCurrently, the dragon points you have already spent are distributed in the following manner.");
            rawoutput('<blockquote><table>');
            foreach ($labels as $type => $label) {
                $head = explode(',', $label);
                if (isset($type) && $type > 0 && (!isset($dist[$type]) || $dist[$type] == 0)) {
                    continue;
                }
                if (count($head) > 1) {
                    rawoutput("<tr><td colspan='2' nowrap>");
                    output("`b`4%s`0`b`n", Translator::translateInline($head[0]));
                    rawoutput('</td></tr>');
                    continue;
                }
                if ($type == 'unknown' && $dist[$type] == 0) {
                    continue;
                }
                rawoutput('<tr><td nowrap>');
                output($label);
                output_notl(':');
                rawoutput('</td><td>&nbsp;&nbsp;</td><td>');
                output_notl("`@%s", $dist[$type]);
                rawoutput('</td></tr>');
            }
            rawoutput('</table></blockquote>');
        }
    }

    public static function setRace(string $resline): void
    {
        global $session;
        $settings = Settings::getInstance();
        $setrace = httpget('setrace');
        if ($setrace != '') {
            $vname = $settings->getSetting('villagename', LOCATION_FIELDS);
            $session['user']['race'] = $setrace;
            $session['user']['location'] = $vname;
            HookHandler::hook('setrace');
            addnav('Continue', "newday.php?continue=1$resline");
        } else {
            output('Where do you recall growing up?`n`n');
            HookHandler::hook('chooserace');
        }
        if (navcount() == 0) {
            clearoutput();
            page_header('No Races Installed');
            output("No races were installed in this game.");
            output("So we'll call you a 'human' and get on with it.");
            if ($session['user']['superuser'] & (SU_MEGAUSER | SU_MANAGE_MODULES)) {
                output('You should go into the module manager off of the super user grotto, install and activate some races.');
            } else {
                output("You might want to ask your admin to install some races, they're really quite fun.");
            }
            $session['user']['race'] = 'Human';
            addnav('Continue', "newday.php?continue=1$resline");
            page_footer();
        } else {
            page_header('A little history about yourself');
            page_footer();
        }
    }

    public static function setSpecialty(string $resline): void
    {
        global $session;
        $setspecialty = httpget('setspecialty');
        if ($setspecialty != '') {
            $session['user']['specialty'] = $setspecialty;
            HookHandler::hook('set-specialty');
            addnav('Continue', "newday.php?continue=1$resline");
        } else {
            page_header('A little history about yourself');
            output('What do you recall doing as a child?`n`n');
            HookHandler::hook('choose-specialty');
        }
        if (navcount() == 0) {
            clearoutput();
            page_header('No Specialties Installed');
            output("Since there are no suitable specialties available, we'll make you a student of the mystical powers and get on with it.");
            if ($session['user']['superuser'] & (SU_MEGAUSER | SU_MANAGE_MODULES)) {
                output('You should go into the module manager off of the super user grotto, install and activate some specialties.');
            } else {
                output('You might want to ask your admin to install some specialties, as they are quite fun (and helpful).');
            }
            $session['user']['specialty'] = 'MP';
            addnav('Continue', "newday.php?continue=1$resline");
            page_footer();
        } else {
            page_footer();
        }
    }
}
