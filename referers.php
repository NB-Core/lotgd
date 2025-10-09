<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Dhms;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Settings;

// translator ready
// addnews ready
// mail ready


use Lotgd\Output;

require_once __DIR__ . "/common.php";

$settings = Settings::getInstance();
$output = Output::getInstance();

Translator::getInstance()->setSchema("referers");

SuAccess::check(SU_EDIT_CONFIG);

$sql = "DELETE FROM " . Database::prefix("referers") . " WHERE last<'" . date("Y-m-d H:i:s", strtotime("-" . $settings->getSetting('expirecontent', 180) . " days")) . "'";
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
$sort = $sort === false ? '' : (string) $sort;

$refreshUrl = 'referers.php' . ($sort === '' ? '' : '?sort=' . URLEncode($sort));
Nav::add("Refresh", $refreshUrl);
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
$output->rawOutput(
    sprintf(
        "<table border=0 cellpadding=2 cellspacing=1><tr class='trhead'><td>%s</td><td>%s</td><td>URL</td><td>%s</td><td>IP</td></tr>",
        $count,
        $last,
        $dest
    )
);
$result = Database::query($sql);
while ($row = Database::fetchAssoc($result)) {
    $output->rawOutput("<tr class='trdark'><td valign='top'>");
    $rowCount = $row['count'] ?? '';
    $output->outputNotl('`b%s`b', $rowCount);
    $output->rawOutput("</td><td valign='top'>");
    $diffsecs = strtotime("now") - strtotime($row['last']);
    //$output->output((int)($diffsecs/86400)."d ".(int)($diffsecs/3600%3600)."h ".(int)($diffsecs/60%60)."m ".(int)($diffsecs%60)."s");
    $output->outputNotl('`b%s`b', Dhms::format($diffsecs));
    $output->rawOutput("</td><td valign='top' colspan='3'>");
    $site = $row['site'] ?? '';
    $output->outputNotl('`b%s`b', $site === '' ? $none : $site);
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
            $rowCountDetail = $row1['count'] ?? '';
            $output->outputNotl('%s', $rowCountDetail);
            $output->rawOutput("</td><td valign='top'>");
            //$output->output((int)($diffsecs/86400)."d".(int)($diffsecs/3600%3600)."h".(int)($diffsecs/60%60)."m".(int)($diffsecs%60)."s");
            $output->outputNotl('%s', Dhms::format($diffsecs));
            $output->rawOutput("</td><td valign='top'>");
            $uri = (string) ($row1['uri'] ?? '');
            if ($uri !== '') {
                $output->rawOutput(
                    sprintf(
                        "<a href='%s' target='_blank'>%s</a>",
                        HTMLEntities($uri, ENT_COMPAT, $settings->getSetting('charset', 'UTF-8')),
                        HTMLEntities(substr($uri, 0, 100))
                    )
                );
            } else {
                $output->outputNotl('%s', $none);
            }
            $output->outputNotl("`n");
            $output->rawOutput("</td><td valign='top'>");
            $destValue = $row1['dest'] ?? '';
            $output->outputNotl('%s', $destValue === '' ? $notset : $destValue);
            $output->rawOutput("</td><td valign='top'>");
            $ip = $row1['ip'] ?? '';
            $output->outputNotl('%s', $ip === '' ? $notset : $ip);
            $output->rawOutput("</td></tr>");
        } else {
            $skippedcount++;
            $skippedtotal += $row1['count'];
        }
    }
    if ($skippedcount > 0) {
        $output->rawOutput(
            sprintf(
                "<tr class='trlight'><td>%s</td><td valign='top' colspan='4'>",
                $skippedtotal
            )
        );
        $output->outputNotl(sprintf($skipped, $skippedcount));
        $output->rawOutput("</td></tr>");
    }
    //$output->output("</td></tr>",true);
}
$output->rawOutput("</table>");
Footer::pageFooter();
