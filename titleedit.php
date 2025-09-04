<?php

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Names;
use Lotgd\Forms;

//Author: Lonny Luberts - 3/18/2005
//Heavily modified by JT Traub
require_once("common.php");
require_once("lib/http.php");

SuAccess::check(SU_EDIT_USERS);

tlschema("retitle");

page_header("Title Editor");
$op = httpget('op');
$id = httpget('id');
$editarray = array(
    "Titles,title",
    //"titleid"=>"Title Id,hidden",
    "dk" => "Dragon Kills,int|0",
    // "ref"=>"Arbitrary Tag,int",
    "male" => "Male Title,text|",
    "female" => "Female Title,text|",
);
addnav("Other");
SuperuserNav::render();
addnav("Functions");

switch ($op) {
    case "save":
        $male = httppost('male');
        $female = httppost('female');
        $dk = httppost('dk');
        // Ref is currently unused
        // $ref = httppost('ref');
        $ref = '';

        if ((int)$id == 0) {
            $sql = "INSERT INTO " . \Lotgd\MySQL\Database::prefix("titles") . " (titleid,dk,ref,male,female) VALUES ($id,$dk,'$ref','$male','$female')";
            $note = "`^New title added.`0";
            $errnote = "`\$Unable to add title.`0";
        } else {
            $sql = "UPDATE " . \Lotgd\MySQL\Database::prefix("titles") . " SET dk=$dk,ref='$ref',male='$male',female='$female' WHERE titleid=$id";
            $note = "`^Title modified.`0";
            $errnote = "`\$Unable to modify title.`0";
        }
        \Lotgd\MySQL\Database::query($sql);
        if (\Lotgd\MySQL\Database::affectedRows() == 0) {
            output($errnote);
            rawoutput(\Lotgd\MySQL\Database::error());
        } else {
            output($note);
        }
        $op = "";
        break;
    case "delete":
        $sql = "DELETE FROM " . \Lotgd\MySQL\Database::prefix("titles") . " WHERE titleid='$id'";
        \Lotgd\MySQL\Database::query($sql);
        output("`^Title deleted.`0");
        $op = "";
        break;
}

