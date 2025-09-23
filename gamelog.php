<?php

use Lotgd\MySQL\Database;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Output;

// translator ready
// addnews ready
// mail ready

// Written by Christian Rutsch

if (!defined('GAMELOG_TEST')) {
    require_once __DIR__ . "/common.php";
}

if (!isset($output)) {
    $output = Output::getInstance();
}

SuAccess::check(SU_EDIT_CONFIG);


Header::pageHeader("Game Log");
Nav::add("Navigation");
SuperuserNav::render();

$step = 500; // hardcoded stepping
$category = Http::get('cat');
$start = (int)Http::get('start'); //starting
$sortorder = (int) Http::get('sortorder'); // 0 = DESC 1= ASC
$sortby = Http::get('sortby');
if ($category > "") {
    $cat = "&cat=$category";
    $sqlcat = "AND " . Database::prefix("gamelog") . ".category = '$category'";
} else {
    $cat = '';
    $sqlcat = '';
}

$asc_desc = ($sortorder == 0 ? "DESC" : "ASC");

$sqlsort = "";
if ($sortby != '') {
    $sqlsort = " ORDER BY " . $sortby . " " . $asc_desc;
}

$sql = "SELECT count(logid) AS c FROM " . Database::prefix("gamelog") . " WHERE 1 $sqlcat";
$result = Database::query($sql);
$row = Database::fetchAssoc($result);
$max = $row['c'];


$sql = "SELECT " . Database::prefix("gamelog") . ".*, " . Database::prefix("accounts") . ".name AS name FROM " . Database::prefix("gamelog") . " LEFT JOIN " . Database::prefix("accounts") . " ON " . Database::prefix("gamelog") . ".who = " . Database::prefix("accounts") . ".acctid WHERE 1 $sqlcat $sqlsort LIMIT $start,$step";
$next = $start + $step;
$prev = $start - $step;
Nav::add("Operations");
Nav::add("Refresh", "gamelog.php?start=$start$cat&sortorder=$sortorder&sortby=$sortby");
if ($category > "") {
    Nav::add("View all", "gamelog.php");
}
Nav::add("Game Log");
if ($next < $max) {
    Nav::add("Next page", "gamelog.php?start=$next$cat&sortorder=$sortorder&sortby=$sortby");
}
if ($start > 0) {
    Nav::add("Previous page", "gamelog.php?start=$prev$cat&sortorder=$sortorder&sortby=$sortby");
}
$result = Database::query($sql);
$odate = "";
$categories = array();

$i = 0;
while ($row = Database::fetchAssoc($result)) {
    $dom = date("D, M d", strtotime($row['date']));
    if ($odate != $dom) {
        $output->outputNotl("`n`b`@%s`0`b`n", $dom);
        $odate = $dom;
    }
    $time = date("H:i:s", strtotime($row['date'])) . " (" . reltime(strtotime($row['date'])) . ")";
    if ($row['name'] != '' && (int) ($row['who'] ?? 0) !== 0) {
        $output->outputNotl("`7(`\$%s`7) %s `7(`&%s`7) (`v%s`7)", $row['category'], $row['message'], $row['name'], $time);
    } else {
        $output->outputNotl(
            "`7(`\$%s`7) %s: %s `7(`v%s`7)",
            $row['category'],
            'System',
            $row['message'],
            $time
        );
    }
    if (!isset($categories[$row['category']]) && $category == "") {
        Nav::add("Operations");
        Nav::add(array("View by `i%s`i", $row['category']), "gamelog.php?cat=" . $row['category']);
        $categories[$row['category']] = 1;
    }
    $output->outputNotl("`n");
}
Nav::add("Sorting");
Nav::add("Sort by date ascending", "gamelog.php?start=$start$cat&sortorder=1&sortby=date");
Nav::add("Sort by date descending", "gamelog.php?start=$start$cat&sortorder=0&sortby=date");
Nav::add("Sort by category ascending", "gamelog.php?start=$start$cat&sortorder=1&sortby=category");
Nav::add("Sort by category descending", "gamelog.php?start=$start$cat&sortorder=0&sortby=category");

Footer::pageFooter();
