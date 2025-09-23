<?php

use Lotgd\DateTime;
use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Commentary;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;
use Lotgd\Events;
use Lotgd\PlayerFunctions;
use Lotgd\Settings;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";

$settings = Settings::getInstance();

$translator = Translator::getInstance();

$translator->setSchema('village');
//mass_module_prepare(array("village","validlocation","villagetext","village-desc"));
// See if the user is in a valid location and if not, put them back to
// a place which is valid
$valid_loc = array();
$vname = $settings->getSetting('villagename', LOCATION_FIELDS);
$iname = $settings->getSetting('innname', LOCATION_INN);
$valid_loc[$vname] = "village";
$valid_loc = HookHandler::hook("validlocation", $valid_loc);
if (!isset($valid_loc[$session['user']['location']])) {
    $session['user']['location'] = $vname;
}

$newestname = "";
$newestplayer = $settings->getSetting('newestplayer', '');
if ($newestplayer == $session['user']['acctid']) {
    $newtext = "`nYou're the newest member of the village.  As such, you wander around, gaping at the sights, and generally looking lost.";
    $newestname = $session['user']['name'];
} else {
    $newtext = "`n`2Wandering near the inn is `&%s`2, looking completely lost.";
    if ((int)$newestplayer != 0) {
        $sql = "SELECT name FROM " . Database::prefix("accounts") . " WHERE acctid='$newestplayer'";
        $result = Database::queryCached($sql, "newest");
        if (Database::numRows($result) == 1) {
            $row = Database::fetchAssoc($result);
            $newestname = $row['name'];
        } else {
            $newestplayer = "";
        }
    } else {
        if ($newestplayer > "") {
            $newestname = $newestplayer;
        } else {
            $newestname = "";
        }
    }
}

$basetext = array(
    "`@`c`b%s Square`b`cThe village of %s hustles and bustles.  No one really notices that you're standing there.  " .
    "You see various shops and businesses along main street.  There is a curious looking rock to one side.  " .
    "On every side the village is surrounded by deep dark forest.`n`n",$vname,$vname
    );
$origtexts = array(
    "text" => $basetext,
    "clock" => "The clock on the inn reads `^%s`@.`n",
    "title" => array("%s Square", $vname),
    "talk" => "`n`%`@Nearby some villagers talk:`n",
    "sayline" => "says",
    "newest" => $newtext,
    "newestplayer" => $newestname,
    "newestid" => $newestplayer,
    "gatenav" => "City Gates",
    "fightnav" => "Blades Boulevard",
    "marketnav" => "Market Street",
    "tavernnav" => "Tavern Street",
    "infonav" => "Info",
    "othernav" => "Other",
    "section" => "village",
    "innname" => $iname,
    "stablename" => "Merick's Stables",
    "mercenarycamp" => "Mercenary Camp",
    "armorshop" => "Pegasus Armor",
    "weaponshop" => "MightyE's Weaponry",
    "fields" => "The Fields"
    );
$schemas = array(
    "text" => "village",
    "clock" => "village",
    "title" => "village",
    "talk" => "village",
    "sayline" => "village",
    "newest" => "village",
    "newestplayer" => "village",
    "newestid" => "village",
    "gatenav" => "village",
    "fightnav" => "village",
    "marketnav" => "village",
    "tavernnav" => "village",
    "infonav" => "village",
    "othernav" => "village",
    "section" => "village",
    "innname" => "village",
    "stablename" => "village",
    "mercenarycamp" => "village",
    "armorshop" => "village",
    "weaponshop" => "village",
    "fields" => "village"
    );
// Now store the schemas
$origtexts['schemas'] = $schemas;

// don't hook on to this text for your standard modules please, use "village"
// instead.
// This hook is specifically to allow modules that do other villages to create
// ambience.
$texts = HookHandler::hook("villagetext", $origtexts);
//and now a special hook for the village
$texts = HookHandler::hook("villagetext-{$session['user']['location']}", $texts);
$schemas = $texts['schemas'];

$translator->setSchema($schemas['title']);
Header::pageHeader($texts['title']);
$translator->setSchema();

