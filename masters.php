<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;

// Initially written as a module by Chris Vorndran.
// Moved into core by JT Traub

require_once __DIR__ . "/common.php";

SuAccess::check(SU_EDIT_CREATURES);

Translator::getInstance()->setSchema("masters");

$op = Http::get('op');
$id = (int)Http::get('id');
$act = Http::get('act');

Header::pageHeader("Masters Editor");
SuperuserNav::render();

if ($op == "del") {
    $sql = "DELETE FROM " . Database::prefix("masters") . " WHERE creatureid=$id";
    Database::query($sql);
    $output->output("`^Master deleted.`0");
    $op = "";
    Http::set("op", "");
} elseif ($op == "save") {
    $name = addslashes(Http::post('name'));
    $weapon = addslashes(Http::post('weapon'));
    $win = addslashes(Http::post('win'));
    $lose = addslashes(Http::post('lose'));
    $lev = (int)Http::post('level');
    if ($id != 0) {
        $sql = "UPDATE " . Database::prefix("masters") . " SET creaturelevel=$lev, creaturename='$name', creatureweapon='$weapon',  creaturewin='$win', creaturelose='$lose' WHERE creatureid=$id";
    } else {
        $atk = $lev * 2;
        $def = $lev * 2;
        $hp = $lev * 11;
        if ($hp == 11) {
            $hp++;
        }
        $sql = "INSERT INTO " . Database::prefix("masters") . " (creatureid,creaturelevel,creaturename,creatureweapon,creaturewin,creaturelose,creaturehealth,creatureattack,creaturedefense) VALUES ($id,$lev,'$name', '$weapon', '$win', '$lose', '$hp', '$atk', '$def')";
    }
    Database::query($sql);
    if ($id == 0) {
        $output->output("`^Master %s`^ added.", stripslashes($name));
    } else {
        $output->output("`^Master %s`^ updated.", stripslashes($name));
    }
    $op = "";
    Http::set("op", "");
} elseif ($op == "edit") {
    Nav::add("Functions");
    Nav::add("Return to Masters Editor", "masters.php");
    $sql = "SELECT * FROM " . Database::prefix("masters") . " WHERE creatureid=$id";
    $res = Database::query($sql);
    if (Database::numRows($res) == 0) {
        $row = array(
            'creaturelevel' => 1,
            'creaturename' => '',
            'creatureweapon' => '',
            'creaturewin' => '',
            'creaturelose' => ''
        );
    } else {
        $row = Database::fetchAssoc($res);
    }
    Nav::add("", "masters.php?op=save&id=$id");
    $output->rawOutput("<form action='masters.php?op=save&id=$id' method='POST'>");
        $output->rawOutput("<label for='level'>");
        $output->output("`^Master's level:`n");
        $output->rawOutput("</label>");
        $output->rawOutput("<select name='level' id='level'>");
    $maxlevel = getsetting('maxlevel');
    for ($i = 0; $i < $maxlevel; $i++) {
        $selected = ($i == $row['creaturelevel'] ? ' selected' : '');
        $output->rawOutput("<option$selected>$i</option>");
    }
    $output->rawOutput("</select>");
    $output->outputNotl("`n");
    $output->output("`^Master's name:`n");
    $output->rawOutput("<input id='input' name='name' value='" . htmlentities($row['creaturename'], ENT_COMPAT, getsetting("charset", "UTF-8")) . "'>");
    $output->outputNotl("`n");
    $output->output("`^Master's weapon:`n");
    $output->rawOutput("<input id='input' name='weapon' value='" . htmlentities($row['creatureweapon'], ENT_COMPAT, getsetting("charset", "UTF-8")) . "'>");
    $output->outputNotl("`n");
    $output->output("`^Master's speech when player wins:`n");
    $output->rawOutput("<textarea name='lose' rows='5' cols='30' class='input'>" . htmlentities($row['creaturelose'], ENT_COMPAT, getsetting("charset", "UTF-8")) . "</textarea>");
    $output->outputNotl("`n");
    $output->output("`^Master's speech when player loses:`n");
    $output->rawOutput("<textarea name='win' rows='5' cols='30' class='input'>" . htmlentities($row['creaturewin'], ENT_COMPAT, getsetting("charset", "UTF-8")) . "</textarea>");
    $output->outputNotl("`n");
    $submit = Translator::translate("Submit");
    $output->rawOutput("<input type='submit' class='button' value='$submit'>");
    $output->rawOutput("</form>");
    $output->outputNotl("`n`n");
    $output->output("`#The following codes are supported in both the win and lose speeches (case matters):`n");
    $output->output("The following codes are supported (case matters):`n");
    $output->output("{goodguyname}	= The player's name (also can be specified as {goodguy}`n");
    $output->output("{weaponname}	= The player's weapon (also can be specified as {weapon}`n");
    $output->output("{armorname}	= The player's armor (also can be specified as {armor}`n");
    $output->output("{himher}	= Subjective pronoun for the player (him her)`n");
    $output->output("{hisher}	= Possessive pronoun for the player (his her)`n");
    $output->output("{heshe}		= Objective pronoun for the player (he she)`n");
    $output->output("{badguyname}	= The monster's name (also can be specified as {badguy}`n");
    $output->output("{badguyweapon}	= The monster's weapon (also can be specified as {creatureweapon}`n");
}

