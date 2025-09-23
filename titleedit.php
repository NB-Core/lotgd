<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Names;
use Lotgd\Forms;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;
use Lotgd\PlayerFunctions;

//Author: Lonny Luberts - 3/18/2005
//Heavily modified by JT Traub
require_once __DIR__ . "/common.php";

SuAccess::check(SU_EDIT_USERS);

Translator::getInstance()->setSchema("retitle");

Header::pageHeader("Title Editor");
$op = Http::get('op');
$id = Http::get('id');
$editarray = array(
    "Titles,title",
    //"titleid"=>"Title Id,hidden",
    "dk" => "Dragon Kills,int|0",
    // "ref"=>"Arbitrary Tag,int",
    "male" => "Male Title,text|",
    "female" => "Female Title,text|",
);
Nav::add("Other");
SuperuserNav::render();
Nav::add("Functions");

switch ($op) {
    case "save":
        $male = Http::post('male');
        $female = Http::post('female');
        $dk = Http::post('dk');
        // Ref is currently unused
        // $ref = Http::post('ref');
        $ref = '';

        if ((int)$id == 0) {
            $sql = "INSERT INTO " . Database::prefix("titles") . " (titleid,dk,ref,male,female) VALUES ($id,$dk,'$ref','$male','$female')";
            $note = "`^New title added.`0";
            $errnote = "`\$Unable to add title.`0";
        } else {
            $sql = "UPDATE " . Database::prefix("titles") . " SET dk=$dk,ref='$ref',male='$male',female='$female' WHERE titleid=$id";
            $note = "`^Title modified.`0";
            $errnote = "`\$Unable to modify title.`0";
        }
        Database::query($sql);
        if (Database::affectedRows() == 0) {
            $output->output($errnote);
            $output->rawOutput(Database::error());
        } else {
            $output->output($note);
        }
        $op = "";
        break;
    case "delete":
        $sql = "DELETE FROM " . Database::prefix("titles") . " WHERE titleid='$id'";
        Database::query($sql);
        $output->output("`^Title deleted.`0");
        $op = "";
        break;
}

