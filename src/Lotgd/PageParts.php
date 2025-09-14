<?php

declare(strict_types=1);

/**
 * Library (supporting) functions for page output
 *      addnews ready
 *      translator ready
 *      mail ready
 *
 * @author core_module
 * @package defaultPackage
 */

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\Buffs;
use Lotgd\CharStats;
use Lotgd\Accounts;
use Lotgd\PlayerFunctions;
use Lotgd\HolidayText;
use Lotgd\Template;
use Lotgd\Sanitize;
use Lotgd\Nav;
use Lotgd\DateTime;
use Lotgd\Settings;
use Lotgd\Output;
use Lotgd\Modules\HookHandler;
use Lotgd\Translator;
use Lotgd\DataCache;
use Lotgd\Http;
use Lotgd\Mounts;
use Lotgd\PhpGenericEnvironment;

class PageParts
{
    /**
     * Tracks scripts that should not display popups.
     * @var array<string,bool>
     */
    public static array $noPopups = [];

    /**
     * Keeps track of which headers have already run to avoid duplicates.
     * @var array<string,bool>
     */
    public static array $runHeaders = [];

    /** Holds the character statistics for the current page. */
    /** Variables passed to Twig templates */
    public static array $twigVars = [];

    /**
     * Starts page output. Initializes the template and translator modules.
     *
     * @param array|string $title
     * Hooks provided:
     *      everyheader
     *      header-{scriptname}
     */
/**
 * Deprecated wrapper that forwards to Header::pageHeader().
 */
    public static function pageHeader(...$args): void
    {
        \Lotgd\Page\Header::pageHeader(...$args);
    }

/**
 * Returns an output formatted popup link based on JavaScript
 *
 * @param string $page The URL to open
 * @param string $size The size of the popup window (Default: 550x300)
 * @return string
 */
    public static function popup(string $page, string $size = "550x300")
    {
        // user prefs
        global $session;
        if ($size === "550x300" && isset($session['loggedin']) && $session['loggedin']) {
            if (!isset($session['user']['prefs'])) {
                $usersize = '550x330';
            } else {
                $usersize = &$session['user']['prefs']['popupsize'];
                if ($usersize == '') {
                    $usersize = '550x330';
                }
            }
            $s = explode("x", $usersize);
            $s[0] = (int)max(50, $s[0]);
            $s[1] = (int)max(50, $s[1]);
        } else {
            $s = explode("x", $size);
        }
        //user prefs
        return "window.open('$page','" . preg_replace("([^[:alnum:]])", "", $page) . "','scrollbars=yes,resizable=yes,width={$s[0]},height={$s[1]}').focus()";
    }

/**
 * Brings all the output elements together and terminates the rendering of the page.  Saves the current user info and updates the rendering statistics
 * Hooks provided:
 *  footer-{$script name}
 *  everyfooter
 *
 */
/**
 * Deprecated wrapper that forwards to Footer::pageFooter().
 */
    public static function pageFooter(bool $saveuser = true)
    {
        \Lotgd\Page\Footer::pageFooter($saveuser);
    }


/**
 * Page header for popup windows.
 *
 * @param string $title The title of the popup window
 */
/**
 * Deprecated wrapper that forwards to Header::popupHeader().
 */
    public static function popupHeader(...$args): void
    {
        \Lotgd\Page\Header::popupHeader(...$args);
    }


/**
 * Ends page generation for popup windows.  Saves the user account info - doesn't update page generation stats
 *
 */
/**
 * Deprecated wrapper that forwards to Footer::popupFooter().
 */
    public static function popupFooter(): void
    {
        \Lotgd\Page\Footer::popupFooter();
    }

/**
 * Resets the character stats array
 *
 */
    public static function wipeCharStats(): void
    {
        \Lotgd\Page\CharStats::wipe();
    }

/**
 * Add a attribute and/or value to the character stats display
 *
 * @param string $label The label to use
 * @param mixed $value (optional) value to display
 */
    public static function addCharStat(string $label, mixed $value = null): void
    {
        \Lotgd\Page\CharStats::add($label, $value);
    }

/**
 * Returns the character stat related to the category ($cat) and the label
 *
 * @param string $cat The relavent category for the stat
 * @param string $label The label of the character stat
 * @return mixed The value associated with the stat
 */
    public static function getCharStat(string $cat, string $label)
    {
        return \Lotgd\Page\CharStats::get($cat, $label);
    }

/**
 * Sets a value to the passed category & label for character stats
 *
 * @param string $cat The category for the char stat
 * @param string $label The label associated with the value
 * @param mixed $val The value of the attribute
 */
    public static function setCharStat(string $cat, string $label, mixed $val): void
    {
        \Lotgd\Page\CharStats::set($cat, $label, $val);
    }

/**
 * Returns output formatted character stats
 *
 * @param array $buffs
 * @return string
 */
    public static function getCharStats(string $buffs): string
    {
        return \Lotgd\Page\CharStats::render($buffs);
    }

/**
 * Returns the value associated with the section & label.  Returns an empty string if the stat isn't set
 *
 * @param string $section The character stat section
 * @param string $title The stat display label
 * @return mixed The value associated with the stat
 */
    public static function getCharStatValue(string $section, string $title)
    {
        return \Lotgd\Page\CharStats::value($section, $title);
    }

/**
 * Returns the current character stats or (if the character isn't logged in) the currently online players
 * Hooks provided:
 *      charstats
 *
 * @return string The current stats for this character or the list of online players
 */
    public static function charStats(): string
    {
        global $session, $companions;
        $mount = Mounts::getInstance()->getPlayerMount();
        $output = Output::getInstance();

        $settings = Settings::getInstance();
        if (defined("IS_INSTALLER") && IS_INSTALLER === true) {
            return "";
        }

        self::wipeCharStats();

        $u = $session['user'] ?? [];
        if (!is_array($u)) {
            $u = [];
        }

        $loggedIn = isset($session['loggedin']) && $session['loggedin'];
        foreach (['race', 'name'] as $key) {
            if (!isset($u[$key])) {
                $loggedIn = false;
            }
        }

        if (!$loggedIn) {
            $u['race'] = $u['race'] ?? RACE_UNKNOWN;
            $u['name'] = $u['name'] ?? '';
        }

        if ($loggedIn) {
            $u['hitpoints'] = round((int)$u['hitpoints'], 0);
            $u['experience'] = round((float)$u['experience'], 0);
            $u['maxhitpoints'] = round((int)$u['maxhitpoints'], 0);
            $spirits = array(-6 => "Resurrected",-2 => "Very Low",-1 => "Low","0" => "Normal",1 => "High",2 => "Very High");
            if ($u['alive']) {
            } else {
                $spirits[(int)$u['spirits']] = Translator::translateInline("DEAD", "stats");
            }
            //calculate_buff_fields();
            if (!isset($session['bufflist']) || !is_array($session['bufflist'])) {
                $session['bufflist'] = [];
            }
            reset($session['bufflist']);
            /*not so easy anymore
              $atk=$u['attack'];
              $def=$u['defense'];
             */
            $o_atk = $atk = PlayerFunctions::getPlayerAttack();
            $o_def = $def = PlayerFunctions::getPlayerDefense();
            $spd = PlayerFunctions::getPlayerSpeed();

            $buffcount = 0;
            $buffs = "";
            foreach ($session['bufflist'] as $val) {
                if (isset($val['suspended']) && $val['suspended']) {
                    continue;
                }
                if (isset($val['atkmod'])) {
                    $atk *= $val['atkmod'];
                }
                if (isset($val['defmod'])) {
                    $def *= $val['defmod'];
                }
                // Short circuit if the name is blank
                if ((isset($val['name']) && $val['name'] > "") || $session['user']['superuser'] & SU_DEBUG_OUTPUT) {
                    Translator::getInstance()->setSchema($val['schema']);
                    //  if ($val['name']=="")
                    //      $val['name'] = "DEBUG: {$key}";
                    //  removed due to performance reasons. foreach is better with only $val than to have $key ONLY for the short happiness of one debug. much greater performance gain here
                    if (is_array($val['name'])) {
                        $val['name'][0] = str_replace("`%", "`%%", $val['name'][0]);
                        $val['name'] = Translator::getInstance()->sprintfTranslate(...$val['name']);
                    } else { //in case it's a string
                        $val['name'] = Translator::translateInline($val['name']);
                    }
                    if ($val['rounds'] >= 0) {
                        // We're about to sprintf, so, let's makes sure that
                        // `% is handled.
                        //$n = Translator::translateInline(str_replace("`%","`%%",$val['name']));
                        $b = Translator::translateInline("`#%s `7(%s rounds left)`n", "buffs");
                        $b = sprintf($b, $val['name'], $val['rounds']);
                        $buffs .= $output->appoencode($b, true);
                    } else {
                        $buffs .= $output->appoencode("`#{$val['name']}`n", true);
                    }
                    Translator::getInstance()->setSchema();
                    $buffcount++;
                }
            }
            if ($buffcount == 0) {
                $buffs .= $output->appoencode(Translator::translateInline("`^None`0"), true);
            }

            $atk = round($atk, 2);
            $def = round($def, 2);
            if ($atk < $o_atk) {
                $atk = round($o_atk, 1) . "`\$" . round($atk - $o_atk, 1);
            } elseif ($atk > $o_atk) {
                $atk = round($o_atk, 1) . "`@+" . round($atk - $o_atk, 1);
            } else {
                // They are equal, display in the 1 signifigant digit format.
                $atk = round($atk, 1);
            }
            if ($def < $o_def) {
                $def = round($o_def, 1) . "`\$" . round($def - $o_def, 1);
            } elseif ($def > $o_def) {
                $def = round($o_def, 1) . "`@+" . round($def - $o_def, 1);
            } else {
                // They are equal, display in the 1 signifigant digit format.
                $def = round($def, 1);
            }
            $point = $settings->getSetting('moneydecimalpoint', ".");
            $sep = $settings->getSetting('moneythousandssep', ",");

            self::addCharStat("Character Info");
            self::addCharStat("Name", $u['name']);
            self::addCharStat("Level", "`b" . $u['level'] . PlayerFunctions::checkTempStat("level", 1) . "`b");
            // Note: Number formatting here has been introduced, but not in tempstats yet - I think it may be overhead for now, but could be done later
            if ($u['alive']) {
                self::addCharStat("Hitpoints", $u['hitpoints'] . PlayerFunctions::checkTempStat("hitpoints", 1) . "`0/" . $u['maxhitpoints'] . PlayerFunctions::checkTempStat("maxhitpoints", 1));
                self::addCharStat("Experience", number_format((float)$u['experience'], 0, $point, $sep) . PlayerFunctions::checkTempStat("experience", 1));
                self::addCharStat("Strength", $u['strength'] . PlayerFunctions::checkTempStat("strength", 1));
                self::addCharStat("Dexterity", $u['dexterity'] . PlayerFunctions::checkTempStat("dexterity", 1));
                self::addCharStat("Intelligence", $u['intelligence'] . PlayerFunctions::checkTempStat("intelligence", 1));
                self::addCharStat("Constitution", $u['constitution'] . PlayerFunctions::checkTempStat("constitution", 1));
                self::addCharStat("Wisdom", $u['wisdom'] . PlayerFunctions::checkTempStat("wisdom", 1));
                        self::addCharStat("Attack", $atk . "`\$<span title='" . PlayerFunctions::explainedGetPlayerAttack() . "'>(?)</span>`0" . PlayerFunctions::checkTempStat("attack", 1));
                        self::addCharStat("Defense", $def . "`\$<span title='" . PlayerFunctions::explainedGetPlayerDefense() . "'>(?)</span>`0" . PlayerFunctions::checkTempStat("defense", 1));
                self::addCharStat("Speed", $spd . PlayerFunctions::checkTempStat("speed", 1));
            } else {
                $maxsoul = 50 + 10 * $u['level'] + $u['dragonkills'] * 2;
                self::addCharStat("Soulpoints", $u['soulpoints'] . PlayerFunctions::checkTempStat("soulpoints", 1) . "`0/" . $maxsoul);
                self::addCharStat("Torments", $u['gravefights'] . PlayerFunctions::checkTempStat("gravefights", 1));
                self::addCharStat("Psyche", 10 + round(($u['level'] - 1) * 1.5));
                self::addCharStat("Spirit", 10 + round(($u['level'] - 1) * 1.5));
            }
            if ($u['race'] != RACE_UNKNOWN) {
                self::addCharStat("Race", Translator::translateInline($u['race'], "race"));
            } else {
                self::addCharStat("Race", Translator::translateInline(RACE_UNKNOWN, "race"));
            }
            if (is_array($companions) && count($companions) > 0) {
                self::addCharStat("Companions");
                foreach ($companions as $name => $companion) {
                    if ((isset($companion['hitpoints']) && $companion['hitpoints'] > 0) || (isset($companion['cannotdie']) && $companion['cannotdie'] == true)) {
                        if ($companion['hitpoints'] < 0) {
                            $companion['hitpoints'] = 0;
                        }
                        if ($companion['hitpoints'] < $companion['maxhitpoints']) {
                            $color = "`\$";
                        } else {
                            $color = "`@";
                        }
                        if (isset($companion['suspended']) && $companion['suspended'] == true) {
                            $suspcode = "`7 *";
                        } else {
                            $suspcode = "";
                        }
                        self::addCharStat($companion['name'], $color . ($companion['hitpoints']) . "`7/`&" . ($companion['maxhitpoints']) . "$suspcode`0");
                    }
                }
            }
            self::addCharStat("Personal Info");
            if ($u['alive']) {
                self::addCharStat("Turns", $u['turns'] . PlayerFunctions::checkTempStat("turns", 1));
                self::addCharStat("PvP", $u['playerfights']);
                self::addCharStat("Spirits", Translator::translateInline("`b" . $spirits[(int)$u['spirits']] . "`b"));
                self::addCharStat("Currency");
                self::addCharStat("Gold", number_format((int)$u['gold'], 0, $point, $sep) . PlayerFunctions::checkTempStat("gold", 1));
                self::addCharStat("Bankgold", number_format((int)$u['goldinbank'], 0, $point, $sep) . PlayerFunctions::checkTempStat("goldinbank", 1));
            } else {
                self::addCharStat("Favor", $u['deathpower'] . PlayerFunctions::checkTempStat("deathpower", 1));
                self::addCharStat("Currency");
            }
            self::addCharStat("Gems", number_format((int)$u['gems'], 0, $point, $sep) . PlayerFunctions::checkTempStat("gems", 1));
            self::addCharStat("Equipment Info");
            self::addCharStat("Weapon", $u['weapon']);
            self::addCharStat("Armor", $u['armor']);
            if ($u['hashorse'] && isset($mount['mountname'])) {
                self::addCharStat("Creature", $mount['mountname'] . "`0");
            }

            HookHandler::hook("charstats");

            $charstat = self::getCharStats($buffs);

            if (!is_array($session['bufflist'])) {
                $session['bufflist'] = array();
            }
            return $charstat;
        } else {
            $ret = "";
            $mode = (int) $settings->getSetting('homeonline_mode', 0);
            $minutesSetting = (int) $settings->getSetting('homeonline_minutes', 15);
            $loginTimeout = $settings->getSetting("LOGINTIMEOUT", 900);
            $cacheMinutes = $mode === 2 ? $minutesSetting : (int) ceil($loginTimeout / 60);
            $cacheKey = "charlisthomepage-$mode-$cacheMinutes";
            $ttl = $cacheMinutes * 60;
            $minutes = 0;
            if ($ret = DataCache::getInstance()->datacache($cacheKey, $ttl)) {
            } else {
                $onlinecount = 0;
                $list = HookHandler::hook("onlinecharlist", array("count" => 0, "list" => ""));
                if (isset($list['handled']) && $list['handled']) {
                    $onlinecount = $list['count'];
                    $ret = $list['list'];
                } else {
                    if ($mode === 2) {
                        $minutes = $minutesSetting;
                        $sql = "SELECT name,alive,location,sex,level,laston,loggedin,lastip,uniqueid FROM " . Database::prefix("accounts") . " WHERE locked=0 AND laston>'" . date("Y-m-d H:i:s", strtotime("-" . $minutes . " minutes")) . "' ORDER BY level DESC";
                    } else {
                        $sql = "SELECT name,alive,location,sex,level,laston,loggedin,lastip,uniqueid FROM " . Database::prefix("accounts") . " WHERE locked=0 AND loggedin=1 AND laston>'" . date("Y-m-d H:i:s", strtotime("-" . $loginTimeout . " seconds")) . "' ORDER BY level DESC";
                    }
                    $result = Database::query($sql);
                    $rows = array();
                    while ($row = Database::fetchAssoc($result)) {
                        $rows[] = $row;
                    }
                    Database::freeResult($result);
                    $rows = HookHandler::hook("loggedin", $rows);
                    if ($mode === 0) {
                        $ret .= $output->appoencode(sprintf(Translator::translateInline("`bOnline Characters (%s players):`b`n"), count($rows)));
                    } elseif ($mode === 1) {
                        $timeLabel = DateTime::readableTime($loginTimeout, false);
                        $ret .= $output->appoencode(sprintf(Translator::translateInline("`bOnline Characters in the last %s:`b`n"), $timeLabel));
                    } else {
                        $ret .= $output->appoencode(sprintf(Translator::translateInline("`bOnline Characters last %s minutes:`b`n"), $minutes));
                    }
                    foreach ($rows as $row) {
                        $ret .= $output->appoencode("`^{$row['name']}`n");
                        $onlinecount++;
                    }
                    if ($onlinecount == 0) {
                        $ret .= $output->appoencode(Translator::translateInline("`iNone`i"));
                    }
                }
                if (Settings::hasInstance()) {
                    $settings->saveSetting("OnlineCount", $onlinecount);
                    $settings->saveSetting("OnlineCountLast", strtotime("now"));
                }
                DataCache::getInstance()->updatedatacache($cacheKey, $ret);
            }
            return $ret;
        }
    }

/**
 * Returns a display formatted (and popup enabled) mail link - determines if unread mail exists and highlights the link if needed
 *
 * @return string The formatted mail link
 */
    public static function mailLink()
    {
        if (! self::isAuthenticated()) {
            return '';
        }

        $row = self::getMailCounts();

        if ($row['notseen'] > 0) {
            return sprintf("<a href='mail.php' target='_blank' onClick=\"" . self::popup("mail.php") . ";return false;\" class='hotmotd'>" . Translator::translateInline("Ye Olde Mail: %s new, %s old", "common") . "</a>", $row['notseen'], $row['seencount']);
        }

        return sprintf("<a href='mail.php' target='_blank' onClick=\"" . self::popup("mail.php") . ";return false;\" class='motd'>" . Translator::translateInline("Ye Olde Mail: %s new, %s old", "common") . "</a>", $row['notseen'], $row['seencount']);
    }

/* same, but only the text for the tab */
    public static function mailLinkTabText()
    {
        if (! self::isAuthenticated()) {
            return '';
        }

        $row = self::getMailCounts();

        if ($row['notseen'] > 0) {
            return sprintf(Translator::translateInline("%s new mail(s)", "common"), $row['notseen']);
        }

        return '';
    }

