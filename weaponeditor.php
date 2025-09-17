<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
// translator ready
use Lotgd\Forms;

// addnews ready
// mail ready
require_once __DIR__ . "/common.php";
require_once __DIR__ . "/lib/http.php";

SuAccess::check(SU_EDIT_EQUIPMENT);

Translator::getInstance()->setSchema("weapon");

page_header("Weapon Editor");
$weaponlevel = (int)httpget("level");
SuperuserNav::render();

addnav("Editor");
addnav("Weapon Editor Home", "weaponeditor.php?level=$weaponlevel");

addnav("Add a weapon", "weaponeditor.php?op=add&level=$weaponlevel");
$values = array(1 => 48,225,585,990,1575,2250,2790,3420,4230,5040,5850,6840,8010,9000,10350);
rawoutput("<h3>");
if ($weaponlevel == 1) {
    output("`&Weapons for 1 Dragon Kill`0");
} else {
    output("`&Weapons for %s Dragon Kills`0", $weaponlevel);
}
rawoutput("<h3>");

$weaponarray = array(
    "Weapon,title",
    "weaponid" => "Weapon ID,hidden",
    "weaponlevel" => "DK Level",
    "weaponname" => "Weapon Name",
    "damage" => "Damage,range,1,15,1");
$op = httpget('op');
$id = httpget('id');
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
    rawoutput("<form action='weaponeditor.php?op=save&level=$weaponlevel' method='POST'>");
    addnav("", "weaponeditor.php?op=save&level=$weaponlevel");
    Forms::showForm($weaponarray, $row);
    rawoutput("</form>");
} elseif ($op == "del") {
    $sql = "DELETE FROM " . Database::prefix("weapons") . " WHERE weaponid='$id'";
    Database::query($sql);
    $op = "";
    httpset("op", $op);
} elseif ($op == "save") {
    $weaponid = (int)httppost("weaponid");
    $damage = httppost("damage");
    $weaponname = httppost("weaponname");
    if ($weaponid > 0) {
        $sql = "UPDATE " . Database::prefix("weapons") . " SET weaponname=\"$weaponname\",damage=\"$damage\",value=" .  $values[$damage] . " WHERE weaponid='$weaponid'";
    } else {
        $sql = "INSERT INTO " . Database::prefix("weapons") . " (level,damage,weaponname,value) VALUES ($weaponlevel,\"$damage\",\"$weaponname\"," . $values[$damage] . ")";
    }
    Database::query($sql);
    //output($sql);
    $op = "";
    httpset("op", $op);
}
if ($op == "") {
    $sql = "SELECT max(level+1) as level FROM " . Database::prefix("weapons");
    $res = Database::query($sql);
    $row = Database::fetchAssoc($res);
    $max = $row['level'];
    for ($i = 0; $i <= $max; $i++) {
        if ($i == 1) {
            addnav("Weapons for 1 DK", "weaponeditor.php?level=$i");
        } else {
            addnav(array("Weapons for %s DKs",$i), "weaponeditor.php?level=$i");
        }
    }
    $sql = "SELECT * FROM " . Database::prefix("weapons") . " WHERE level=$weaponlevel ORDER BY damage";
    $result = Database::query($sql);
    $ops = translate_inline("Ops");
    $name = translate_inline("Name");
    $cost = translate_inline("Cost");
    $damage = translate_inline("Damage");
    $level = translate_inline("Level");
    $edit = translate_inline("Edit");
    $del = translate_inline("Del");
    $delconfirm = translate_inline("Are you sure you wish to delete this weapon?");

    rawoutput("<table border=0 cellpadding=2 cellspacing=1 bgcolor='#999999'>");
    rawoutput("<tr class='trhead'><td>$ops</td><td>$name</td><td>$cost</td><td>$damage</td><td>$level</td></tr>");
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        rawoutput("<tr class='" . ($i % 2 ? "trdark" : "trlight") . "'>");
        rawoutput("<td>[<a href='weaponeditor.php?op=edit&id={$row['weaponid']}&level=$weaponlevel'>$edit</a>|<a href='weaponeditor.php?op=del&id={$row['weaponid']}&level=$weaponlevel' onClick='return confirm(\"Are you sure you wish to delete this weapon?\");'>$del</a>]</td>");
        addnav("", "weaponeditor.php?op=edit&id={$row['weaponid']}&level=$weaponlevel");
        addnav("", "weaponeditor.php?op=del&id={$row['weaponid']}&level=$weaponlevel");
        rawoutput("<td>");
        output_notl($row['weaponname']);
        rawoutput("</td><td>");
        output_notl((string)$row['value']);
        rawoutput("</td><td>");
        output_notl((string)$row['damage']);
        rawoutput("</td><td>");
        output_notl((string)$row['level']);
        rawoutput("</td>");
        rawoutput("</tr>");
    }
    rawoutput("</table>");
}
page_footer();
