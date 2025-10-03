<?php

declare(strict_types=1);

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
$category = is_string($category) ? $category : '';
$start = (int) Http::get('start'); //starting
$sortorder = (int) Http::get('sortorder'); // 0 = DESC 1= ASC
$sortby = Http::get('sortby');
$sortby = is_string($sortby) ? $sortby : '';
$encodedSort = urlencode($sortby);

$allowedSeverities = ['info', 'warning', 'error', 'debug'];
$severity = Http::get('severity');
$severity = is_string($severity) ? strtolower($severity) : '';
if (! in_array($severity, $allowedSeverities, true)) {
    $severity = '';
}

if ($category !== '') {
    $encodedCategory = urlencode($category);
    $cat = "&cat=$encodedCategory";
    $sqlcat = "AND " . Database::prefix("gamelog") . ".category = '" . addslashes($category) . "'";
} else {
    $cat = '';
    $sqlcat = '';
}

if ($severity !== '') {
    $sev = '&severity=' . urlencode($severity);
    $sqlseverity = "AND " . Database::prefix("gamelog") . ".severity = '" . addslashes($severity) . "'";
} else {
    $sev = '';
    $sqlseverity = '';
}

$asc_desc = ($sortorder == 0 ? "DESC" : "ASC");

$sqlsort = "";
if ($sortby != '') {
    $sqlsort = " ORDER BY " . $sortby . " " . $asc_desc;
}

$sql = "SELECT count(logid) AS c FROM " . Database::prefix("gamelog") . " WHERE 1 $sqlcat $sqlseverity";
$result = Database::query($sql);
$row = Database::fetchAssoc($result);
$max = $row['c'];


$sql = "SELECT " . Database::prefix("gamelog") . ".*, " . Database::prefix("accounts") . ".name AS name FROM " . Database::prefix("gamelog") . " LEFT JOIN " . Database::prefix("accounts") . " ON " . Database::prefix("gamelog") . ".who = " . Database::prefix("accounts") . ".acctid WHERE 1 $sqlcat $sqlseverity $sqlsort LIMIT $start,$step";
$next = $start + $step;
$prev = $start - $step;
Nav::add("Operations");
Nav::add("Refresh", "gamelog.php?start=$start$cat$sev&sortorder=$sortorder&sortby=$encodedSort");
if ($category !== '') {
    Nav::add("View all", "gamelog.php");
}
Nav::add("Game Log");
if ($next < $max) {
    Nav::add("Next page", "gamelog.php?start=$next$cat$sev&sortorder=$sortorder&sortby=$encodedSort");
}
if ($start > 0) {
    Nav::add("Previous page", "gamelog.php?start=$prev$cat$sev&sortorder=$sortorder&sortby=$encodedSort");
}
$sortParams = "&sortorder=$sortorder&sortby=$encodedSort";
Nav::add("Severity");
Nav::add("All severities", "gamelog.php?start=0$cat$sortParams");
foreach ($allowedSeverities as $severityOption) {
    $label = ucfirst($severityOption);
    Nav::add("Severity: $label", "gamelog.php?start=0$cat&severity=" . urlencode($severityOption) . "$sortParams");
}
$result = Database::query($sql);
$odate = "";
$categories = array();
$severityColors = [
    'info' => '`@',
    'warning' => '`$',
    'error' => '`4',
    'debug' => '`#',
];

$i = 0;
while ($row = Database::fetchAssoc($result)) {
    $dom = date("D, M d", strtotime($row['date']));
    if ($odate != $dom) {
        $output->outputNotl("`n`b`@%s`0`b`n", $dom);
        $odate = $dom;
    }
    $time = date("H:i:s", strtotime($row['date'])) . " (" . reltime(strtotime($row['date'])) . ")";
    $severityValue = strtolower($row['severity'] ?? '');
    if (! isset($severityColors[$severityValue])) {
        $severityValue = 'info';
    }
    $severityLabel = sprintf('`7[%s%s`7]`0', $severityColors[$severityValue], strtoupper($severityValue));
    if ($row['name'] != '' && (int) ($row['who'] ?? 0) !== 0) {
        $output->outputNotl(
            "`7(`\$%s`7) %s %s `7(`&%s`7) (`v%s`7)",
            $row['category'],
            $severityLabel,
            $row['message'],
            $row['name'],
            $time
        );
    } else {
        $output->outputNotl(
            "`7(`\$%s`7) %s System: %s `7(`v%s`7)",
            $row['category'],
            $severityLabel,
            $row['message'],
            $time
        );
    }
    if (!isset($categories[$row['category']]) && $category === '') {
        Nav::add("Operations");
        $categoryLink = "gamelog.php?cat=" . urlencode($row['category']);
        if ($severity !== '') {
            $categoryLink .= '&severity=' . urlencode($severity);
        }
        Nav::add(array("View by `i%s`i", $row['category']), $categoryLink);
        $categories[$row['category']] = 1;
    }
    $output->outputNotl("`n");
}
Nav::add("Sorting");
Nav::add("Sort by date ascending", "gamelog.php?start=$start$cat$sev&sortorder=1&sortby=date");
Nav::add("Sort by date descending", "gamelog.php?start=$start$cat$sev&sortorder=0&sortby=date");
Nav::add("Sort by category ascending", "gamelog.php?start=$start$cat$sev&sortorder=1&sortby=category");
Nav::add("Sort by category descending", "gamelog.php?start=$start$cat$sev&sortorder=0&sortby=category");

Footer::pageFooter();
