<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Dhms;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;

// translator ready
// addnews ready
// mail ready


require_once __DIR__ . "/common.php";

Translator::getInstance()->setSchema("referers");

SuAccess::check(SU_EDIT_CONFIG);

$sql = "DELETE FROM " . Database::prefix("referers") . " WHERE last<'" . date("Y-m-d H:i:s", strtotime("-" . getsetting("expirecontent", 180) . " days")) . "'";
Database::query($sql);
$op = Http::get('op');

if ($op == "rebuild") {
    $sql = "SELECT * FROM " . Database::prefix("referers");
    $result = Database::query($sql);
    while ($row = Database::fetchAssoc($result)) {
        $site = str_replace("http://", "", $row['uri']);
        if (strpos($site, "/")) {
            $site = substr($site, 0, strpos($site, "/"));
        }
        $sql = "UPDATE " . Database::prefix("referers") . " SET site='" . addslashes($site) . "' WHERE refererid='{$row['refererid']}'";
        Database::query($sql);
    }
}
SuperuserNav::render();
Nav::add("Referer Options");
Nav::add("", $_SERVER['REQUEST_URI']);
$sort = Http::get('sort');
Nav::add("Refresh", "referers.php?sort=" . URLEncode($sort) . "");
Nav::add("C?Sort by Count", "referers.php?sort=count" . ($sort == "count DESC" ? "" : "+DESC"));
Nav::add("U?Sort by URL", "referers.php?sort=uri" . ($sort == "uri" ? "+DESC" : ""));
Nav::add("T?Sort by Time", "referers.php?sort=last" . ($sort == "last DESC" ? "" : "+DESC"));

Nav::add("Rebuild Sites", "referers.php?op=rebuild");

Header::pageHeader("Referers");
$order = "count DESC";
if ($sort != "") {
    $order = $sort;
}
$sql = "SELECT SUM(count) AS count, MAX(last) AS last,site FROM " . Database::prefix("referers") . " GROUP BY site ORDER BY $order LIMIT 100";
$count = Translator::translate("Count");
$last = Translator::translate("Last");
$dest = Translator::translate("Destination");
$none = Translator::translate("`iNone`i");
$notset = Translator::translate("`iNot set`i");
$skipped = Translator::translate("`i%s records skipped (over a week old)`i");
$output->rawOutput("<table border=0 cellpadding=2 cellspacing=1><tr class='trhead'><td>$count</td><td>$last</td><td>URL</td><td>$dest</td><td>IP</td></tr>");
$result = Database::query($sql);
while ($row = Database::fetchAssoc($result)) {
    $output->rawOutput("<tr class='trdark'><td valign='top'>");
    $output->outputNotl("`b" . $row['count'] . "`b");
    $output->rawOutput("</td><td valign='top'>");
    $diffsecs = strtotime("now") - strtotime($row['last']);
    //$output->output((int)($diffsecs/86400)."d ".(int)($diffsecs/3600%3600)."h ".(int)($diffsecs/60%60)."m ".(int)($diffsecs%60)."s");
    $output->outputNotl("`b" . Dhms::format($diffsecs) . "`b");
    $output->rawOutput("</td><td valign='top' colspan='3'>");
    $output->outputNotl("`b" . ($row['site'] == "" ? $none : $row['site']) . "`b");
    $output->rawOutput("</td></tr>");

    $sql = "SELECT count,last,uri,dest,ip FROM " . Database::prefix("referers") . " WHERE site='" . addslashes($row['site']) . "' ORDER BY {$order} LIMIT 25";
    $result1 = Database::query($sql);
    $skippedcount = 0;
    $skippedtotal = 0;
    $number = Database::numRows($result1);
    for ($k = 0; $k < $number; $k++) {
        $row1 = Database::fetchAssoc($result1);
        $diffsecs = strtotime("now") - strtotime($row1['last']);
        if ($diffsecs <= 604800) {
            $output->rawOutput("<tr class='trlight'><td>");
            $output->outputNotl($row1['count']);
            $output->rawOutput("</td><td valign='top'>");
            //$output->output((int)($diffsecs/86400)."d".(int)($diffsecs/3600%3600)."h".(int)($diffsecs/60%60)."m".(int)($diffsecs%60)."s");
            $output->outputNotl(Dhms::format($diffsecs));
            $output->rawOutput("</td><td valign='top'>");
            if ($row1['uri'] > "") {
                $output->rawOutput("<a href='" . HTMLEntities($row1['uri'], ENT_COMPAT, getsetting("charset", "UTF-8")) . "' target='_blank'>" . HTMLEntities(substr($row1['uri'], 0, 100)) . "</a>");
            } else {
                $output->outputNotl($none);
            }
            $output->outputNotl("`n");
            $output->rawOutput("</td><td valign='top'>");
            $output->outputNotl($row1['dest'] == '' ? $notset : $row1['dest']);
            $output->rawOutput("</td><td valign='top'>");
            $output->outputNotl($row1['ip'] == '' ? $notset : $row1['ip']);
            $output->rawOutput("</td></tr>");
        } else {
            $skippedcount++;
            $skippedtotal += $row1['count'];
        }
    }
    if ($skippedcount > 0) {
        $output->rawOutput("<tr class='trlight'><td>$skippedtotal</td><td valign='top' colspan='4'>");
        $output->outputNotl(sprintf($skipped, $skippedcount));
        $output->rawOutput("</td></tr>");
    }
    //$output->output("</td></tr>",true);
}
$output->rawOutput("</table>");
Footer::pageFooter();
