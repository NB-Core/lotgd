<?php

declare(strict_types=1);

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Substitute;
use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\MySQL\Database;
use Lotgd\Settings;
use Lotgd\Translator;

// addnews ready
// mail ready
// translator ready
require_once __DIR__ . "/common.php";

Translator::getInstance()->setSchema("taunt");

SuAccess::check(SU_EDIT_CREATURES);

Header::pageHeader("Taunt Editor");
SuperuserNav::render();
$op = Http::get('op');
$tauntid = Http::get('tauntid');
if ($op == "edit") {
    Nav::add("Taunts");
    Nav::add("Return to the taunt editor", "taunt.php");
    $output->rawOutput("<form action='taunt.php?op=save&tauntid=$tauntid' method='POST'>", true);
    Nav::add("", "taunt.php?op=save&tauntid=$tauntid");
    if ($tauntid != "") {
        $sql = "SELECT * FROM " . Database::prefix("taunts") . " WHERE tauntid=\"$tauntid\"";
        $result = Database::query($sql);
        $row = Database::fetchAssoc($result);
        $badguy = array(
            'creaturename' => 'Baron Munchausen',
            'creatureweapon' => 'Bad Puns',
            'diddamage' => 0,
        );
        $taunt = Substitute::applyArray($row['taunt']);
        $taunt = Translator::sprintfTranslate(...$taunt);
        $output->output("Preview: %s`0`n`n", $taunt);
    } else {
        $row = array('tauntid' => 0, 'taunt' => "");
    }
    $output->output("Taunt: ");
    $output->rawOutput("<input name='taunt' value=\"" . HTMLEntities($row['taunt'], ENT_COMPAT, Settings::getsetting("charset", "UTF-8")) . "\" size='70'><br>");
    $output->output("The following codes are supported (case matters):`n");
    $output->output("{goodguyname}	= The player's name (also can be specified as {goodguy}`n");
    $output->output("{goodguyweapon}	= The player's weapon (also can be specified as {weapon}`n");
    $output->output("{armorname}	= The player's armor (also can be specified as {armor}`n");
    $output->output("{himher}	= Subjective pronoun for the player (him her)`n");
    $output->output("{hisher}	= Possessive pronoun for the player (his her)`n");
    $output->output("{heshe}		= Objective pronoun for the player (he she)`n");
    $output->output("{badguyname}	= The monster's name (also can be specified as {badguy}`n");
    $output->output("{badguyweapon}	= The monster's weapon (also can be specified as {creatureweapon}`n");
    $save = Translator::translateInline("Save");
    $output->rawOutput("<input type='submit' class='button' value='$save'>");
    $output->rawOutput("</form>");
} elseif ($op == "del") {
    $sql = "DELETE FROM " . Database::prefix("taunts") . " WHERE tauntid=\"$tauntid\"";
    Database::query($sql);
    $op = "";
    Http::set("op", "");
} elseif ($op == "save") {
    $taunt = Http::post('taunt');
    if ($tauntid != "") {
        $sql = "UPDATE " . Database::prefix("taunts") . " SET taunt=\"$taunt\",editor=\"" . addslashes($session['user']['login']) . "\" WHERE tauntid=\"$tauntid\"";
    } else {
        $sql = "INSERT INTO " . Database::prefix("taunts") . " (taunt,editor) VALUES (\"$taunt\",\"" . addslashes($session['user']['login']) . "\")";
    }
    Database::query($sql);
    $op = "";
    Http::set("op", "");
}
if ($op == "") {
    $sql = "SELECT * FROM " . Database::prefix("taunts");
    $result = Database::query($sql);
    $output->rawOutput("<table border=0 cellpadding=2 cellspacing=1 bgcolor='#999999'>");
    $op = Translator::translateInline("Ops");
    $t = Translator::translateInline("Taunt String");
    $auth = Translator::translateInline("Author");
    $output->rawOutput("<tr class='trhead'><td nowrap>$op</td><td>$t</td><td>$auth</td></tr>");
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        $output->rawOutput("<tr class='" . ($i % 2 == 0 ? "trdark" : "trlight") . "'>", true);
        $output->rawOutput("<td nowrap>");
        $edit = Translator::translateInline("Edit");
        $del = Translator::translateInline("Del");
        $conf = Translator::translateInline("Are you sure you wish to delete this taunt?");
        $id = $row['tauntid'];
        $output->rawOutput("[ <a href='taunt.php?op=edit&tauntid=$id'>$edit</a> | <a href='taunt.php?op=del&tauntid=$id' onClick='return confirm(\"$conf\");'>$del</a> ]");
        Nav::add("", "taunt.php?op=edit&tauntid=$id");
        Nav::add("", "taunt.php?op=del&tauntid=$id");
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['taunt']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['editor']);
        $output->rawOutput("</td></tr>");
    }
    Nav::add("", "taunt.php?c=" . Http::get('c'));
    $output->rawOutput("</table>");
    Nav::add("Taunts");
    Nav::add("Add a new taunt", "taunt.php?op=edit");
}
Footer::pageFooter();
