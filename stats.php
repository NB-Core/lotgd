<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Dhms;
use Lotgd\Http;

// translator ready
// addnews ready
// mail ready
require_once 'common.php';

Translator::getInstance()->setSchema('stats');

SuAccess::check(SU_EDIT_CONFIG);

Header::pageHeader('Stats');
SuperuserNav::render();
//Nav::add("Refresh the stats", "stats.php");
Nav::add('Stats Types');
Nav::add('Totals & Averages', 'stats.php?op=stats');
Nav::add('Top Referers', 'stats.php?op=referers');
Nav::add('Logon Graph', 'stats.php?op=graph');

$op = (string) Http::get('op');

if ($op == "stats" || $op == "") {
    $sql = "SELECT sum(gentimecount) AS c, sum(gentime) AS t, sum(gensize) AS s, count(acctid) AS a FROM " . Database::prefix("accounts");
    $result = Database::query($sql);
    $row = Database::fetchAssoc($result);
    $output->output("`b`%For existing accounts:`b`n");
    $output->output("`@Total Accounts: `^%s`n", number_format($row['a']));
    $output->output("`@Total Hits: `^%s`n", number_format($row['c']));
    $output->output("`@Total Page Gen Time: `^%s`n", Dhms::format($row['t']));
    $output->output("`@Total Page Gen Size: `^%sb`n", number_format($row['s']));
    $output->output("`@Average Page Gen Time: `^%s`n", Dhms::format($row['t'] / $row['c'], true));
    $output->output("`@Average Page Gen Size: `^%s`n", number_format($row['s'] / $row['c']));
} elseif ($op == "referers") {
    $output->output("`n`%`bTop Referers:`b`0`n");
    $output->rawOutput("<table border='0' cellpadding='2' cellspacing='1' bgcolor='#999999'>");
    $name = translate_inline("Name");
    $refs = translate_inline("Referrals");
    $output->rawOutput("<tr class='trhead'><td><b>$name</b></td><td><b>$refs</b></td></tr>");
    $sql = "SELECT count(*) AS c, acct.acctid,acct.name AS referer FROM " . Database::prefix("accounts") . " INNER JOIN " . Database::prefix("accounts") . " AS acct ON acct.acctid = " . Database::prefix("accounts") . ".referer WHERE " . Database::prefix("accounts") . ".referer>0 GROUP BY " . Database::prefix("accounts") . ".referer DESC ORDER BY c DESC";
    $result = Database::query($sql);
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        $output->rawOutput("<tr class='" . ($i % 2 ? "trdark" : "trlight") . "'><td>");
        $output->outputNotl("`@{$row['referer']}`0");
        $output->rawOutput("</td><td>");
        $output->outputNotl("`^{$row['c']}:`0  ");
        $sql = "SELECT name,refererawarded FROM " . Database::prefix("accounts") . " WHERE referer = {$row['acctid']} ORDER BY acctid ASC";
        $res2 = Database::query($sql);
        $number2 = Database::numRows($res2);
        for ($j = 0; $j < $number2; $j++) {
            $r = Database::fetchAssoc($res2);
            $output->outputNotl(($r['refererawarded'] ? "`&" : "`$") . $r['name'] . "`0");
            if ($j != $number2 - 1) {
                $output->outputNotl(",");
            }
        }
        $output->rawOutput("</td></tr>");
    }
    $output->rawOutput("</table>");
} elseif ($op == "graph") {
    $sql = "SELECT count(acctid) AS c, substring(laston,1,10) AS d FROM " . Database::prefix("accounts") . " GROUP BY d DESC ORDER BY d DESC";
    $result = Database::query($sql);
    $output->output("`n`%`bDate accounts last logged on:`b");
    $output->rawOutput("<table border='0' cellpadding='0' cellspacing='0'>");
    $class = "trlight";
    $odate = date("Y-m-d");
    $j = 0;
    $cumul = 0;
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        $diff = (strtotime($odate) - strtotime($row['d'])) / 86400;
        for ($x = 1; $x < $diff; $x++) {
            //if ($j%7==0) $class=($class=="trlight"?"trdark":"trlight");
            //$j++;
            $class = (date("W", strtotime("$odate -$x days")) % 2 ? "trlight" : "trdark");
            $output->rawOutput("<tr class='$class'><td>" . date("Y-m-d", strtotime("$odate -$x days")) . "</td><td>&nbsp;&nbsp;</td><td>0</td><td>&nbsp;&nbsp;</td><td align='right'>$cumul</td></tr>");
        }
    //  if ($j%7==0) $class=($class=="trlight"?"trdark":"trlight");
    //  $j++;
        $class = (date("W", strtotime($row['d'])) % 2 ? "trlight" : "trdark");
        $cumul += $row['c'];
        $output->rawOutput("<tr class='$class'><td>{$row['d']}</td><td>&nbsp;&nbsp;</td><td><img src='images/trans.gif' width='{$row['c']}' border='1' height='5'>{$row['c']}</td><td>&nbsp;&nbsp;</td><td align='right'>$cumul</td></tr>");
        $odate = $row['d'];
    }
    $output->rawOutput("</table>");
}
Footer::pageFooter();