switch ($op) {
    case "reset":
                require_once("lib/titles.php");

        output("`^Rebuilding all titles for all players.`0`n`n");
        $sql = "SELECT name,title,dragonkills,acctid,sex,ctitle FROM " . \Lotgd\MySQL\Database::prefix("accounts");
        $result = \Lotgd\MySQL\Database::query($sql);
        $number = \Lotgd\MySQL\Database::numRows($result);
        for ($i = 0; $i < $number; $i++) {
            $row = \Lotgd\MySQL\Database::fetchAssoc($result);
            $oname = $row['name'];
            $dk = $row['dragonkills'];
            $otitle = $row['title'];
            $dk = (int)($row['dragonkills']);
            if (!valid_dk_title($otitle, $dk, $row['sex'])) {
                $sex = translate_inline($row['sex'] ? "female" : "male");
                $newtitle = get_dk_title($dk, (int)$row['sex']);
                $newname = Names::changePlayerTitle($newtitle, $row);
                $id = $row['acctid'];
                if ($oname != $newname) {
                    output(
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
                        $sql = "UPDATE " . \Lotgd\MySQL\Database::prefix("accounts") . " SET name='" .
                            addslashes($newname) . "', title='" .
                            addslashes($newtitle) . "' WHERE acctid='$id'";
                        \Lotgd\MySQL\Database::query($sql);
                    }
                } elseif ($otitle != $newtitle) {
                    output(
                        "`@Changing only the title (not the name) of `^%s`@ `@(%s`@ [%s,%s])`n",
                        $oname,
                        $newtitle,
                        $dk,
                        $sex
                    );
                    if ($session['user']['acctid'] == $row['acctid']) {
                        $session['user']['title'] = $newtitle;
                    } else {
                        $sql = "UPDATE " . \Lotgd\MySQL\Database::prefix("accounts") .
                            " SET title='" . addslashes($newtitle) .
                            "' WHERE acctid='$id'";
                        \Lotgd\MySQL\Database::query($sql);
                    }
                }
            }
        }
        output("`n`n`^Done.`0");
        addnav("Main Title Editor", "titleedit.php");
        break;

    case "edit":
    case "add":
        if ($op == "edit") {
            output("`\$Editing an existing title`n`n");
            $sql = "SELECT * FROM " . \Lotgd\MySQL\Database::prefix("titles") . " WHERE titleid='$id'";
            $result = \Lotgd\MySQL\Database::query($sql);
            $row = \Lotgd\MySQL\Database::fetchAssoc($result);
        } elseif ($op == "add") {
            output("`\$Adding a new title`n`n");
            $row = array('titleid' => 0, 'male' => '', 'female' => '', 'dk' => 0);
            $id = 0;
        }
        rawoutput("<form action='titleedit.php?op=save&id=$id' method='POST'>");
        addnav("", "titleedit.php?op=save&id=$id");
        Forms::showForm($editarray, $row);
        rawoutput("</form>");
        addnav("Functions");
        addnav("Main Title Editor", "titleedit.php");
        title_help();
        //fallthrough

    default:
        $sql = "SELECT * FROM " . \Lotgd\MySQL\Database::prefix("titles") . " ORDER BY dk, titleid";
        $result = \Lotgd\MySQL\Database::query($sql);
        if (\Lotgd\MySQL\Database::numRows($result) < 1) {
            output("");
        } else {
            $row = \Lotgd\MySQL\Database::fetchAssoc($result);
        }
        output("`@`c`b-=Title Editor=-`b`c");
        $ops = translate_inline("Ops");
        $dks = translate_inline("Dragon Kills");
        // $ref is currently unused
        // $reftag = translate_inline("Reference Tag");
        $mtit = translate_inline("Male Title");
        $ftit = translate_inline("Female Title");
        $edit = translate_inline("Edit");
        $del = translate_inline("Delete");
        $delconfirm = translate_inline("Are you sure you wish to delete this title?");
        rawoutput("<table border=0 cellspacing=0 cellpadding=2 width='100%' align='center'>");
        // reference tag is currently unused
        // rawoutput("<tr class='trhead'><td>$ops</td><td>$dks</td><td>$reftag</td><td>$mtit</td><td>$ftit</td></tr>");
        rawoutput("<tr class='trhead'><td>$ops</td><td>$dks</td><td>$mtit</td><td>$ftit</td></tr>");
        $result = \Lotgd\MySQL\Database::query($sql);
        $i = 0;
        while ($row = \Lotgd\MySQL\Database::fetchAssoc($result)) {
            $id = $row['titleid'];
            rawoutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'>");
            rawoutput("<td>[<a href='titleedit.php?op=edit&id=$id'>$edit</a>|<a href='titleedit.php?op=delete&id=$id' onClick='return confirm(\"$delconfirm\");'>$del</a>]</td>");
            addnav("", "titleedit.php?op=edit&id=$id");
            addnav("", "titleedit.php?op=delete&id=$id");
            rawoutput("<td>");
            output_notl("`&%s`0", $row['dk']);
            rawoutput("</td><td>");
            // reftag is currently unused
            // output("`^%s`0", $row['ref']);
            // output("</td><td>");
            output_notl("`2%s`0", $row['male']);
            rawoutput("</td><td>");
            output_notl("`6%s`0", $row['female']);
            rawoutput("</td></tr>");
            $i++;
        }
        rawoutput("</table>");
        //modulehook("titleedit", array());
        addnav("Functions");
        addnav("Add a Title", "titleedit.php?op=add");
        addnav("Refresh List", "titleedit.php");
        addnav("Reset Users Titles", "titleedit.php?op=reset");
        title_help();
        break;
}

function title_help()
{
    output("`#You can have multiple titles for a given dragon kill rank.");
    output("If you do, one of those titles will be chosen at random to give to the player when a title is assigned.`n`n");
    output("You can have gaps in the title order.");
    output("If you have a gap, the title given will be for the DK rank less than or equal to the players current number of DKs.`n");
}

page_footer();