    /**
     * Retrieve mail counts for the current user.
     *
     * @return array{seencount:int, notseen:int}|null
     */
    private static function getMailCounts(): ?array
    {
        global $session;
        $output = Output::getInstance();
        static $counts = null;

        if ($counts !== null) {
            return $counts;
        }

        if (! self::isAuthenticated()) {
            return null;
        }

        $sql = "SELECT sum(if(seen=1,1,0)) AS seencount, sum(if(seen=0,1,0)) AS notseen FROM "
            . Database::prefix("mail")
            . " WHERE msgto=\"" . $session['user']['acctid'] . "\"";

        $result = Database::queryCached($sql, "mail-{$session['user']['acctid']}", 86400);
        $row = Database::fetchAssoc($result);
        Database::freeResult($result);

        $counts = [
            'seencount' => (int) $row['seencount'],
            'notseen'   => (int) $row['notseen'],
        ];

        return $counts;
    }

    private static function isAuthenticated(): bool
    {
        global $session;

        return isset($session['user']['acctid']);
    }

    /**
     * Build the Paypal donation HTML snippet and replace the appropriate placeholder.
     *
     * @param string      $palreplace   Placeholder to replace
     * @param string      $header       Header template fragment
     * @param string      $footer       Footer template fragment
     * @param Settings|null $settings   Settings handler or null
     * @param string      $logd_version Current game version string
     */
    public static function buildPaypalDonationMarkup(
        string $palreplace,
        string $header,
        string $footer,
        ?Settings $settings,
        string $logd_version
    ): array {
        global $session;

        $paypalstr = '<table align="center"><tr><td>';
        $currency = (isset($settings) && defined('DB_CHOSEN'))
            ? $settings->getSetting('paypalcurrency', 'USD')
            : 'USD';

        $laston = $session['user']['laston'] ?? '1970-01-01 00:00:00';

        if (
            !isset($_SESSION['logdnet'])
            || !isset($_SESSION['logdnet'][''])
            || $_SESSION['logdnet'][''] == ''
            || date('Y-m-d H:i:s', strtotime('-1 hour')) > $laston
        ) {
            $already_registered_logdnet = false;
        } else {
            $already_registered_logdnet = true;
        }

        if (isset($settings) && $settings->getSetting('logdnet', 0) && $session['user']['loggedin'] && !$already_registered_logdnet) {
            $sql = "SELECT count(acctid) AS c FROM " . Database::prefix('accounts');
            $result = Database::queryCached($sql, 'acctcount', 600);
            $row = Database::fetchAssoc($result);
            $c = $row['c'];
            $a = $settings->getSetting('serverurl', 'http://' . $_SERVER['SERVER_NAME'] . ($_SERVER['SERVER_PORT'] == 80 ? '' : ':' . $_SERVER['SERVER_PORT']) . dirname($_SERVER['REQUEST_URI']));
            if (!preg_match('/\/$/', $a)) {
                $a = $a . '/';
                $settings->saveSetting('serverurl', $a);
            }

            $l = $settings->getSetting('defaultlanguage', 'en');
            $d = $settings->getSetting('serverdesc', 'Another LoGD Server');
            $e = $settings->getSetting('gameadminemail', 'postmaster@localhost.com');
            $u = $settings->getSetting('logdnetserver', 'http://lotgd.net/');
            if (!preg_match('/\/$/', $u)) {
                $u = $u . '/';
                $settings->saveSetting('logdnetserver', $u);
            }

            $v = $logd_version;
            $c = rawurlencode((string) $c);
            $a = rawurlencode($a);
            $l = rawurlencode($l);
            $d = rawurlencode($d);
            $e = rawurlencode($e);
            $v = rawurlencode($v);
            $u = rawurlencode($u);
            $paypalstr .= "<script defer type='text/javascript' charset='UTF-8' src='images/logdnet.php?op=register&c=$c&l=$l&v=$v&a=$a&d=$d&e=$e&u=$u'></script>";
        } else {
            $paypalstr .= "<form action=\"https://www.paypal.com/cgi-bin/webscr\" method=\"post\" target=\"_blank\" onsubmit=\"return confirm('You are donating to the author of Lotgd. Donation points can not be credited unless you petition. Press Ok to make a donation, or press Cancel.');\">" .
                "<input type='hidden' name='cmd' value='_xclick'>" .
                "<input type='hidden' name='business' value='logd@mightye.org'>" .
                "<input type='hidden' name='item_name' value='Legend of the Green Dragon Author Donation from " . Sanitize::fullSanitize($session['user']['name']) . "'>" .
                "<input type='hidden' name='item_number' value='" . htmlentities($session['user']['login'], ENT_COMPAT, isset($settings) ? $settings->getSetting('charset', 'UTF-8') : 'UTF-8') . ":" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . "'>" .
                "<input type='hidden' name='no_shipping' value='1'>" .
                "<input type='hidden' name='notify_url' value='http://lotgd.net/payment.php'>" .
                "<input type='hidden' name='cn' value='Your Character Name'>" .
                "<input type='hidden' name='cs' value='1'>" .
                "<input type='hidden' name='currency_code' value='USD'>" .
                "<input type='hidden' name='tax' value='0'>" .
                "<input type='image' src='images/paypal1.gif' border='0' name='submit' alt='Donate to Eric Stevens'>" .
                "</form>";
        }
        $paysite = isset($settings) ? $settings->getSetting('paypalemail', '') : '';
        if ($paysite != '') {
            $paypalstr .= '</td><td>';
            $paypalstr .= '<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_blank">'
                . "<input type='hidden' name='cmd' value='_xclick'>"
                . "<input type='hidden' name='business' value='$paysite'>"
                . "<input type='hidden' name='item_name' value='" . (isset($settings) ? $settings->getSetting('paypaltext', 'Legend of the Green Dragon Site Donation from') : 'Legend of the Green Dragon Site Donation From') . " " . Sanitize::fullSanitize($session['user']['name']) . "'>"
                . "<input type='hidden' name='item_number' value='" . htmlentities($session['user']['login'], ENT_COMPAT, isset($settings) ? $settings->getSetting('charset', 'UTF-8') : 'UTF-8') . ":" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . "'>"
                . "<input type='hidden' name='no_shipping' value='1'>";
            if (file_exists('payment.php')) {
                $paypalstr .= "<input type='hidden' name='notify_url' value='http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/payment.php'>";
            }
            $paypalstr .= "<input type='hidden' name='cn' value='Your Character Name'>"
                . "<input type='hidden' name='cs' value='1'>"
                . "<input type='hidden' name='currency_code' value='$currency'>"
                . "<input type='hidden' name='lc' value='" . $settings->getSetting('paypalcountry-code', 'US') . "'>"
                . "<input type='hidden' name='bn' value='PP-DonationsBF'>"
                . "<input type='hidden' name='tax' value='0'>"
                . "<input type='image' src='images/paypal2.gif' border='0' name='submit' alt='Donate to the Site'>"
                . '</form>';
        }
        $paypalstr .= '</td></tr></table>';

        $replacement = (strpos($palreplace, 'paypal') ? '' : '{stats}') . $paypalstr;
        $token = trim($palreplace, '{}');

        return self::replaceHeaderFooterTokens(
            $header,
            $footer,
            [
                $token   => $replacement,
                'paypal' => $paypalstr,
            ]
        );
    }

