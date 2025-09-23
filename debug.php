<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";

Translator::getInstance()->setSchema("debug");

SuAccess::check(SU_EDIT_CONFIG);

SuperuserNav::render();
Nav::add("Debug Options");
Nav::add("", $_SERVER['REQUEST_URI']);
$sort = Http::get('sort');
Nav::add("Get Pageruntimes", "debug.php?debug=pageruntime&sort=" . URLEncode($sort));
Nav::add("Get Modulehooktimes", "debug.php?debug=hooksort&sort=" . URLEncode($sort));


Header::pageHeader("Debug Analysis");
$order = "sum";
if ($sort != "") {
    $order = $sort;
}
$debug = Http::get('debug');
if ($debug == '') {
    $debug = 'pageruntime';
}
$ascdesc_raw = (int)Http::get('direction');
if ($ascdesc_raw) {
    $ascdesc = "ASC";
} else {
    $ascdesc = "DESC";
}
Nav::add("Sorting");
Nav::add("By Total", "debug.php?debug=" . $debug . "&sort=sum&direction=" . $ascdesc_raw);
Nav::add("By Average", "debug.php?debug=" . $debug . "&sort=medium&direction=" . $ascdesc_raw);
Nav::add("Switch ASC/DESC", "debug.php?debug=" . $debug . "&sort=" . URLEncode($sort) . "&direction=" . (!$ascdesc_raw));


switch ($debug) {
    case "hooksort":
        $sql = "SELECT 
					category, 
					subcategory, 
					SUM(value + 0) AS sum,
					AVG(value + 0) AS medium,
					COUNT(id) AS counter
				FROM 
					`" . Database::prefix('debug') . "` 
				WHERE 
					type = 'hooktime'
				GROUP BY 
					category, subcategory
				ORDER BY 
					$order $ascdesc
				LIMIT 30;
				";
        $category = Translator::translate("Setting");
        $subcategory = Translator::translate("Module Name");
        $sum_desc = Translator::translate("Total Seconds");
        $med_desc = Translator::translate("Average per Hit");
        $hits = Translator::translate("Hits");
        break;

    case "pageruntime":
    default:
        $sql = "SELECT 
				type,
				category, 
				subcategory, 
				SUM(value + 0) AS sum,
				AVG(value + 0) AS medium,
				COUNT(id) AS counter
			FROM " . Database::prefix('debug') . "
			WHERE type = 'pagegentime'
			GROUP BY type, category, subcategory
			ORDER BY $order $ascdesc
			LIMIT 30";

        $category = Translator::translate("Setting");
        $subcategory = Translator::translate("Module Name");
        $sum_desc = Translator::translate("Total Seconds");
        $med_desc = Translator::translate("Average per Hit");
        $hits = Translator::translate("Hits");
}
$none = Translator::translate("`iNone`i");
$notset = Translator::translate("`iNot set`i");
$output->rawOutput("<table border=0 cellpadding=2 cellspacing=1><tr class='trhead'><td>$category</td><td>$subcategory</td><td>$sum_desc</td><td>$med_desc</td><td>$hits</td></tr>");
debug($sql);
$result = Database::query($sql);
$i = true;
while ($row = Database::fetchAssoc($result)) {
    $i = !$i;
    $output->rawOutput("<tr class='" . ($i ? 'trdark' : 'trlight') . "'><td valign='top'>");
    $output->outputNotl("`b" . $row['category'] . "`b");
    $output->rawOutput("</td><td valign='top'>");
    $output->outputNotl("`b" . $row['subcategory'] . "`b");
    $output->rawOutput("</td><td valign='top'>");
    $output->outputNotl($row['sum']);
    $output->rawOutput("</td><td valign='top'>");
    $output->outputNotl($row['medium']);
    $output->rawOutput("</td><td valign='top'>");
    $output->outputNotl($row['counter']);
    $output->rawOutput("</td></tr>");
}
$output->rawOutput("</table>");
Footer::pageFooter();
