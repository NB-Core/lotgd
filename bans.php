<?php

declare(strict_types=1);

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\DateTime;
use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\UserLookup;

//addnews ready
// mail ready
require_once("common.php");
use Lotgd\Names;

tlschema("bans");
SuAccess::check(SU_EDIT_BANS);

$op = Http::get('op');
$userid = Http::get("userid");

Header::pageHeader("Ban Editor");

$sort = Http::get('sort');

$gentime = 0;
$gentimecount = 0;

$order = "acctid";
if ($sort != "") {
    $order = "$sort";
}
$display = 0;
$query = Http::post('q');
if ($query === false) {
    $query = Http::get('q');
}
if (!$query && $sort) {
    $query = "%";
}

if ($op == "search" || $op == "") {
    list($searchresult, $err) = UserLookup::lookupUser($query, $order);
    $op = "";
    if ($err) {
        output($err);
    } else {
        if ($searchresult) {
            $display = 1;
        }
    }
}

output("`\$`cWelcome to the Ban Editor`c`0`n`n");

rawoutput("<form action='bans.php?op=search' method='POST'>");
output("Search users by any field: ");
rawoutput("<input name='q' id='q'>");
$se = translate_inline("Search");
rawoutput("<input type='submit' class='button' value='$se'>");
rawoutput("</form>");
rawoutput("<script language='JavaScript'>document.getElementById('q').focus();</script>");
Nav::add("", "bans.php?op=search");



SuperuserNav::render();
Nav::add("Bans");
Nav::add("Add a ban", "bans.php?op=setupban");
Nav::add("List/Remove bans", "bans.php?op=removeban");
Nav::add("Search for banned user", "bans.php?op=searchban");


switch ($op) {
    case "setupban":
            require("pages/bans/case_setupban.php");
        break;
    case "saveban":
            require("pages/bans/case_saveban.php");
        break;
    case "delban":
            require("pages/bans/case_delban.php");
        break;
    case "removeban":
            require("pages/bans/case_removeban.php");
        break;
    case "searchban":
            require("pages/bans/case_searchban.php");
        break;
    default:
            output("From here, you can issue bans for players from being able to play.`n`nBased on the ID = cookie on the machine AND/OR on the IP they accessed the char last the ban takes effect.`n`nNote: Locked chars stay locked, even after they delete their cookie / change their IP.`n`nHowever, they can make new chars and login in that case. You cannot control this.");
            require("pages/bans/case_.php");
}
Footer::pageFooter();