    /**
     * Generate the mail link markup and populate placeholders.
     *
     * @api
     */
    public static function assembleMailLink(string $header, string $footer): array
    {
        global $session;

        $settings = Settings::getInstance();

        $mailHtml = '';
        if (isset($session['user']['acctid']) && $session['user']['acctid'] > 0 && $session['user']['loggedin']) {
            if ($settings->getSetting('ajax', 0) == 1 && isset($session['user']['prefs']['ajax']) && $session['user']['prefs']['ajax']) {
                $maillink_add_pre = '';
                $maillink_add_after = '';
                $setupFile = __DIR__ . '/../../async/setup.php';
                if (file_exists($setupFile)) {
                    set_error_handler(fn () => true, E_USER_WARNING);
                    require_once $setupFile;
                    restore_error_handler();
                }
                $asyncFile = __DIR__ . '/../../async/maillink.php';
                if (file_exists($asyncFile)) {
                    require $asyncFile;
                }
                $mailHtml = $maillink_add_pre . "<div id='maillink'>" . self::mailLink() . "</div>" . $maillink_add_after;
            } else {
                $mailHtml = self::mailLink();
            }
        }

        return self::replaceHeaderFooterTokens(
            $header,
            $footer,
            ['mail' => $mailHtml]
        );
    }

