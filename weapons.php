<?php

use Lotgd\DateTime;
use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Nav;
use Lotgd\Nav\VillageNav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";

$translator = Translator::getInstance();

$translator->setSchema("weapon");

DateTime::checkDay();
$tradeinvalue = round(($session['user']['weaponvalue'] * .75), 0);
$basetext = array(
    "title"         =>  "MightyE's Weapons",
    "desc"          =>  array(
        "`!MightyE `7stands behind a counter and appears to pay little attention to you as you enter, but you know from experience that he has his eye on every move you make.",
        array("He may be a humble weapons merchant, but he still carries himself with the grace of a man who has used his weapons to kill mightier %s than you.`n`n", Translator::translate($session['user']['sex'] ? "women" : "men")),
        "The massive hilt of a claymore protrudes above his shoulder; its gleam in the torch light not much brighter than the gleam off of `!MightyE's`7 bald forehead, kept shaved mostly as a strategic advantage, but in no small part because nature insisted that some level of baldness was necessary.`n`n",
        "`!MightyE`7 finally nods to you, stroking his goatee and looking like he wished he could have an opportunity to use one of these weapons.",
    ),
    "tradein"       =>  array(
        "`7You stroll up the counter and try your best to look like you know what most of these contraptions do.",
        array("`!MightyE`7 looks at you and says, \"`#I'll give you `^%s`# trade-in value for your `5%s`#.",$tradeinvalue,$session['user']['weapon']),
        "Just click on the weapon you wish to buy, what ever 'click' means`7,\" and looks utterly confused.",
        "He stands there a few seconds, snapping his fingers and wondering if that is what is meant by \"click,\" before returning to his work: standing there and looking good.`n`n",
    ),
    "nosuchweapon"  =>  "`!MightyE`7 looks at you, confused for a second, then realizes that you've apparently taken one too many bonks on the head, and nods and smiles.",
    "tryagain"      =>  "Try again?",
    "notenoughgold" =>  "Waiting until `!MightyE`7 looks away, you reach carefully for the `5%s`7, which you silently remove from the rack upon which it sits. Secure in your theft, you turn around and head for the door, swiftly, quietly, like a ninja, only to discover that upon reaching the door, the ominous `!MightyE`7 stands, blocking your exit. You execute a flying kick. Mid flight, you hear the \"SHING\" of a sword leaving its sheath.... your foot is gone. You land on your stump, and `!MightyE`7 stands in the doorway, claymore once again in its back holster, with no sign that it had been used, his arms folded menacingly across his burly chest.  \"`#Perhaps you'd like to pay for that?`7\" is all he has to say as you collapse at his feet, lifeblood staining the planks under your remaining foot.`n`nYou wake up some time later, having been tossed unconscious into the street.",
    "payweapon"     =>  "`!MightyE`7 takes your `5%s`7 and promptly puts a price on it, setting it out for display with the rest of his weapons.`n`nIn return, he hands you a shiny new `5%s`7 which you swoosh around the room, nearly taking off `!MightyE`7's head, which he deftly ducks; you're not the first person to exuberantly try out a new weapon.",
);

$schemas = array(
    "title" => "weapon",
    "desc" => "weapon",
    "tradein" => "weapon",
    "nosuchweapon" => "weapon",
    "tryagain" => "weapon",
    "notenoughgold" => "weapon",
    "payweapon" => "weapon",
);

$basetext['schemas'] = $schemas;
$texts = HookHandler::hook("weaponstext", $basetext);
$schemas = $texts['schemas'];

$translator->setSchema($schemas['title']);
Header::pageHeader($texts['title']);
$output->output("`c`b`&" . $texts['title'] . "`0`b`c");
$translator->setSchema();

$op = Http::get("op");

