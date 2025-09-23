<?php

declare(strict_types=1);

use Lotgd\DateTime;
use Lotgd\Commentary;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\Nav\VillageNav;
use Lotgd\Translator;
use Lotgd\Output;
use Lotgd\Modules\HookHandler;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";

$output = Output::getInstance();


Translator::getInstance()->setSchema("account");

Header::pageHeader("Account Information");
Commentary::addCommentary();
DateTime::checkDay();

$output->output("`\$Some stats concerning your account. Note that this in the timezone of the server.`0`n`n");
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
    $entry['title'] = Translator::translateInline($entry['title']);
    $newstats[] = $entry;
}
$stats = $newstats;

$stats = HookHandler::hook("accountstats", $stats);
$output->rawOutput("<table>");
foreach ($stats as $entry) {
    $output->rawOutput("<tr><td>");
    $output->outputNotl("`q" . $entry['title']);
    $output->rawOutput("</td><td>");
    $output->outputNotl("`\$" . $entry['value']);
    $output->rawOutput("</td></tr>");
}
$output->rawOutput("</table>");


Translator::getInstance()->setSchema();

Footer::pageFooter();