    /**
     * Generate the petition link markup and populate placeholders.
     *
     * @internal This method is intended for internal use only.
     */
    public static function assemblePetitionLink(string $header, string $footer): array
    {
        $link = "<a href='petition.php' onClick=\"" . self::popup('petition.php') . ";return false;\" target='_blank' align='right' class='motd'>" . Translator::translateInline('Petition for Help') . "</a>";

        return self::replaceHeaderFooterTokens(
            $header,
            $footer,
            ['petition' => $link]
        );
    }

    /**
     * Generate the petition administration display section.
     *
     * @internal This method is intended for internal use only and should not be relied upon as part of the public API.
     */
    public static function assemblePetitionDisplay(string $header, string $footer): array
    {
        global $session;
        $output = Output::getInstance();

        $pcount = '';
        if (isset($session['user']['superuser']) && $session['user']['superuser'] & SU_EDIT_PETITIONS) {
            $sql = "SELECT count(petitionid) AS c,status FROM " . Database::prefix('petitions') . " GROUP BY status";
            $result = Database::queryCached($sql, 'petition_counts');
            $petitions = array('P5' => 0,'P4' => 0,'P0' => 0,'P1' => 0,'P3' => 0,'P7' => 0,'P6' => 0,'P2' => 0);
            while ($row = Database::fetchAssoc($result)) {
                $petitions['P' . $row['status']] = $row['c'];
            }
            $pet = Translator::translateInline('`0`bPetitions:`b');
            $ued = Translator::translateInline('`0`bUser Editor`b');
            $mod = Translator::translateInline('`0`bManage Modules`b');
            Database::freeResult($result);
            $admin_array = array();
            if ($session['user']['superuser'] & SU_EDIT_USERS) {
                $admin_array[] = "<a href='user.php'>$ued</a>";
                Nav::add('', 'user.php');
            }
            if ($session['user']['superuser'] & SU_MANAGE_MODULES) {
                $admin_array[] = "<a href='modules.php'>$mod</a>";
                Nav::add('', 'modules.php');
            }
            $admin_array[] = "<a href='viewpetition.php'>$pet</a>";
            Nav::add('', 'viewpetition.php');
            $p = implode('|', $admin_array);
            $pcolors = array('`$','`^','`6','`!','`#','`%','`v');
            $pets = '`n';
            foreach ($petitions as $val) {
                if ($pets != '`n') {
                    $pets .= '|';
                }
                $color = array_shift($pcolors) ?: '`1';
                $pets .= $color . $val . '`0';
            }
            $ret_args = array('petitioncount' => $pets);
            $ret_args = HookHandler::hook('petitioncount', $ret_args);
            $pets = $ret_args['petitioncount'];
            $p .= $pets;
            $pcount = Template::templateReplace('petitioncount', ['petitioncount' => $output->appoencode($p, true)]);
        }

        return self::replaceHeaderFooterTokens(
            $header,
            $footer,
            ['petitiondisplay' => $pcount]
        );
    }