if ($op == "") {
    $translator->setSchema($schemas['desc']);
    if (is_array($texts['desc'])) {
        foreach ($texts['desc'] as $description) {
            $output->outputNotl($translator->sprintfTranslate($description));
        }
    } else {
        $output->output($texts['desc']);
    }
    $translator->setSchema();


    $sql = "SELECT max(level) AS level FROM " .  Database::prefix("weapons") . " WHERE level<=" . (int)$session['user']['dragonkills'];
    $result = Database::query($sql);
    $row = Database::fetchAssoc($result);

    $sql = "SELECT * FROM " . Database::prefix("weapons") . " WHERE level = " . (int)$row['level'] . " ORDER BY damage ASC";
    $result = Database::query($sql);

    $translator->setSchema($schemas['tradein']);
    if (is_array($texts['tradein'])) {
        foreach ($texts['tradein'] as $description) {
            $output->outputNotl($translator->sprintfTranslate($description));
        }
    } else {
        $output->output($texts['tradein']);
    }
    $translator->setSchema();

    $wname = Translator::translate("`bName`b");
    $wdam = Translator::translate("`bDamage`b");
    $wcost = Translator::translate("`bCost`b");
    $output->rawOutput("<table border='0' cellpadding='0'>");
    $output->rawOutput("<tr class='trhead'><td>");
    $output->outputNotl($wname);
    $output->rawOutput("</td><td align='center'>");
    $output->outputNotl($wdam);
    $output->rawOutput("</td><td align='right'>");
    $output->outputNotl($wcost);
    $output->rawOutput("</td></tr>");
    $i = 0;
    while ($row = Database::fetchAssoc($result)) {
        $link = true;
        $row = HookHandler::hook("modify-weapon", $row);
        if (isset($row['skip']) && $row['skip'] === true) {
            continue;
        }
        if (isset($row['unavailable']) && $row['unavailable'] == true) {
            $link = false;
        }
        $output->rawOutput("<tr class='" . ($i % 2 == 1 ? "trlight" : "trdark") . "'><td>");
        $color = "`)";
        if ($row['value'] <= ($session['user']['gold'] + $tradeinvalue)) {
            if ($link) {
                $color = "`&";
                $output->rawOutput("<a href='weapons.php?op=buy&id={$row['weaponid']}'>");
            } else {
                $color = "`7";
            }
            $output->outputNotl("%s%s`0", $color, $row['weaponname']);
            if ($link) {
                $output->rawOutput("</a>");
            }
            Nav::add("", "weapons.php?op=buy&id={$row['weaponid']}");
        } else {
            $output->outputNotl("%s%s`0", $color, $row['weaponname']);
            Nav::add("", "weapons.php?op=buy&id={$row['weaponid']}");
        }
        $output->rawOutput("</td><td align='center'>");
        $output->outputNotl("%s%s`0", $color, $row['damage']);
        $output->rawOutput("</td><td align='right'>");
        if (isset($row['alternatetext']) && $row['alternatetext'] > "") {
            $output->output("%s%s`0", $color, $row['alternatetext']);
        } else {
            $output->outputNotl("%s%s`0", $color, $row['value']);
        }
        $output->rawOutput("</td></tr>");
        ++$i;
    }
    $output->rawOutput("</table>");
    VillageNav::render();
} elseif ($op == "buy") {
    $id = Http::get("id");
    $sql = "SELECT * FROM " . Database::prefix("weapons") . " WHERE weaponid='$id'";
    $result = Database::query($sql);
    if (Database::numRows($result) == 0) {
        $translator->setSchema($schemas['nosuchweapon']);
        $output->output($texts['nosuchweapon']);
        $translator->setSchema();
        $translator->setSchema($schemas['tryagain']);
        Nav::add($texts['tryagain'], "weapons.php");
        $translator->setSchema();
        VillageNav::render();
    } else {
        $row = Database::fetchAssoc($result);
        $row = HookHandler::hook("modify-weapon", $row);
        if ($row['value'] > ($session['user']['gold'] + $tradeinvalue)) {
            $translator->setSchema($schemas['notenoughgold']);
            $output->output($texts['notenoughgold'], $row['weaponname']);
            $translator->setSchema();
            VillageNav::render();
        } else {
            $translator->setSchema($schemas['payweapon']);
            $output->output($texts['payweapon'], $session['user']['weapon'], $row['weaponname']);
            $translator->setSchema();
            debuglog("spent " . ($row['value'] - $tradeinvalue) . " gold on the " . $row['weaponname'] . " weapon");
            $session['user']['gold'] -= $row['value'];
            $session['user']['weapon'] = $row['weaponname'];
            $session['user']['gold'] += $tradeinvalue;
            $session['user']['attack'] -= $session['user']['weapondmg'];
            $session['user']['weapondmg'] = $row['damage'];
            $session['user']['attack'] += $session['user']['weapondmg'];
            $session['user']['weaponvalue'] = $row['value'];
            VillageNav::render();
        }
    }
}

Footer::pageFooter();
