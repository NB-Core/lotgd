<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;

/**
 * \file armoreditor.php
 * This file represents the grotto armor editor where you can create or edit new weapons for the shop.
 * @see armor.php
 */

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
// translator ready
use Lotgd\Forms;

// addnews ready
// mail ready
require_once __DIR__ . "/common.php";

use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;

SuAccess::check(SU_EDIT_EQUIPMENT);

Translator::getInstance()->setSchema("armor");

Header::pageHeader("Armor Editor");
$armorlevel = (int)Http::get('level');
SuperuserNav::render();
Nav::add("Armor Editor");
Nav::add("Armor Editor Home", "armoreditor.php?level=$armorlevel");

Nav::add("Add armor", "armoreditor.php?op=add&level=$armorlevel");
$values = array(1 => 48,225,585,990,1575,2250,2790,3420,4230,5040,5850,6840,8010,9000,10350);
output("`&<h3>Armor for %s Dragon Kills</h3>`0", $armorlevel, true);

$armorarray = array(
    "Armor,title",
    "armorid" => "Armor ID,hidden",
    "armorname" => "Armor Name",
    "defense" => "Defense,range,1,15,1");
$op = Http::get('op');
$id = Http::get('id');
if ($op == "edit" || $op == "add") {
    if ($op == "edit") {
        $sql = "SELECT * FROM " . Database::prefix("armor") . " WHERE armorid='$id'";
        $result = Database::query($sql);
        $row = Database::fetchAssoc($result);
    } else {
        $sql = "SELECT max(defense+1) AS defense FROM " . Database::prefix("armor") . " WHERE level=$armorlevel";
        $result = Database::query($sql);
        $row = Database::fetchAssoc($result);
    }
    rawoutput("<form action='armoreditor.php?op=save&level=$armorlevel' method='POST'>");
    Nav::add("", "armoreditor.php?op=save&level=$armorlevel");
    Forms::showForm($armorarray, $row);
    rawoutput("</form>");
} elseif ($op == "del") {
    $sql = "DELETE FROM " . Database::prefix("armor") . " WHERE armorid='$id'";
    Database::query($sql);
    //output($sql);
    $op = "";
    Http::set('op', $op);
} elseif ($op == "save") {
    $armorid = Http::post('armorid');
    $armorname = Http::post('armorname');
    $defense = Http::post('defense');
    if ($armorid > 0) {
        $sql = "UPDATE " . Database::prefix("armor") . " SET armorname=\"$armorname\",defense=\"$defense\",value=" . $values[$defense] . " WHERE armorid='$armorid'";
    } else {
        $sql = "INSERT INTO " . Database::prefix("armor") . " (level,defense,armorname,value) VALUES ($armorlevel,\"$defense\",\"$armorname\"," . $values[$defense] . ")";
    }
    Database::query($sql);
    $op = "";
    Http::set('op', $op);
}
if ($op == "") {
    $sql = "SELECT max(level+1) AS level FROM " . Database::prefix("armor");
    $res = Database::query($sql);
    $row = Database::fetchAssoc($res);
    $max = $row['level'];
    for ($i = 0; $i <= $max; $i++) {
        if ($i == 1) {
            Nav::add(array("Armor for %s DK",$i), "armoreditor.php?level=$i");
        } else {
            Nav::add(array("Armor for %s DKs",$i), "armoreditor.php?level=$i");
        }
    }
    $sql = "SELECT * FROM " . Database::prefix("armor") . " WHERE level=$armorlevel ORDER BY defense";
    $result = Database::query($sql);
    $ops = translate_inline("Ops");
    $name = translate_inline("Name");
    $cost = translate_inline("Cost");
    $defense = translate_inline("Defense");
    $level = translate_inline("Level");
    $edit = translate_inline("Edit");
    $del = translate_inline("Del");
    $delconfirm = translate_inline("Are you sure you wish to delete this armor?");

    rawoutput("<table border=0 cellpadding=2 cellspacing=1 bgcolor='#999999'>");
    rawoutput("<tr class='trhead'><td>$ops</td><td>$name</td><td>$cost</td><td>$defense</td><td>$level</td></tr>");
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        rawoutput("<tr class='" . ($i % 2 ? "trdark" : "trlight") . "'>");
        rawoutput("<td>[<a href='armoreditor.php?op=edit&id={$row['armorid']}&level=$armorlevel'>$edit</a>|<a href='armoreditor.php?op=del&id={$row['armorid']}&level=$armorlevel' onClick='return confirm(\"$delconfirm\");'>$del</a>]</td>");
        Nav::add("", "armoreditor.php?op=edit&id={$row['armorid']}&level=$armorlevel");
        Nav::add("", "armoreditor.php?op=del&id={$row['armorid']}&level=$armorlevel");
        rawoutput("<td>");
        output_notl($row['armorname']);
        rawoutput("</td><td>");
        output_notl((string)$row['value']);
        rawoutput("</td><td>");
        output_notl((string)$row['defense']);
        rawoutput("</td><td>");
        output_notl((string)$row['level']);
        rawoutput("</td>");
        rawoutput("</tr>");
    }
    rawoutput("</table>");
}
Footer::pageFooter();
