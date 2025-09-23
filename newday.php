<?php

use Lotgd\MySQL\Database;
use Lotgd\Settings;
use Lotgd\Translator;
use Lotgd\Buffs;
use Lotgd\Newday;
use Lotgd\MountName;
use Lotgd\Mounts;
use Lotgd\Names;
use Lotgd\Battle;
use Lotgd\Substitute;
use Lotgd\AddNews;
use Lotgd\DataCache;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;
use Lotgd\Output;
use Lotgd\Sanitize;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";

$output = Output::getInstance();
$settings = Settings::getInstance();

Translator::getInstance()->setSchema("newday");
//mass_module_prepare(array("newday-intercept", "newday"));
HookHandler::hook("newday-intercept", array());

/***************
 **  SETTINGS **
 ***************/
$turnsperday = $settings->getSetting('turns', 10);
$maxinterest = ((float)$settings->getSetting('maxinterest', 10) / 100); //0.1;
$mininterest = ((float)$settings->getSetting('mininterest', 1) / 100); //0.1;
$dailypvpfights = $settings->getSetting('pvpday', 3);

$resline = (Http::get('resurrection') == "true") ? "&resurrection=true" : "" ;
/******************
 ** End Settings **
 ******************/
$dk = Http::get('dk');
if (
    (count($session['user']['dragonpoints']) <
            $session['user']['dragonkills']) && $dk != ""
) {
    array_push($session['user']['dragonpoints'], $dk);
    switch ($dk) {
        case "str":
            $session['user']['strength']++;
            break;
        case "dex":
            $session['user']['dexterity']++;
            break;
        case "con":
            $session['user']['constitution']++;
            break;
        case "int":
            $session['user']['intelligence']++;
            break;
        case "wis":
            $session['user']['wisdom']++;
            break;
        case "hp":
            $session['user']['maxhitpoints'] += 5;
            break;
        //legacy support
        case "at":
            $session['user']['attack']++;
            break;
        case "de":
            $session['user']['defense']++;
            break;
    }
}

$labels = array(
        "General Stuff,title",
        "hp" => "Max Hitpoints + 5",
        "ff" => "Forest Fights + 1",
        "Attributes,title",
        "str" => "Strength +1",
        "dex" => "Dexterity +1",
        "con" => "Constitution +1",
        "int" => "Intelligence +1",
        "wis" => "Wisdom +1",
        /*Legacy Support, you cannot buy them anymore*/
        /*well, to be precise you can if you write a module that sets $canbuy['at']=1 ;) */
        "at" => "Attack + 1",
        "de" => "Defense + 1",
        "unknown" => "Unknown Spends (contact an admin to investigate!)",
);
$canbuy = array(
        "hp" => 1,
        "ff" => 1,
        "str" => 1,
        "dex" => 1,
        "con" => 1,
        "int" => 1,
        "wis" => 1,
        "at" => 0,
        "de" => 0,
        "unknown" => 0,
);
$retargs = HookHandler::hook("dkpointlabels", array('desc' => $labels, 'buy' => $canbuy));
$labels = $retargs['desc'];
$canbuy = $retargs['buy'];

$pdk = Http::get("pdk");

$dp = count($session['user']['dragonpoints']);
$dkills = $session['user']['dragonkills'];

if ($pdk == 1) {
        Newday::dragonPointRecalc($labels, $dkills, $dp);
}