    /**
     * Insert the navigation output into header and footer strings.
     * @api
     */
    public static function generateNavigationOutput(string $header, string $footer, string $builtnavs): array
    {
        return self::replaceHeaderFooterTokens(
            $header,
            $footer,
            ['nav' => $builtnavs]
        );
    }

    /**
     * Run module hooks for the footer and replace placeholders.
     */
    public static function applyFooterHooks(string $header, string $footer, string $script): array
    {
        // Gather module hook results for footer replacements
        $replacementbits = HookHandler::hook("footer-$script", []);
        if ($script == 'runmodule' && (($module = Http::get('module'))) > '') {
            $replacementbits = HookHandler::hook("footer-$module", $replacementbits);
        }
        $replacementbits['__scriptfile__'] = $script;
        $replacementbits = HookHandler::hook('everyfooter', $replacementbits);
        unset($replacementbits['__scriptfile__']);

        // Build a simple token => string mapping
        $replacements = [];
        foreach ($replacementbits as $key => $val) {
            $replacements[$key] = implode('', $val);
        }

        return self::replaceHeaderFooterTokens($header, $footer, $replacements);
    }

    /**
     * Run popup footer hooks and apply replacements.
     */
    public static function applyPopupFooterHooks(string $header, string $footer): array
    {
        $replacementbits = HookHandler::hook('footer-popup', []);

        $replacements = [];
        foreach ($replacementbits as $key => $val) {
            $replacements[$key] = implode('', $val);
        }

        return self::replaceHeaderFooterTokens($header, $footer, $replacements);
    }

