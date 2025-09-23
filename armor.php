<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav\VillageNav;
use Lotgd\Nav;
use Lotgd\DateTime;
use Lotgd\Modules\HookHandler;

// translator ready
// addnews ready
// mail ready
/**
* \file armor.php
* This file holds the village armor shop which gets added as a nav by village.php.
* @see village.php
* @see armoreditor.php
*/
require_once __DIR__ . "/common.php";

$translator = Translator::getInstance();

$translator->setSchema("armor");

DateTime::checkDay();
$tradeinvalue = round(($session['user']['armorvalue'] * .75), 0);
$basetext = array(
    "title"         =>  "Pegasus Armor",
    "desc"          =>  array(
        "`5The fair and beautiful `#Pegasus`5 greets you with a warm smile as you stroll over to her brightly colored gypsy wagon, which is placed, not out of coincidence, right next to `!MightyE`5's weapon shop.",
        "Her outfit is as brightly colored and outrageous as her wagon, and it is almost (but not quite) enough to make you look away from her huge gray eyes and flashes of skin between her not-quite-sufficient gypsy clothes.`n`n",
    ),
    "tradein"       =>  array(
        "`5You look over the various pieces of apparel, and wonder if `#Pegasus`5 would be so good as to try some of them on for you, when you realize that she is busy staring dreamily at `!MightyE`5 through the window of his shop as he, bare-chested, demonstrates the use of one of his fine wares to a customer.",
        array("Noticing for a moment that you are browsing her wares, she glances at your `&%s`5 and says that she'll give you `^%s`5 for them.`0`n`n",str_replace("`%", "`2", $session['user']['armor']), $tradeinvalue), //because of `%s messing up stuff
    ),
    "nosuchweapon"  =>  "`#Pegasus`5 looks at you, confused for a second, then realizes that you've apparently taken one too many bonks on the head, and nods and smiles.",
    "tryagain"      =>  "Try again?",
    "notenoughgold" =>  "`5Waiting until `#Pegasus`5 looks away, you reach carefully for the `%%s`5, which you silently remove from the stack of clothes on which it sits. Secure in your theft, you begin to turn around only to realize that your turning action is hindered by a fist closed tightly around your throat.  Glancing down, you trace the fist to the arm on which it is attached, which in turn is attached to a very muscular `!MightyE`5. You try to explain what happened here, but your throat doesn't seem to be able to open up to let your voice through, let alone essential oxygen.`n`nAs darkness creeps in on the edge of your vision, you glance pleadingly, but futilly at `%Pegasus`5 who is staring dreamily at `!MightyE`5, her hands clutched next to her face, which is painted with a large admiring smile.`n`n`n`nYou wake up some time later, having been tossed unconscious into the street.",
    "payarmor"      =>  "`#Pegasus`5 takes your gold, and much to your surprise she also takes your `%%s`5 and promptly puts a price on it, setting it neatly on another stack of clothes.`n`nIn return, she hands you a beautiful  new `%%s`5.`n`nYou begin to protest, \"`@Won't I look silly wearing nothing but my `&%s`@?`5\" you ask. You ponder it a moment, and then realize that everyone else in the town is doing the same thing. \"`@Oh well, when in Rome...`5\"",
);

$schemas = array(
    "title" => "armor",
    "desc" => "armor",
    "tradein" => "armor",
    "nosuchweapon" => "armor",
    "tryagain" => "armor",
    "notenoughgold" => "armor",
    "payarmor" => "armor",
);

$basetext['schemas'] = $schemas;
$texts = HookHandler::hook("armortext", $basetext);
$schemas = $texts['schemas'];