if ($dp < $dkills) {
        Newday::dragonPointSpend($labels, $canbuy, $dkills, $dp, $resline);
} elseif (!$session['user']['race'] || $session['user']['race'] == RACE_UNKNOWN) {
        Newday::setRace($resline);
} elseif ($session['user']['specialty'] == "") {
        Newday::setSpecialty($resline);
} else {
    Header::pageHeader("It is a new day!");
    $output->rawOutput("<font size='+1'>");
    $output->output("`c`b`#It is a New Day!`0`b`c");
    $output->rawOutput("</font>");
    $resurrection = Http::get('resurrection');

    if ($session['user']['alive'] != true) {
        $session['user']['resurrections']++;
        $output->output("`@You are resurrected!  This is resurrection number %s.`0`n", $session['user']['resurrections']);
        $session['user']['alive'] = true;
        DataCache::getInstance()->invalidatedatacache("list.php-warsonline");
    }
    $session['user']['age']++;
    $session['user']['seenmaster'] = 0;
    $output->output("You open your eyes to discover that a new day has been bestowed upon you. It is day number `^%s.`0", $session['user']['age']);
    $output->output("You feel refreshed enough to take on the world!`n");
    $output->output("`2Turns for today set to `^%s`2.`n", $turnsperday);

    $turnstoday = "Base: $turnsperday";
    $args = HookHandler::hook(
        "pre-newday",
        array("resurrection" => $resurrection, "turnstoday" => $turnstoday)
    );
    $turnstoday = $args['turnstoday'];

    $interestrate = e_rand($mininterest * 100, $maxinterest * 100) / (float)100;
    if ($session['user']['turns'] > $settings->getSetting('fightsforinterest', 4) && $session['user']['goldinbank'] >= 0) {
        $interestrate = 0;
        $output->output("`2Today's interest rate: `^0% (Bankers in this village only give interest to those who work for it)`2.`n");
    } elseif ($settings->getSetting('maxgoldforinterest', 100000) && $session['user']['goldinbank'] >= $settings->getSetting('maxgoldforinterest', 100000)) {
        $interestrate = 0;
        $output->output("`2Today's interest rate: `^0%% (The bank will not pay interest on accounts equal or greater than %s to retain solvency)`2.`n", $settings->getSetting('maxgoldforinterest', 100000));
    } else {
        $output->output("`2Today's interest rate: `^%s%% `n", ($interestrate) * 100);
        if ($session['user']['goldinbank'] >= 0) {
            $output->output("`2Gold earned from interest: `^%s`2.`n", (int)($session['user']['goldinbank'] * ($interestrate)));
        } else {
            $output->output("`2Interest Accrued on Debt: `^%s`2 gold.`n", -(int)($session['user']['goldinbank'] * ($interestrate)));
        }
    }

    //clear all standard buffs
    $tempbuf = unserialize($session['user']['bufflist'], ['allowed_classes' => false]);
    if ($tempbuf === false) {
        trigger_error('Failed to unserialize bufflist.', E_USER_WARNING);
        $tempbuf = [];
    }
    $session['user']['bufflist'] = "";
    Buffs::stripAllBuffs();
    Translator::getInstance()->setSchema("buffs");
    if (is_array($tempbuf)) {
        foreach ($tempbuf as $key => $val) {
            if (
                array_key_exists('survivenewday', $val) &&
                    $val['survivenewday'] == 1
            ) {
                //$session['bufflist'][$key]=$val;
                if (array_key_exists('schema', $val) && $val['schema']) {
                    Translator::getInstance()->setSchema($val['schema']);
                }
                Buffs::applyBuff($key, $val);
                if (
                    array_key_exists('newdaymessage', $val) &&
                        $val['newdaymessage']
                ) {
                    $output->output($val['newdaymessage']);
                    $output->outputNotl("`n");
                }
                if (array_key_exists('schema', $val) && $val['schema']) {
                    Translator::getInstance()->setSchema();
                }
            }
        }
    }
    Translator::getInstance()->setSchema();

    $output->output("`2Hitpoints have been restored to `^%s`2.`n", $session['user']['maxhitpoints']);

    $dkff = 0;
    if (is_array($session['user']['dragonpoints'])) {
        reset($session['user']['dragonpoints']);
        foreach ($session['user']['dragonpoints'] as $val) {
            if ($val == "ff") {
                $dkff++;
            }
        }
    }
    if ($session['user']['hashorse']) {
        $mount = Mounts::getInstance()->getPlayerMount();
        $buff  = unserialize($mount['mountbuff']);
        if (!isset($buff['schema']) || $buff['schema'] == "") {
            $buff['schema'] = "mounts";
        }
        Buffs::applyBuff('mount', $buff);
    }
    if ($dkff > 0) {
        $output->output(
            "`n`2You gain `^%s`2 forest %s from spent dragon points!",
            $dkff,
            Translator::translateInline($dkff == 1 ? 'fight' : 'fights')
        );
    }
    $r1 = e_rand(-1, 1);
    $r2 = e_rand(-1, 1);
    $spirits = $r1 + $r2;
    $resurrectionturns = $spirits;
    if ($resurrection == "true") {
        AddNews::add("`&%s`& has been resurrected by %s`&.", $session['user']['name'], $settings->getSetting('deathoverlord', '`$Ramius'));
        $spirits = -6;
        $resurrectionturns = $settings->getSetting('resurrectionturns', -6);
        if (strstr($resurrectionturns, '%')) {
            $resurrectionturns = strtok($resurrectionturns, '%');
            $resurrectionturns = (int)$resurrectionturns;
            if ($resurrectionturns < -100) {
                $resurrectionturns = -100;
            }
            $resurrectionturns = round(($turnsperday + $dkff) * ($resurrectionturns / 100), 0);
        } else {
            if ($resurrectionturns < -($turnsperday + $dkff)) {
                $resurrectionturns = -($turnsperday + $dkff);
            }
        }
        $session['user']['deathpower'] -= $settings->getSetting('resurrectioncost', 100);
        $session['user']['restorepage'] = "village.php?c=1";
    }

    $sp = array((-6) => "Resurrected", (-2) => "Very Low", (-1) => "Low",
            (0) => "Normal", 1 => "High", 2 => "Very High");
    $sp = Translator::translateInline($sp);
    $output->output("`n`2You are in `^%s`2 spirits today!`n", $sp[$spirits]);
    if (abs($spirits) > 0) {
        if ($resurrectionturns > 0) {
            $gain = Translator::translateInline('gain');
        } else {
            $gain = Translator::translateInline('lose');
        }
        $sff = abs($resurrectionturns);
        $output->output(
            "`2As a result, you `^%s %s forest %s`2 for today!`n",
            $gain,
            $sff,
            Translator::translateInline($sff == 1 ? 'fight' : 'fights')
        );
    }
    $rp = rtrim(Sanitize::cmdSanitize($session['user']['restorepage']), '&?');
    if (substr($rp, 0, 10) == "badnav.php") {
        Nav::add("Continue", "news.php");
    } else {
        Nav::add("Continue", $rp);
    }

    $session['user']['laston'] = date("Y-m-d H:i:s");
    $interest_amount = (int) round($session['user']['goldinbank'] * $interestrate, 0);
    $debtfloor = $settings->getSetting('debtfloor', -50000);
    if ($session['user']['goldinbank'] + $interest_amount < $debtfloor) {
        //debtfloor reached set to floor
        $session['user']['goldinbank'] = $debtfloor;
        $output->output("You are so much in debt, the elders won't let you drop further. Your bank gold has been set to %s gold.`n", $debtfloor);
        debug("Set debtfloor in bank " . $debtfloor);
    } else {
        //manage interest
        $session['user']['goldinbank'] += $interest_amount;
    }

    if ($interest_amount != 0) {
        debuglog(($interest_amount >= 0 ? "earned " : "paid ") . abs($interest_amount) . " gold in interest");
    }
    $turnstoday .= ", Spirits: $resurrectionturns, DK: $dkff";
    $session['user']['turns'] = $turnsperday + $resurrectionturns + $dkff;
    $session['user']['hitpoints'] = $session['user']['maxhitpoints'];
    $session['user']['spirits'] = $spirits;
    if ($resurrection != "true") {
        $session['user']['playerfights'] = $dailypvpfights;
    }
    $session['user']['transferredtoday'] = 0;
    $session['user']['amountouttoday'] = 0;
    $session['user']['seendragon'] = 0;
    $session['user']['seenmaster'] = 0;
    $session['user']['fedmount'] = 0;
    if ($resurrection != "true") {
        $session['user']['soulpoints'] = 50 + 10 * $session['user']['level'] + $session['user']['dragonkills'] * 2;
        $session['user']['gravefights'] = $settings->getSetting('gravefightsperday', 10);
    }
    $session['user']['boughtroomtoday'] = 0;
    $session['user']['recentcomments'] = $session['user']['lasthit'];
    $session['user']['lasthit'] = gmdate("Y-m-d H:i:s");
    if ($session['user']['hashorse']) {
        $mount = Mounts::getInstance()->getPlayerMount();
        $msg   = $mount['newday'];
        $msg   = Substitute::applyArray("`n`&" . $msg . "`0`n");
        $output->output($msg);
                list($name, $lcname) = MountName::getmountname();

        $mff = (int) $mount['mountforestfights'];
        $session['user']['turns'] += $mff;
        $turnstoday .= ", Mount: $mff";
        if ($mff > 0) {
            $state = Translator::translateInline('gain');
            $color = "`^";
        } elseif ($mff < 0) {
            $state = Translator::translateInline('lose');
            $color = "`$";
        }
        $mff = abs($mff);
        if ($mff != 0) {
            $output->output("`n`&Because of %s`&, you %s%s %s`& forest %s for today!`n`0", $lcname, $color, $state, $mff, Translator::translateInline($mff == 1 ? 'fight' : 'fights'));
        }
    } else {
        $output->output("`n`&You strap your `%%s`& to your back and head out for some adventure.`0", $session['user']['weapon']);
    }

        Battle::unsuspendCompanions("allowinshades");

    if (!$settings->getSetting('newdaycron', 0)) {
        //check last time we did this vs now to see if it was a different game day.
        $lastnewdaysemaphore = convertgametime(strtotime($settings->getSetting('newdaySemaphore', DATETIME_DATEMIN) . " +0000"));
        $gametoday = gametime();
        if (gmdate("Ymd", $gametoday) != gmdate("Ymd", $lastnewdaysemaphore)) {
                // it appears to be a different game day, acquire semaphore and
                // check again.
            $sql = "LOCK TABLES " . Database::prefix("settings") . " WRITE";
            Database::query($sql);
            $settings->clearSettings();
            $lastnewdaysemaphore = convertgametime(strtotime($settings->getSetting('newdaySemaphore', DATETIME_DATEMIN) . " +0000"));
                $gametoday = gametime();
            if (gmdate("Ymd", $gametoday) != gmdate("Ymd", $lastnewdaysemaphore)) {
                //we need to run the hook, update the setting, and unlock.
                $settings->saveSetting('newdaySemaphore', gmdate("Y-m-d H:i:s"));
                $sql = "UNLOCK TABLES";
                Database::query($sql);
                Newday::runOnce();
            } else {
                //someone else beat us to it, unlock.
                $sql = "UNLOCK TABLES";
                Database::query($sql);
            }
        }
    }
    $args = HookHandler::hook(
        "newday",
        array("resurrection" => $resurrection, "turnstoday" => $turnstoday)
    );
    $turnstoday = $args['turnstoday'];
    debuglog("New Day Turns: $turnstoday");

    //legacy support if you have no playername set
    if ($session['user']['playername'] == '') {
        //set it
        $session['user']['playername'] = Names::getPlayerBasename(false);
    }
}
Footer::pageFooter();