Commentary::addCommentary();
$skipvillagedesc = Events::handleEvent("village");
DateTime::checkDay();

if ($session['user']['slaydragon'] == 1) {
    $session['user']['slaydragon'] = 0;
}


if ($session['user']['alive']) {
} else {
    redirect("shades.php");
}

if ((int) $settings->getSetting('automaster', 1) && $session['user']['seenmaster'] != 1) {
    //masters hunt down truant students
    $level = $session['user']['level'] + 1;
    $dks = $session['user']['dragonkills'];
    $expreqd = PlayerFunctions::expForNextLevel($level, $dks);
    if (
        $session['user']['experience'] > $expreqd &&
            $session['user']['level'] < (int) $settings->getSetting('maxlevel', 15)
    ) {
        redirect("train.php?op=autochallenge");
    }
}

$op = Http::get('op');
$com = Http::get('comscroll');
$refresh = Http::get("refresh");
$commenting = Http::get("commenting");
$comment = Http::post('insertcommentary');
// Don't give people a chance at a special event if they are just browsing
// the commentary (or talking) or dealing with any of the hooks in the village.
if (!$op && $com == "" && !$comment && !$refresh && !$commenting) {
    // The '1' should really be sysadmin customizable.
    if (HookHandler::moduleEvents("village", (int) $settings->getSetting('villagechance', 0)) != 0) {
        if (Nav::checkNavs()) {
            Footer::pageFooter();
        } else {
            // Reset the special for good.
            $session['user']['specialinc'] = "";
            $session['user']['specialmisc'] = "";
            $skipvillagedesc = true;
            $op = "";
            Http::set("op", "");
        }
    }
}

$translator->setSchema($schemas['fields']);
Nav::add($texts['fields']);
Nav::add("Q?`%Quit`0 to the fields", "login.php?op=logout", true);
$translator->setSchema();

$translator->setSchema($schemas['gatenav']);
Nav::add($texts['gatenav']);
$translator->setSchema();

Nav::add("F?Forest", "forest.php");
if ((int) $settings->getSetting('pvp', 1)) {
    Nav::add("S?Slay Other Players", "pvp.php");
}
if ((bool) $settings->getSetting('enablecompanions', true)) {
    $translator->setSchema($schemas['mercenarycamp']);
    Nav::add($texts['mercenarycamp'], "mercenarycamp.php");
    $translator->setSchema();
}

$translator->setSchema($schemas['fightnav']);
Nav::add($texts['fightnav']);
$translator->setSchema();
Nav::add("u?Bluspring's Warrior Training", "train.php");
if (@file_exists("lodge.php")) {
    Nav::add("J?JCP's Hunter Lodge", "lodge.php");
}

$translator->setSchema($schemas['marketnav']);
Nav::add($texts['marketnav']);
$translator->setSchema();
$translator->setSchema($schemas['weaponshop']);
Nav::add("W?" . $texts['weaponshop'], "weapons.php");
$translator->setSchema();
$translator->setSchema($schemas['armorshop']);
Nav::add("A?" . $texts['armorshop'], "armor.php");
$translator->setSchema();
Nav::add("B?Ye Olde Bank", "bank.php");
Nav::add("Z?Ze Gypsy Tent", "gypsy.php");
if ((int) $settings->getSetting('betaperplayer', 1) === 1 && @file_exists("pavilion.php")) {
    Nav::add("E?Eye-catching Pavilion", "pavilion.php");
}

$translator->setSchema($schemas['tavernnav']);
Nav::add($texts['tavernnav']);
$translator->setSchema();
$translator->setSchema($schemas['innname']);
Nav::add("I?" . $texts['innname'] . "`0", "inn.php", true);
$translator->setSchema();
$translator->setSchema($schemas['stablename']);
Nav::add("M?" . $texts['stablename'] . "`0", "stables.php");
$translator->setSchema();

Nav::add("G?The Gardens", "gardens.php");
Nav::add("R?Curious Looking Rock", "rock.php");
if ((int) $settings->getSetting('allowclans', 1)) {
    Nav::add("C?Clan Halls", "clan.php");
}