    /**
     * Insert head script markup into the header.
     *
     * @internal This method is intended for internal use only and should not be used directly by external code.
     */
    public static function insertHeadScript(string $header, string $preHeadscript, string $headscript): string
    {
        $markup = $preHeadscript;
        if (!empty($headscript)) {
            $markup .= "<script type='text/javascript' charset='UTF-8'>" . $headscript . '</script>';
        }

        return self::applyTemplateStringReplacements(
            $header,
            'header',
            ['headscript' => $markup]
        );
    }

    /**
     * Generate the canonical link element for the current page.
     */
    public static function canonicalLink(): string
    {
        $settings = Settings::getInstance();

        $serverUrl = rtrim($settings->getSetting('serverurl', 'http://' . $_SERVER['HTTP_HOST']), '/');

        $uri = PhpGenericEnvironment::getRequestUri();
        if ($uri === '') {
            $uri = PhpGenericEnvironment::getScriptName();
        }

        // Remove the session "c" parameter while keeping the rest intact
        $parsedUrl = parse_url($uri);
        if ($parsedUrl === false) {
            // Handle the malformed URL case
            $parsedUrl = [];
        }
        $queryString = $parsedUrl['query'] ?? '';
        if (is_string($queryString)) {
            parse_str($queryString, $queryParams);
        } else {
            $queryParams = [];
        }
        unset($queryParams['c']); // Remove the 'c' parameter
        if (empty($queryParams)) {
            unset($parsedUrl['query']);
        } else {
            $parsedUrl['query'] = http_build_query($queryParams);
        }
        $uri = ($parsedUrl['path'] ?? '') . (!empty($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '');

        $page = ltrim($uri, '/');

        return sprintf('<link rel="canonical" href="%s/%s" />', $serverUrl, $page);
    }

    /**
     * Strip advertisement placeholders from the header.
     *
     * @internal This method is intended for internal use only and is not part of the public API.
     */
    public static function stripAdPlaceholders(string $header): string
    {
        $header = str_replace('{bodyad}', '', $header);
        $header = str_replace('{verticalad}', '', $header);
        $header = str_replace('{navad}', '', $header);
        $header = str_replace('{headerad}', '', $header);

        return $header;
    }

    /**
     * Replace placeholder tokens in header and footer strings using
     * Template-style warnings for missing fields.
     *
     * @param string $header Original header markup
     * @param string $footer Original footer markup
     * @param array  $replacements Associative array of token => value pairs
     *
     * @return array Array with the processed header and footer
     * @internal This method is intended for internal use only.
     */
    public static function replaceHeaderFooterTokens(string $header, string $footer, array $replacements): array
    {
        $header = self::applyTemplateStringReplacements($header, 'header', $replacements);
        $footer = self::applyTemplateStringReplacements($footer, 'footer', $replacements);

        return [$header, $footer];
    }

    /**
     * Apply template style replacements to a raw string.
     * Adds a warning in the output if the placeholder is missing.
     *
     * @param string $content  Template fragment to process
     * @param string $name     Fragment identifier used in warnings
     * @param array  $replacements List of token => value pairs
     */
    public static function applyTemplateStringReplacements(string $content, string $name, array $replacements): string
    {
        foreach ($replacements as $key => $val) {
            if (TwigTemplate::isActive()) {
                self::$twigVars[$key] = $val;
            }

            if (strpos($content, '{' . $key . '}') === false) {
                // If we don't find the key in the content, we can skip it - if you want to notify, use the line below
                //output("`bWarning:`b the `i%s`i piece was not found in the `i%s`i template part! (%s)`n", $key, $name, $content);
                continue;
            } else {
                $content = str_replace('{' . $key . '}', $val, $content);
            }
        }

        return $content;
    }

    /**
     * Compute the page generation statistics string.
     *
     * @internal This method is intended for internal use only and should not be relied upon as part of the public API.
     */
    public static function computePageGenerationStats(float $pagestarttime): string
    {
        global $session;
        $settings = Settings::getInstance();

        $gentime = DateTime::getMicroTime() - $pagestarttime;
        if (!isset($session['user']['gentime'])) {
            $session['user']['gentime'] = 0;
        }
        $session['user']['gentime'] += $gentime;
        if (!isset($session['user']['gentimecount'])) {
            $session['user']['gentimecount'] = 0;
        }
        $session['user']['gentimecount']++;
        if ($settings->getSetting('debug', 0)) {
            $sql = "INSERT INTO " . Database::prefix('debug') . " VALUES (0,'pagegentime','runtime','" . PhpGenericEnvironment::getScriptName() . "','" . ($gentime) . "');";
            Database::query($sql);
            $sql = "INSERT INTO " . Database::prefix('debug') . " VALUES (0,'pagegentime','dbtime','" . PhpGenericEnvironment::getScriptName() . "','" . (round(Database::getInfo('querytime', 0), 3)) . "');";
            Database::query($sql);
        }
        $queryCount = Database::getQueryCount();
        $querytime = Database::getInfo('querytime', 0);

        return "Page gen: " . round($gentime, 3) . "s / " . $queryCount . " queries (" . round($querytime, 3) . "s), Ave: " . round($session['user']['gentime'] / $session['user']['gentimecount'], 3) . "s - " . round($session['user']['gentime'], 3) . "/" . round($session['user']['gentimecount'], 3);
    }


/**
 * Returns a display formatted (and popup enabled) MOTD link - determines if unread MOTD items exist and highlights the link if needed
 *
 * @return string The formatted MOTD link
 */
    public static function motdLink()
    {
        global $session;
        if (isset($session['needtoviewmotd']) && $session['needtoviewmotd']) {
            return "<a href='motd.php' target='_blank' onClick=\"" . self::popup("motd.php") . ";return false;\" class='hotmotd'><b>" . Translator::translateInline("MoTD") . "</b></a>";
        } else {
            return "<a href='motd.php' target='_blank' onClick=\"" . self::popup("motd.php") . ";return false;\" class='motd'><b>" . Translator::translateInline("MoTD") . "</b></a>";
        }
    }
}
