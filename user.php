<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\DateTime;
use Lotgd\AddNews;

//addnews ready
// mail ready
require_once __DIR__ . "/common.php";
require_once __DIR__ . "/lib/http.php";
require_once __DIR__ . "/lib/sanitize.php";
use Lotgd\Names;

Translator::getInstance()->setSchema("user");
SuAccess::check(SU_EDIT_USERS);

$op = httpget('op');
$userid = (int)httpget("userid");

if ($op == "lasthit") {
    // Try and keep user editor and captcha from breaking each other.
    $_POST['i_am_a_hack'] = 'true';
}
page_header("User Editor");

$sort = httpget('sort');
$petition = httpget("returnpetition");
$returnpetition = "";
if ($petition != "") {
    $returnpetition = "&returnpetition=$petition";
}

$gentime = 0;
$gentimecount = 0;

$order = "acctid";
if ($sort != "") {
    $order = "$sort";
}
$display = 0;
$query = httppost('q');
if ($query === false) {
    $query = httpget('q');
}
if (!$query && $sort) {
    $query = "%";
}

if ($op == "search" || $op == "") {
    require_once __DIR__ . "/lib/lookup_user.php";
    list($searchresult, $err) = lookup_user($query, $order);
    $op = "";
    if ($err) {
        output($err);
    } else {
        if ($searchresult) {
            $display = 1;
        }
    }
}


$m = httpget("module");
if ($m) {
    $m = "&module=$m&subop=module";
}
rawoutput("<form action='user.php?op=search$m' method='POST'>");
output("Search by any field below: ");
rawoutput("<input name='q' id='q'>");
$se = translate_inline("Search");
rawoutput("<input type='submit' class='button' value='$se'>");
rawoutput("</form>");
rawoutput("<script language='JavaScript'>document.getElementById('q').focus();</script>");
addnav("", "user.php?op=search$m");
SuperuserNav::render();
addnav("Bans");
addnav("Add a ban", "bans.php?op=setupban");
addnav("List/Remove bans", "bans.php?op=removeban");
addnav("Search for banned user", "bans.php?op=searchban");


// This doesn't seem to be used, so I'm going to comment it out now
//$msg = httpget('msg');
//if ($msg>"") {
//  output("Message: %s`n", $msg);
//}

// Collect a list of the mounts
$mounts = "0," . translate_inline("None");
$sql = "SELECT mountid,mountname,mountcategory FROM " . Database::prefix("mounts") .  " ORDER BY mountcategory";
$result = Database::query($sql);
while ($row = Database::fetchAssoc($result)) {
    $mounts .= ",{$row['mountid']},{$row['mountcategory']}: " . color_sanitize($row['mountname']);
}

$specialties = array("Undecided" => translate_inline("Undecided"));
$specialties = modulehook("specialtynames", $specialties);
$enum = "";
foreach ($specialties as $key => $name) {
    if ($enum) {
        $enum .= ",";
    }
    $enum .= "$key,$name";
}

//Inserted for v1.1.0 Dragonprime Edition to extend clan possibilities
$ranks = array(CLAN_APPLICANT => "`!Applicant`0",CLAN_MEMBER => "`#Member`0",CLAN_OFFICER => "`^Officer`0",CLAN_LEADER => "`&Leader`0", CLAN_FOUNDER => "`\$Founder");
$ranks = modulehook("clanranks", array("ranks" => $ranks, "clanid" => null, "userid" => $userid));
$ranks = $ranks['ranks'];
$rankstring = "";
foreach ($ranks as $rankid => $rankname) {
    if ($rankstring != "") {
        $rankstring .= ",";
    }
    $rankstring .= $rankid . "," . sanitize($rankname);
}
$races = modulehook("racenames");
//all races here expect such ones no module covers, so we add the users race first.
if ($op == 'edit' || $op == 'save') {
    //add the race
    $sql = "SELECT race FROM " . Database::prefix('accounts') . " WHERE acctid=$userid LIMIT 1;";
    $result = Database::query($sql);
    $row = Database::fetchAssoc($result);
    // Unknown race, else it will scramble the array
    $racesenum = RACE_UNKNOWN . "," . translate_inline("Undecided", "race") . ",";
    foreach ($races as $race) {
        $racesenum .= $race . "," . $race . ",";
    }
    if (!in_array($row['race'], $races)) {
    /*  if ($row['race']=='') {
            //ok, we have a resetted race here
            $racesenum.=$row['race'].",".translate_inline("Undecided","race").",";
        } else {*/
            $racesenum .= $row['race'] . "," . $row['race'] . ",";
//      }
    }
    $racesenum = substr($racesenum, 0, strlen($racesenum) - 1);
    //later on: enumpretrans, because races are already translated in a way...
}
require __DIR__ . "/src/Lotgd/Config/user_account.php";
$sql = "SELECT clanid,clanname,clanshort FROM " . Database::prefix("clans") . " ORDER BY clanshort";
$result = Database::query($sql);
while ($row = Database::fetchAssoc($result)) {
    //ok, we had nuts here wo made clan names with commas - so I replace them with ; ...
    $userinfo['clanid'] .= ",{$row['clanid']}," . str_replace(",", ";", "<{$row['clanshort']}> {$row['clanname']}");
}

switch ($op) {
    case "lasthit":
            require __DIR__ . "/pages/user/user_lasthit.php";
        break;
    case "savemodule":
            require __DIR__ . "/pages/user/user_savemodule.php";
        break;
    case "special":
            require __DIR__ . "/pages/user/user_special.php";
        break;
    case "save":
            require __DIR__ . "/pages/user/user_save.php";
        break;
}

switch ($op) {
    case "edit":
            require __DIR__ . "/pages/user/user_edit.php";
        break;
    case "setupban":
            require __DIR__ . "/pages/user/user_setupban.php";
        break;
    case "del":
            require __DIR__ . "/pages/user/user_del.php";
        break;
    case "saveban":
            require __DIR__ . "/pages/user/user_saveban.php";
        break;
    case "delban":
            require __DIR__ . "/pages/user/user_delban.php";
        break;
    case "removeban":
            require __DIR__ . "/pages/user/user_removeban.php";
        break;
    case "searchban":
            require __DIR__ . "/pages/user/user_searchban.php";
        break;
    case "debuglog":
            require __DIR__ . "/pages/user/user_debuglog.php";
        break;
    case "":
            require __DIR__ . "/pages/user/user_.php";
        break;
}
page_footer();

function show_bitfield($val)
{
    $out = "";
    $v = 1;
    for ($i = 0; $i < 32; $i++) {
        $out .= (int)$val & (int)$v ? "1" : "0";
        $v *= 2;
    }
    return($out);
}