$translator->setSchema($schemas['infonav']);
Nav::add($texts['infonav']);
$translator->setSchema();
Nav::add("??F.A.Q. (newbies start here)", "petition.php?op=faq", false, true);
Nav::add("N?Daily News", "news.php");
Nav::add("L?List Warriors", "list.php");
Nav::add("o?Hall o' Fame", "hof.php");

$translator->setSchema($schemas['othernav']);
Nav::add($texts['othernav']);
$translator->setSchema();
Nav::add("A?Account Info", "account.php");
Nav::add("P?Preferences", "prefs.php");
if (!file_exists("lodge.php")) {
    Nav::add("Refer a Friend", "referral.php");
}

$translator->setSchema('nav');
Nav::add("Superuser");
if ($session['user']['superuser'] & SU_EDIT_COMMENTS) {
    Nav::add(",?Comment Moderation", "moderate.php");
}
if ($session['user']['superuser'] & ~SU_DOESNT_GIVE_GROTTO) {
    Nav::add("X?`bSuperuser Grotto`b", "superuser.php");
}
if ($session['user']['superuser'] & SU_INFINITE_DAYS) {
    Nav::add("/?New Day", "newday.php");
}
$translator->setSchema();
//let users try to cheat, we protect against this and will know if they try.
Nav::add("", "superuser.php");
Nav::add("", "user.php");
Nav::add("", "taunt.php");
Nav::add("", "creatures.php");
Nav::add("", "configuration.php");
Nav::add("", "badword.php");
Nav::add("", "armoreditor.php");
Nav::add("", "bios.php");
Nav::add("", "badword.php");
Nav::add("", "donators.php");
Nav::add("", "referers.php");
Nav::add("", "retitle.php");
Nav::add("", "stats.php");
Nav::add("", "viewpetition.php");
Nav::add("", "weaponeditor.php");

if (!$skipvillagedesc) {
    HookHandler::hook("collapse{", array("name" => "villagedesc-" . $session['user']['location']));
    $translator->setSchema($schemas['text']);
    $output->output($texts['text']);
    $translator->setSchema();
    HookHandler::hook("}collapse");
    HookHandler::hook("collapse{", array("name" => "villageclock-" . $session['user']['location']));
    $translator->setSchema($schemas['clock']);
    $output->output($texts['clock'], getgametime());
    $translator->setSchema();
    HookHandler::hook("}collapse");
    HookHandler::hook("village-desc", $texts);
    //support for a special village-only hook
    HookHandler::hook("village-desc-{$session['user']['location']}", $texts);
    if ($texts['newestplayer'] > "" && $texts['newest']) {
        HookHandler::hook("collapse{", array("name" => "villagenewest-" . $session['user']['location']));
        $translator->setSchema($schemas['newest']);
        $output->output($texts['newest'], $texts['newestplayer']);
        $translator->setSchema();
        $id = $texts['newestid'];
        if ($session['user']['superuser'] & SU_EDIT_USERS && $id) {
            $edit = Translator::translate("Edit");
            $output->rawOutput(" [<a href='user.php?op=edit&userid=$id'>$edit</a>]");
            Nav::add("", "user.php?op=edit&userid=$id");
        }
        $output->outputNotl("`n");
        HookHandler::hook("}collapse");
    }
}
$texts = HookHandler::hook("village", $texts);
//special hook for all villages... saves queries...
$texts = HookHandler::hook("village-{$session['user']['location']}", $texts);

if ($skipvillagedesc) {
    $output->output("`n");
}

$args = HookHandler::hook("blockcommentarea", array("section" => $texts['section']));
if (!isset($args['block']) || $args['block'] != 'yes') {
        $translator->setSchema($schemas['talk']);
        $output->output($texts['talk']);
        $translator->setSchema();
            Commentary::commentDisplay("", $texts['section'], "Speak", 25, $texts['sayline'], $schemas['sayline']);
}

module_display_events("village", "village.php");
Footer::pageFooter();
