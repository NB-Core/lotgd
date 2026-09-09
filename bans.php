<?php

declare(strict_types=1);

use Lotgd\Http;
use Lotgd\Nav;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\Settings;
use Lotgd\SuAccess;
use Lotgd\Translator;
use Lotgd\PlayerSearch;
use Lotgd\Forms;

//addnews ready
// mail ready
require_once __DIR__ . "/common.php";

$output   = Output::getInstance();
$settings = Settings::getInstance();

Translator::getInstance()->setSchema("bans");
SuAccess::check(SU_EDIT_BANS);

$op = Http::get('op');

// The core's own operations, and only those. A POST that does not carry this
// page's form token is treated as if nothing had been sent. An $op this page
// does not implement belongs to a module -- modules render into these pages
// through hooks and may post forms of their own, and an old one cannot carry a
// token it has never heard of -- so it passes through untouched.
//
// Here rather than in each branch: a delete keys off $op with its id in the
// query string, so blanking the body alone would not stop it, and this list is
// the page's inventory of what changes state.
if (Forms::isUnverifiedCoreOp($op, ['delban', 'saveban'])) {
    debuglog('Rejected a state change with an invalid CSRF token.');
    http_response_code(400);
    $op = '';
    $_POST = [];
}

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
 
$playerSearch = new PlayerSearch();
$columns = [
    'acctid',
    'login',
    'name',
    'level',
    'laston',
    'loggedin',
    'gentimecount',
    'gentime',
    'lastip',
    'uniqueid',
    'emailaddress',
];
$searchresult = [];
$err = '';
if ($op == "search" || $op == "") {
    $lookup = $playerSearch->legacyLookup((string) $query, $columns, $order);
    $searchresult = $lookup['rows'];
    $err = $lookup['error'];
    $op = "";
    if ($err) {
        $output->output('%s', $err);
    } else {
        if ($searchresult) {
            $display = 1;
        }
    }
}

$output->output("`\$`cWelcome to the Ban Editor`c`0`n`n");

$output->rawOutput("<form action='bans.php?op=search' method='POST'>");
$output->output("Search users by any field: ");
$output->rawOutput("<input name='q' id='q'>");
$se = Translator::translateInline("Search");
$output->rawOutput("<input type='submit' class='button' value='$se'>");
$output->rawOutput("</form>");
$output->rawOutput("<script language='JavaScript'>document.getElementById('q').focus();</script>");
Nav::add("", "bans.php?op=search");



SuperuserNav::render();
Nav::add("Bans");
Nav::add("Add a ban", "bans.php?op=setupban");
Nav::add("List/Remove bans", "bans.php?op=removeban");
Nav::add("Search for banned user", "bans.php?op=searchban");


switch ($op) {
    case "setupban":
            require __DIR__ . "/pages/bans/case_setupban.php";
        break;
    case "saveban":
            require __DIR__ . "/pages/bans/case_saveban.php";
        break;
    case "delban":
            require __DIR__ . "/pages/bans/case_delban.php";
        break;
    case "removeban":
            require __DIR__ . "/pages/bans/case_removeban.php";
        break;
    case "searchban":
            require __DIR__ . "/pages/bans/case_searchban.php";
        break;
    default:
            $output->output("From here, you can issue bans for players from being able to play.`n`nBased on the ID = cookie on the machine AND/OR on the IP they accessed the char last the ban takes effect.`n`nNote: Locked chars stay locked, even after they delete their cookie / change their IP.`n`nHowever, they can make new chars and login in that case. You cannot control this.");
            require __DIR__ . "/pages/bans/case_.php";
}
Footer::pageFooter();
