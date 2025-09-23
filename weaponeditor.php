<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Forms;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;

// translator ready


// addnews ready
// mail ready
require_once __DIR__ . "/common.php";

SuAccess::check(SU_EDIT_EQUIPMENT);

Translator::getInstance()->setSchema("weapon");

Header::pageHeader("Weapon Editor");
$weaponlevel = (int)Http::get("level");
SuperuserNav::render();

Nav::add("Editor");
Nav::add("Weapon Editor Home", "weaponeditor.php?level=$weaponlevel");

Nav::add("Add a weapon", "weaponeditor.php?op=add&level=$weaponlevel");
$values = array(1 => 48,225,585,990,1575,2250,2790,3420,4230,5040,5850,6840,8010,9000,10350);
$output->rawOutput("<h3>");
if ($weaponlevel == 1) {
    $output->output("`&Weapons for 1 Dragon Kill`0");
} else {
    $output->output("`&Weapons for %s Dragon Kills`0", $weaponlevel);
}
$output->rawOutput("<h3>");

$weaponarray = array(
    "Weapon,title",
    "weaponid" => "Weapon ID,hidden",
    "weaponlevel" => "DK Level",
    "weaponname" => "Weapon Name",
    "damage" => "Damage,range,1,15,1");
$op = Http::get('op');
$id = Http::get('id');
if ($op == "edit" || $op == "add") {
    if ($op == "edit") {
        $sql = "SELECT * FROM " . Database::prefix("weapons") . " WHERE weaponid='$id'";
        $result = Database::query($sql);
        $row = Database::fetchAssoc($result);
    } else {
        $sql = "SELECT max(damage+1) AS damage FROM " . Database::prefix("weapons") . " WHERE level=$weaponlevel";
        $result = Database::query($sql);
        $row = Database::fetchAssoc($result);
    }
    $output->rawOutput("<form action='weaponeditor.php?op=save&level=$weaponlevel' method='POST'>");
    Nav::add("", "weaponeditor.php?op=save&level=$weaponlevel");
    Forms::showForm($weaponarray, $row);
    $output->rawOutput("</form>");
} elseif ($op == "del") {
    $sql = "DELETE FROM " . Database::prefix("weapons") . " WHERE weaponid='$id'";
    Database::query($sql);
    $op = "";
    Http::set("op", $op);
} elseif ($op == "save") {
    $weaponid = (int)Http::post("weaponid");
    $damage = Http::post("damage");
    $weaponname = Http::post("weaponname");
    if ($weaponid > 0) {
        $sql = "UPDATE " . Database::prefix("weapons") . " SET weaponname=\"$weaponname\",damage=\"$damage\",value=" .  $values[$damage] . " WHERE weaponid='$weaponid'";
    } else {
        $sql = "INSERT INTO " . Database::prefix("weapons") . " (level,damage,weaponname,value) VALUES ($weaponlevel,\"$damage\",\"$weaponname\"," . $values[$damage] . ")";
    }
    Database::query($sql);
    //$output->output($sql);
    $op = "";
    Http::set("op", $op);
}
if ($op == "") {
    $sql = "SELECT max(level+1) as level FROM " . Database::prefix("weapons");
    $res = Database::query($sql);
    $row = Database::fetchAssoc($res);
    $max = $row['level'];
    for ($i = 0; $i <= $max; $i++) {
        if ($i == 1) {
            Nav::add("Weapons for 1 DK", "weaponeditor.php?level=$i");
        } else {
            Nav::add(array("Weapons for %s DKs",$i), "weaponeditor.php?level=$i");
        }
    }
    $sql = "SELECT * FROM " . Database::prefix("weapons") . " WHERE level=$weaponlevel ORDER BY damage";
    $result = Database::query($sql);
    $ops = Translator::translate("Ops");
    $name = Translator::translate("Name");
    $cost = Translator::translate("Cost");
    $damage = Translator::translate("Damage");
    $level = Translator::translate("Level");
    $edit = Translator::translate("Edit");
    $del = Translator::translate("Del");
    $delconfirm = Translator::translate("Are you sure you wish to delete this weapon?");

    $output->rawOutput("<table border=0 cellpadding=2 cellspacing=1 bgcolor='#999999'>");
    $output->rawOutput("<tr class='trhead'><td>$ops</td><td>$name</td><td>$cost</td><td>$damage</td><td>$level</td></tr>");
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        $output->rawOutput("<tr class='" . ($i % 2 ? "trdark" : "trlight") . "'>");
        $output->rawOutput("<td>[<a href='weaponeditor.php?op=edit&id={$row['weaponid']}&level=$weaponlevel'>$edit</a>|<a href='weaponeditor.php?op=del&id={$row['weaponid']}&level=$weaponlevel' onClick='return confirm(\"Are you sure you wish to delete this weapon?\");'>$del</a>]</td>");
        Nav::add("", "weaponeditor.php?op=edit&id={$row['weaponid']}&level=$weaponlevel");
        Nav::add("", "weaponeditor.php?op=del&id={$row['weaponid']}&level=$weaponlevel");
        $output->rawOutput("<td>");
        $output->outputNotl($row['weaponname']);
        $output->rawOutput("</td><td>");
        $output->outputNotl((string)$row['value']);
        $output->rawOutput("</td><td>");
        $output->outputNotl((string)$row['damage']);
        $output->rawOutput("</td><td>");
        $output->outputNotl((string)$row['level']);
        $output->rawOutput("</td>");
        $output->rawOutput("</tr>");
    }
    $output->rawOutput("</table>");
}
Footer::pageFooter();