if ($op == "") {
    Nav::add("Functions");
    Nav::add("Refresh list", "masters.php");
    Nav::add("Add master", "masters.php?op=edit&id=0");
    $sql = "SELECT * FROM " . Database::prefix("masters") . " ORDER BY creaturelevel";
    $res = Database::query($sql);
    $count = Database::numRows($res);
    $ops = Translator::translate("Ops");
    $edit = Translator::translate("edit");
    $del = Translator::translate("del");
    $delconfirm = Translator::translate("Are you sure you wish to delete this master.");
    $name = Translator::translate("Name");
    $level = Translator::translate("Level");
    $lose = Translator::translate("Lose to Master");
    $win = Translator::translate("Win against Master");
    $weapon = Translator::translate("Weapon");
    $output->rawOutput("<table border='0' cellpadding='2' cellspacing='1' align='center' bgcolor='#999999'>");
    $output->rawOutput("<tr class='trhead'><td>$ops</td><td>$level</td><td>$name</td><td>$weapon</td><td>$win</td><td>$lose</tr>");
    $i = false;
    while ($row = Database::fetchAssoc($res)) {
        $id = $row['creatureid'];
        $output->rawOutput("<tr class='" . ($i ? "trdark" : "trlight") . "'><td nowrap>");
        $output->rawOutput("[ <a href='masters.php?op=edit&id=$id'>");
        $output->outputNotl($edit);
        $output->rawOutput("</a> | <a href='masters.php?op=del&id=$id' onClick='return confirm(\"$delconfirm\");'>");
        $output->outputNotl($del);
        $output->rawOutput("] </a>");
        Nav::add("", "masters.php?op=edit&id=$id");
        Nav::add("", "masters.php?op=del&id=$id");
        $output->rawOutput("</td><td>");
        $output->outputNotl("`%%s`0", $row['creaturelevel']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("`#%s`0", stripslashes($row['creaturename']));
        $output->rawOutput("</td><td>");
        $output->outputNotl("`!%s`0", stripslashes($row['creatureweapon']));
        $output->rawOutput("</td><td>");
        $output->outputNotl("`&%s`0", stripslashes($row['creaturelose']));
        $output->rawOutput("</td><td>");
        $output->outputNotl("`^%s`0", stripslashes($row['creaturewin']));
        $output->rawOutput("</td></tr>");
        $i = !$i;
    }
    $output->rawOutput("</table>");
    $output->output("`n`#You can change the names, weapons and messages of all of the Training Masters.");
    $output->output("`n`3You can add masters up to the maximum level where the dragon appears in the forest and which can be set in your game settings -> game setup. You cannot assign higher masters, but if you choose not to make one master for each level, the earlier master will appear again to the player to test him.`n");
    $output->output("`#  It is suggested, that you do not toy around with this, unless you know what you are doing.`0`n");
}
Footer::pageFooter();
