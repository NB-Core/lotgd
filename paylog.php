<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;

// mail ready
// addnews ready
// translator ready
require_once 'common.php';

Translator::getInstance()->setSchema("paylog");

SuAccess::check(SU_EDIT_PAYLOG);
/*
+-----------+---------------------+------+-----+---------+----------------+
| Field     | Type                | Null | Key | Default | Extra          |
+-----------+---------------------+------+-----+---------+----------------+
| payid     | int(11)             |      | PRI | NULL    | auto_increment |
| info      | text                |      |     |         |                |
| response  | text                |      |     |         |                |
| txnid     | varchar(32)         |      | MUL |         |                |
| amount    | float(9,2)          |      |     | 0.00    |                |
| name      | varchar(50)         |      |     |         |                |
| acctid    | int(11) unsigned    |      |     | 0       |                |
| processed | tinyint(4) unsigned |      |     | 0       |                |
| filed     | tinyint(4) unsigned |      |     | 0       |                |
| txfee     | float(9,2)          |      |     | 0.00    |                |
+-----------+---------------------+------+-----+---------+----------------+
*/
Header::pageHeader('Payment Log');
SuperuserNav::render();
modulehook("paylog", array());

$op = (string) Http::get('op');
if ($op == "") {
    $sql = "SELECT info,txnid FROM " . Database::prefix("paylog") . " WHERE processdate='" . DATETIME_DATEMIN . "'";
    $result = Database::query($sql);
    while ($row = Database::fetchAssoc($result)) {
        $info = unserialize($row['info']);
        $sql = "UPDATE " . Database::prefix('paylog') . " SET processdate='" . date("Y-m-d H:i:s", strtotime($info['payment_date'])) . "' WHERE txnid='" . addslashes($row['txnid']) . "'";
        Database::query($sql);
    }
    $sql = "SELECT substring(processdate,1,7) AS month, sum(amount)-sum(txfee) AS profit FROM " . Database::prefix('paylog') . " GROUP BY month ORDER BY month DESC";
    $result = Database::query($sql);
    Nav::add('Months');
    while ($row = Database::fetchAssoc($result)) {
        Nav::add(array("%s %s %s", date("M Y", strtotime($row['month'] . '-01')), getsetting('paypalcurrency', 'USD'), $row['profit']), "paylog.php?month={$row['month']}");
    }
    $month = (string) Http::get('month');
    if ($month == "") {
        $month = date("Y-m");
    }
    $startdate = $month . "-01 00:00:00";
    $enddate = date("Y-m-d H:i:s", strtotime("+1 month", strtotime($startdate)));
    $sql = "SELECT " . Database::prefix("paylog") . ".*," . Database::prefix("accounts") . ".name," . Database::prefix("accounts") . ".donation," . Database::prefix("accounts") . ".donationspent FROM " . Database::prefix("paylog") . " LEFT JOIN " . Database::prefix("accounts") . " ON " . Database::prefix("paylog") . ".acctid = " . Database::prefix("accounts") . ".acctid WHERE processdate>='$startdate' AND processdate < '$enddate' ORDER BY payid DESC";
    $result = Database::query($sql);
    $output->rawOutput("<table border='0' cellpadding='2' cellspacing='1' bgcolor='#999999'>");
    $type = translate_inline("Type");
    $gross = translate_inline("Gross");
    $fee = translate_inline("Fee");
    $net = translate_inline("Net");
    $processed = translate_inline("Processed");
    $id = translate_inline("Transaction ID");
    $who = translate_inline("Who");
    $output->rawOutput("<tr class='trhead'><td>Date</td><td>$id</td><td>$type</td><td>$gross</td><td>$fee</td><td>$net</td><td>$processed</td><td>$who</td></tr>");
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        $info = unserialize($row['info']);
        $output->rawOutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'><td nowrap>");
        $output->outputNotl(date("m/d H:i", strtotime($info['payment_date'])));
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['txnid']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $info['txn_type']);
        $output->rawOutput("</td><td nowrap>");
        $output->outputNotl("%.2f %s", $info['mc_gross'], $info['mc_currency']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $info['mc_fee']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%.2f", (float)$info['mc_gross'] - (float)$info['mc_fee']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", translate_inline($row['processed'] ? "`@Yes`0" : "`\$No`0"));
        $output->rawOutput("</td><td nowrap>");
        if ($row['name'] > "") {
            $output->rawOutput("<a href='user.php?op=edit&userid={$row['acctid']}'>");
            $output->outputNotl(
                "`&%s`0 (%d/%d)",
                $row['name'],
                $row['donationspent'],
                $row['donation']
            );
            $output->rawOutput("</a>");
            Nav::add('', "user.php?op=edit&userid={$row['acctid']}");
        } else {
            $amt = round((float)$info['mc_gross'] * 100, 0);
            $memo = "";
            if (isset($info['memo'])) {
                $memo = $info['memo'];
            }
            $link = "donators.php?op=add1&name=" . rawurlencode($memo) . "&amt=$amt&txnid={$row['txnid']}";
            $output->rawOutput("-=( <a href='$link' title=\"" . htmlentities($info['item_number'], ENT_COMPAT, getsetting("charset", "UTF-8")) . "\" alt=\"" . htmlentities($info['item_number'], ENT_COMPAT, getsetting("charset", "UTF-8")) . "\">[" . htmlentities($memo, ENT_COMPAT, getsetting("charset", "UTF-8")) . "]</a> )=-");
            Nav::add('', $link);
        }
        $output->rawOutput("</td></tr>");
    }
    $output->rawOutput("</table>");
    Nav::add('Refresh', 'paylog.php');
}
Footer::pageFooter();