switch ($op) {
    case "reset":
        $output->output("`^Rebuilding all titles for all players.`0`n`n");
        $sql = "SELECT name,title,dragonkills,acctid,sex,ctitle FROM " . Database::prefix("accounts");
        $result = Database::query($sql);
        $number = Database::numRows($result);
        for ($i = 0; $i < $number; $i++) {
            $row = Database::fetchAssoc($result);
            $oname = $row['name'];
            $dk = $row['dragonkills'];
            $otitle = $row['title'];
            $dk = (int)($row['dragonkills']);
            if (!PlayerFunctions::validDkTitle($otitle, $dk, $row['sex'])) {
                $sex = Translator::translate($row['sex'] ? "female" : "male");
                $newtitle = PlayerFunctions::getDkTitle($dk, (int)$row['sex']);
                $newname = Names::changePlayerTitle($newtitle, $row);
                $id = $row['acctid'];
                if ($oname != $newname) {
                    $output->output(
                        "`@Changing `^%s`@ to `^%s `@(%s`@ [%s,%s])`n",
                        $oname,
                        $newname,
                        $newtitle,
                        $dk,
                        $sex
                    );
                    if ($session['user']['acctid'] == $row['acctid']) {
                        $session['user']['title'] = $newtitle;
                        $session['user']['name'] = $newname;
                    } else {
                        $sql = "UPDATE " . Database::prefix("accounts") . " SET name='" .
                            addslashes($newname) . "', title='" .
                            addslashes($newtitle) . "' WHERE acctid='$id'";
                        Database::query($sql);
                    }
                } elseif ($otitle != $newtitle) {
                    $output->output(
                        "`@Changing only the title (not the name) of `^%s`@ `@(%s`@ [%s,%s])`n",
                        $oname,
                        $newtitle,
                        $dk,
                        $sex
                    );
                    if ($session['user']['acctid'] == $row['acctid']) {
                        $session['user']['title'] = $newtitle;
                    } else {
                        $sql = "UPDATE " . Database::prefix("accounts") .
                            " SET title='" . addslashes($newtitle) .
                            "' WHERE acctid='$id'";
                        Database::query($sql);
                    }
                }
            }
        }
        $output->output("`n`n`^Done.`0");
        Nav::add("Main Title Editor", "titleedit.php");
        break;

    case "edit":
    case "add":
        if ($op == "edit") {
            $output->output("`\$Editing an existing title`n`n");
            $sql = "SELECT * FROM " . Database::prefix("titles") . " WHERE titleid='$id'";
            $result = Database::query($sql);
            $row = Database::fetchAssoc($result);
        } elseif ($op == "add") {
            $output->output("`\$Adding a new title`n`n");
            $row = array('titleid' => 0, 'male' => '', 'female' => '', 'dk' => 0);
            $id = 0;
        }
        $output->rawOutput("<form action='titleedit.php?op=save&id=$id' method='POST'>");
        Nav::add("", "titleedit.php?op=save&id=$id");
        Forms::showForm($editarray, $row);
        $output->rawOutput("</form>");
        Nav::add("Functions");
        Nav::add("Main Title Editor", "titleedit.php");
        title_help();
        //fallthrough

    default:
        $sql = "SELECT * FROM " . Database::prefix("titles") . " ORDER BY dk, titleid";
        $result = Database::query($sql);
        if (Database::numRows($result) < 1) {
            $output->output("");
        } else {
            $row = Database::fetchAssoc($result);
        }
        $output->output("`@`c`b-=Title Editor=-`b`c");
        $ops = Translator::translate("Ops");
        $dks = Translator::translate("Dragon Kills");
        // $ref is currently unused
        // $reftag = Translator::translate("Reference Tag");
        $mtit = Translator::translate("Male Title");
        $ftit = Translator::translate("Female Title");
        $edit = Translator::translate("Edit");
        $del = Translator::translate("Delete");
        $delconfirm = Translator::translate("Are you sure you wish to delete this title?");
        $output->rawOutput("<table border=0 cellspacing=0 cellpadding=2 width='100%' align='center'>");
        // reference tag is currently unused
        // $output->rawOutput("<tr class='trhead'><td>$ops</td><td>$dks</td><td>$reftag</td><td>$mtit</td><td>$ftit</td></tr>");
        $output->rawOutput("<tr class='trhead'><td>$ops</td><td>$dks</td><td>$mtit</td><td>$ftit</td></tr>");
        $result = Database::query($sql);
        $i = 0;
        while ($row = Database::fetchAssoc($result)) {
            $id = $row['titleid'];
            $output->rawOutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'>");
            $output->rawOutput("<td>[<a href='titleedit.php?op=edit&id=$id'>$edit</a>|<a href='titleedit.php?op=delete&id=$id' onClick='return confirm(\"$delconfirm\");'>$del</a>]</td>");
            Nav::add("", "titleedit.php?op=edit&id=$id");
            Nav::add("", "titleedit.php?op=delete&id=$id");
            $output->rawOutput("<td>");
            $output->outputNotl("`&%s`0", $row['dk']);
            $output->rawOutput("</td><td>");
            // reftag is currently unused
            // $output->output("`^%s`0", $row['ref']);
            // $output->output("</td><td>");
            $output->outputNotl("`2%s`0", $row['male']);
            $output->rawOutput("</td><td>");
            $output->outputNotl("`6%s`0", $row['female']);
            $output->rawOutput("</td></tr>");
            $i++;
        }
        $output->rawOutput("</table>");
        //HookHandler::hook("titleedit", array());
        Nav::add("Functions");
        Nav::add("Add a Title", "titleedit.php?op=add");
        Nav::add("Refresh List", "titleedit.php");
        Nav::add("Reset Users Titles", "titleedit.php?op=reset");
        title_help();
        break;
}

function title_help()
{
    $output->output("`#You can have multiple titles for a given dragon kill rank.");
    $output->output("If you do, one of those titles will be chosen at random to give to the player when a title is assigned.`n`n");
    $output->output("You can have gaps in the title order.");
    $output->output("If you have a gap, the title given will be for the DK rank less than or equal to the players current number of DKs.`n");
}

Footer::pageFooter();
