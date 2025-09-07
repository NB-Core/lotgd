<?php

declare(strict_types=1);

use Lotgd\SuAccess;
use Lotgd\Substitute;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\MySQL\Database;
use Lotgd\Translator;

// addnews ready
// mail ready
// translator ready
require_once __DIR__ . "/common.php";

Translator::getInstance()->setSchema("deathmessage");

SuAccess::check(SU_EDIT_CREATURES);

Header::pageHeader("Deathmessage Editor");
SuperuserNav::render();
$op = Http::get('op');
$deathmessageid = Http::get('deathmessageid');
switch ($op) {
    case "edit":
        Nav::add("Deathmessages");
        Nav::add("Return to the Deathmessage editor", "deathmessages.php");
        $output->rawOutput("<form action='deathmessages.php?op=save&deathmessageid=$deathmessageid' method='POST'>", true);
        Nav::add("", "deathmessages.php?op=save&deathmessageid=$deathmessageid");
        if ($deathmessageid != "") {
            $sql = "SELECT * FROM " . Database::prefix("deathmessages") . " WHERE deathmessageid=\"$deathmessageid\"";
            $result = Database::query($sql);
            $row = Database::fetchAssoc($result);
            $badguy = array(
                'creaturename' => '`2The Nasty Rabbit',
                'creatureweapon' => 'Rabbit Ears',
                'diddamage' => 0,
            );
            $deathmessage = Substitute::applyArray($row['deathmessage'], array("{where}"), array("in the fields"));
            $deathmessage = Translator::sprintfTranslate(...$deathmessage);
            $output->output("Preview: %s`0`n`n", $deathmessage);
        } else {
            $row = array('deathmessageid' => 0, 'deathmessage' => "");
        }
        $output->output("The following codes are supported (case matters):`n");
        $output->output("{goodguyname}	= The player's name (also can be specified as {goodguy}`n");
        $output->output("{goodguyweapon}	= The player's weapon (also can be specified as {weapon}`n");
        $output->output("{armorname}	= The player's armor (also can be specified as {armor}`n");
        $output->output("{himher}	= Subjective pronoun for the player (him her)`n");
        $output->output("{hisher}	= Possessive pronoun for the player (his her)`n");
        $output->output("{heshe}		= Objective pronoun for the player (he she)`n");
        $output->output("{badguyname}	= The monster's name (also can be specified as {badguy}`n");
        $output->output("{badguyweapon}	= The monster's weapon (also can be specified as {creatureweapon}`n");
        $output->output("{where}         = The location like 'in the forest' or 'in the fields' or whatnot`n");
        $save = Translator::translateInline("Save");
        $output->output("`n`n`4Deathmessage: ");
        $output->rawOutput("<input name='deathmessage' value=\"" . HTMLEntities($row['deathmessage'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "\" size='70'><br>");
        $output->output("Is this a Forest Deathmessage: ");
        $output->rawOutput("<input name='forest' " . ((int)$row['forest'] ? "checked" : "") . " value='1' type='checkbox'><br>");
        $output->output("Is this a Graveyard Deathmessage: ");
        $output->rawOutput("<input name='graveyard' " . ((int)$row['graveyard'] ? "checked" : "") . " value='1' type='checkbox'><br>");
        $output->output("Is a Taunt displayed along with it?");
        $output->rawOutput("<input name='taunt' " . ((int)$row['taunt'] ? "checked" : "") . " value='1' type='checkbox'><br>");
        $output->rawOutput("<input type='submit' class='button' value='$save'>");
        $output->rawOutput("</form>");
        break;
    case "del":
        $sql = "DELETE FROM " . Database::prefix("deathmessages") . " WHERE deathmessageid=\"$deathmessageid\"";
        Database::query($sql);
        $op = "";
        Http::set("op", "");
        break;
    case "save":
        $deathmessage = Http::post('deathmessage');
        $forest = (int) Http::post('forest');
        $graveyard = (int) Http::post('graveyard');
        $taunt = (int) Http::post('taunt');
        if ($deathmessageid != "") {
            $sql = "UPDATE " . Database::prefix("deathmessages") . " SET deathmessage=\"$deathmessage\",taunt=$taunt,forest=$forest,graveyard=$graveyard,editor=\"" . addslashes($session['user']['login']) . "\" WHERE deathmessageid=\"$deathmessageid\"";
        } else {
            $sql = "INSERT INTO " . Database::prefix("deathmessages") . " (deathmessage,taunt,forest,graveyard,editor) VALUES (\"$deathmessage\",$taunt,$forest,$graveyard,\"" . addslashes($session['user']['login']) . "\")";
        }
        Database::query($sql);
        $op = "";
        Http::set("op", "");
        break;
}
if ($op == "") {
    $output->output("`i`\$Note: These messages are NEWS messages the user will trigger when he/she dies in the forest or graveyard.`0`i`n`n");
    $sql = "SELECT * FROM " . Database::prefix("deathmessages");
    $result = Database::query($sql);
    $output->rawOutput("<table border=0 cellpadding=2 cellspacing=1 bgcolor='#999999'>");
    $op = Translator::translateInline("Ops");
    $t = Translator::translateInline("Deathmessage String");
    $auth = Translator::translateInline("Author");
    $f = Translator::translateInline("Forest Message");
    $g = Translator::translateInline("Graveyard Message");
    $ta = Translator::translateInline("With Taunt");
    $output->rawOutput("<tr class='trhead'><td nowrap>$op</td><td>$t</td><td>$f</td><td>$g</td><td>$ta</td><td>$auth</td></tr>");
    $i = true;
    while ($row = Database::fetchAssoc($result)) {
        $i = !$i;
        $output->rawOutput("<tr class='" . ($i ? "trdark" : "trlight") . "'>", true);
        $output->rawOutput("<td nowrap>");
        $edit = Translator::translateInline("Edit");
        $del = Translator::translateInline("Del");
        $conf = Translator::translateInline("Are you sure you wish to delete this deathmessage?");
        $id = $row['deathmessageid'];
        $output->rawOutput("[ <a href='deathmessages.php?op=edit&deathmessageid=$id'>$edit</a> | <a href='deathmessages.php?op=del&deathmessageid=$id' onClick='return confirm(\"$conf\");'>$del</a> ]");
        Nav::add("", "deathmessages.php?op=edit&deathmessageid=$id");
        Nav::add("", "deathmessages.php?op=del&deathmessageid=$id");
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['deathmessage']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", deathmessages_bool($row['forest']));
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", deathmessages_bool($row['graveyard']));
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", deathmessages_bool($row['taunt']));
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['editor']);
        $output->rawOutput("</td></tr>");
    }
    Nav::add("", "deathmessages.php?c=" . Http::get('c'));
    $output->rawOutput("</table>");
    Nav::add("Deathmessages");
    Nav::add("Add a new deathmessage", "deathmessages.php?op=edit");
}
function deathmessages_bool($value)
{
    if ($value) {
        return Translator::translateInline("Yes");
    } else {
        return Translator::translateInline("No");
    }
}
Footer::pageFooter();
