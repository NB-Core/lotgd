<?php

declare(strict_types=1);

use Lotgd\DateTime;
use Lotgd\Commentary;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\Nav\VillageNav;
use Lotgd\Translator;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";


Translator::getInstance()->setSchema("account");

Header::pageHeader("Account Information");
Commentary::addCommentary();
DateTime::checkDay();

output("`\$Some stats concerning your account. Note that this in the timezone of the server.`0`n`n");
Nav::add("Navigation");
VillageNav::render();
Nav::add("Actions");
Nav::add("Refresh", "account.php");

$user = $session['user'];

//pre-fill
$stats = array();

$stats[] = array("title" => "Account created on:","value" => ($user['regdate'] == DATETIME_DATEMIN ? "Too old to be traced" : $user['regdate']));
$stats[] = array("title" => "Last Comment posted:","value" => $user['recentcomments']);
$stats[] = array("title" => "Last PvP happened:","value" => $user['pvpflag']);
$stats[] = array("title" => "Dragonkills:","value" => $user['dragonkills']);
$stats[] = array("title" => "Total Pages generated for you:","value" => $user['gentimecount']);
$stats[] = array(
    "title" => "How long did these pages take to generate:",
    "value" => DateTime::readableTime($user['gentime'])
);
$stats[] = array("title" => "You are Account Number:","value" => ($user['acctid'] - 1));
//Add the count summary for DKs
$dksummary = "";
if ($user['dragonkills'] > 0) {
    $dragonpointssummary = array_count_values($user['dragonpoints']);
} else {
    $dragonpointssummary = array();
}
foreach ($dragonpointssummary as $key => $value) {
    $dksummary .= "$key --> $value`n";
}
$stats[] = array("title" => "Dragon Point Spending:","value" => $dksummary);
//translate...
foreach ($stats as $entry) {
    $entry['title'] = translate_inline($entry['title']);
    $newstats[] = $entry;
}
$stats = $newstats;

$stats = modulehook("accountstats", $stats);
rawoutput("<table>");
foreach ($stats as $entry) {
    rawoutput("<tr><td>");
    output_notl("`q" . $entry['title']);
    rawoutput("</td><td>");
    output_notl("`\$" . $entry['value']);
    rawoutput("</td></tr>");
}
rawoutput("</table>");


Translator::getInstance()->setSchema();

Footer::pageFooter();