$translator->setSchema($schemas['title']);
Header::pageHeader($texts['title']);
output("`c`b`%" . $texts['title'] . "`0`b`c");
$translator->setSchema();
$op = Http::get('op');
if ($op == "") {
    $translator->setSchema($schemas['desc']);
    if (is_array($texts['desc'])) {
        foreach ($texts['desc'] as $description) {
            output_notl($translator->sprintfTranslate($description));
        }
    } else {
        output($basetext['desc']);
    }
    $translator->setSchema();

    $sql = "SELECT max(level) AS level FROM " . Database::prefix("armor") . " WHERE level<=" . $session['user']['dragonkills'];
    $result = Database::query($sql);
    $row = Database::fetchAssoc($result);

    $sql = "SELECT * FROM " . Database::prefix("armor") . " WHERE level={$row['level']} ORDER BY value";
    $result = Database::query($sql);

    $translator->setSchema($schemas['tradein']);
    if (is_array($texts['tradein'])) {
        foreach ($texts['tradein'] as $description) {
            output_notl($translator->sprintfTranslate($description));
        }
    } else {
        output($texts['tradein']);
    }
    $translator->setSchema();

    $aname = Translator::translate("`bName`b");
    $adef = Translator::translate("`bDefense`b");
    $acost = Translator::translate("`bCost`b");
    rawoutput("<table border='0' cellpadding='0'>");
    rawoutput("<tr class='trhead'><td>");
    output_notl($aname);
    rawoutput("</td><td align='center'>");
    output_notl($adef);
    rawoutput("</td><td align='right'>");
    output_notl($acost);
    rawoutput("</td></tr>");
    $i = 0;
    while ($row = Database::fetchAssoc($result)) {
        $link = true;
        $row = HookHandler::hook("modify-armor", $row);
        if (isset($row['skip']) && $row['skip'] === true) {
            continue;
        }
        if (isset($row['unavailable']) && $row['unavailable'] == true) {
            $link = false;
        }
        rawoutput("<tr class='" . ($i % 2 == 1 ? "trlight" : "trdark") . "'>");
        rawoutput("<td>");
        $color = "`)";
        if ($row['value'] <= ($session['user']['gold'] + $tradeinvalue)) {
            if ($link) {
                $color = "`&";
                rawoutput("<a href='armor.php?op=buy&id={$row['armorid']}'>");
            } else {
                $color = "`7";
            }
            output_notl("%s%s`0", $color, $row['armorname']);
            if ($link) {
                rawoutput("</a>");
            }
            Nav::add("", "armor.php?op=buy&id={$row['armorid']}");
        } else {
            output_notl("%s%s`0", $color, $row['armorname']);
            Nav::add("", "armor.php?op=buy&id={$row['armorid']}");
        }
        rawoutput("</td><td align='center'>");
        output_notl("%s%s`0", $color, $row['defense']);
        rawoutput("</td><td align='right'>");
        if (isset($row['alternatetext']) && $row['alternatetext'] > "") {
            output("%s%s`0", $color, $row['alternatetext']);
        } else {
            output_notl("%s%s`0", $color, $row['value']);
        }
        rawoutput("</td></tr>");
        ++$i;
    }
    rawoutput("</table>", true);
    VillageNav::render();
} elseif ($op == "buy") {
    $id = Http::get('id');
    $sql = "SELECT * FROM " . Database::prefix("armor") . " WHERE armorid='$id'";
    $result = Database::query($sql);
    if (Database::numRows($result) == 0) {
        $translator->setSchema($schemas['nosuchweapon']);
        output($texts['nosuchweapon']);
        $translator->setSchema();
        $translator->setSchema($schemas['tryagain']);
        Nav::add($texts['tryagain'], "armor.php");
        $translator->setSchema();
        VillageNav::render();
    } else {
        $row = Database::fetchAssoc($result);
        $row = HookHandler::hook("modify-armor", $row);
        if ($row['value'] > ($session['user']['gold'] + $tradeinvalue)) {
            $translator->setSchema($schemas['notenoughgold']);
            output($texts['notenoughgold'], $row['armorname']);
            $translator->setSchema();
            VillageNav::render();
        } else {
            $translator->setSchema($schemas['payarmor']);
            output($texts['payarmor'], $session['user']['armor'], $row['armorname'], $row['armorname']);
            $translator->setSchema();
            debuglog("spent " . ($row['value'] - $tradeinvalue) . " gold on the " . $row['armorname'] . " armor");
            $session['user']['gold'] -= $row['value'];
            $session['user']['armor'] = $row['armorname'];
            $session['user']['gold'] += $tradeinvalue;
            $session['user']['defense'] -= $session['user']['armordef'];
            $session['user']['armordef'] = $row['defense'];
            $session['user']['defense'] += $session['user']['armordef'];
            $session['user']['armorvalue'] = $row['value'];
            VillageNav::render();
        }
    }
}
Footer::pageFooter();
