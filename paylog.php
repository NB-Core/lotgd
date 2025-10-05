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
use Lotgd\Modules\HookHandler;
use Lotgd\Settings;
use Lotgd\Serialization;
use Lotgd\Paylog\LegacyPayloadNormalizer;

// mail ready
// addnews ready
// translator ready
use Lotgd\Output;

require_once __DIR__ . '/common.php';

if (!function_exists('paylogLogInvalidInfo')) {
    /**
     * Log legacy paylog entries that cannot be decoded.
     */
    function paylogLogInvalidInfo(array $row): void
    {
        static $logged = [];

        $txnid = $row['txnid'] ?? null;
        if ($txnid !== null) {
            if (isset($logged[$txnid])) {
                return;
            }
            $logged[$txnid] = true;
        }

        $payid = $row['payid'] ?? null;
        $identifier = $payid !== null ? sprintf('ID %s', $payid) : 'unknown ID';
        if ($txnid !== null) {
            $identifier .= sprintf(' (txnid %s)', $txnid);
        }

        error_log(sprintf('Paylog entry %s contains an unreadable legacy payload.', $identifier));
    }
}

$settings = Settings::getInstance();
$output = Output::getInstance();

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
HookHandler::hook("paylog", array());

$op = (string) Http::get('op');
$currency = $settings->getSetting('paypalcurrency', 'USD');
if ($op == "") {
    Nav::add('Actions');
    Nav::add('Refresh', 'paylog.php');
    $sql = "SELECT info,txnid FROM " . Database::prefix("paylog") . " WHERE processdate='" . DATETIME_DATEMIN . "'";
    $result = Database::query($sql);
    while ($row = Database::fetchAssoc($result)) {
        $info = Serialization::safeUnserialize($row['info']);
        $normalized = LegacyPayloadNormalizer::normalize($row, $info, $currency);
        if (! $normalized['is_valid']) {
            paylogLogInvalidInfo($row);
            continue;
        }

        if ($normalized['paymentDate'] !== null) {
            $timestamp = strtotime($normalized['paymentDate']);
            if ($timestamp !== false) {
                $sql = "UPDATE " . Database::prefix('paylog') . " SET processdate='" . date("Y-m-d H:i:s", $timestamp) . "' WHERE txnid='" . addslashes($row['txnid']) . "'";
                Database::query($sql);
            }
        }
    }
    $sql = "SELECT YEAR(processdate) AS year, MONTH(processdate) AS month, COALESCE(SUM(amount) - SUM(txfee), 0) AS profit FROM " . Database::prefix('paylog') . " GROUP BY year, month ORDER BY year DESC, month DESC";
    $result = Database::query($sql);
    Nav::add('Months');
    $currentYear = null;
    while ($row = Database::fetchAssoc($result)) {
        if ((int) $currentYear !== (int) $row['year']) {
            $currentYear = (int) $row['year'];
            Nav::addSubHeader((string) $currentYear, false);
        }

        $monthString = sprintf('%04d-%02d', $row['year'], $row['month']);
        $labelDate = sprintf('%04d-%02d-01', $row['year'], $row['month']);
        Nav::add(
            array(
                "%s %s %s",
                date('M Y', strtotime($labelDate)),
                $currency,
                $row['profit']
            ),
            "paylog.php?month={$monthString}"
        );
    }
    $rawMonth = (string) Http::get('month');
    $month = $rawMonth === '' ? date('Y-m') : $rawMonth;
    $startdate = $month . "-01 00:00:00";
    $enddate = date("Y-m-d H:i:s", strtotime("+1 month", strtotime($startdate)));
    $type = Translator::translate("Type");
    $gross = Translator::translate("Gross");
    $fee = Translator::translate("Fee");
    $net = Translator::translate("Net");
    $processed = Translator::translate("Processed");
    $id = Translator::translate("Transaction ID");
    $who = Translator::translate("Who");

    if ($rawMonth === '') {
        $yearlySql = "SELECT YEAR(processdate) AS year, COALESCE(SUM(amount), 0) AS gross_total, COALESCE(SUM(txfee), 0) AS fee_total FROM " . Database::prefix('paylog') . " GROUP BY year ORDER BY year DESC";
        $yearlyResult = Database::query($yearlySql);
        $output->rawOutput("<table border='0' cellpadding='2' cellspacing='1' bgcolor='#999999'>");
        $output->rawOutput("<tr class='trhead'><td>" . Translator::translate('Year') . "</td><td>" . $gross . "</td><td>" . $fee . "</td><td>" . $net . "</td></tr>");
        $yearIndex = 0;
        while ($yearRow = Database::fetchAssoc($yearlyResult)) {
            $output->rawOutput("<tr class='" . ($yearIndex % 2 ? "trlight" : "trdark") . "'><td>");
            $output->outputNotl('%s', $yearRow['year']);
            $output->rawOutput("</td><td>");
            $output->outputNotl('%.2f %s', $yearRow['gross_total'], $currency);
            $output->rawOutput("</td><td>");
            $output->outputNotl('%.2f %s', $yearRow['fee_total'], $currency);
            $output->rawOutput("</td><td>");
            $output->outputNotl('%.2f %s', $yearRow['gross_total'] - $yearRow['fee_total'], $currency);
            $output->rawOutput("</td></tr>");
            ++$yearIndex;
        }
        $output->rawOutput("</table><br>");
    }

    $sql = "SELECT " . Database::prefix("paylog") . ".*," . Database::prefix("accounts") . ".name," . Database::prefix("accounts") . ".donation," . Database::prefix("accounts") . ".donationspent FROM " . Database::prefix("paylog") . " LEFT JOIN " . Database::prefix("accounts") . " ON " . Database::prefix("paylog") . ".acctid = " . Database::prefix("accounts") . ".acctid WHERE processdate>='$startdate' AND processdate < '$enddate' ORDER BY payid DESC";
    $result = Database::query($sql);
    $output->rawOutput("<table border='0' cellpadding='2' cellspacing='1' bgcolor='#999999'>");
    $output->rawOutput("<tr class='trhead'><td>Date</td><td>$id</td><td>$type</td><td>$gross</td><td>$fee</td><td>$net</td><td>$processed</td><td>$who</td></tr>");
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        $info = Serialization::safeUnserialize($row['info']);
        $normalized = LegacyPayloadNormalizer::normalize($row, $info, $currency);
        if (! $normalized['is_valid']) {
            paylogLogInvalidInfo($row);
        }
        $output->rawOutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'><td nowrap>");
        $timestamp = null;
        if ($normalized['paymentDate'] !== null) {
            $timestamp = strtotime($normalized['paymentDate']);
        }
        if ($timestamp === false || $timestamp === null) {
            $fallbackDate = $row['processdate'] ?? '';
            if ($fallbackDate !== '' && $fallbackDate !== DATETIME_DATEMIN) {
                $fallbackTimestamp = strtotime($fallbackDate);
                if ($fallbackTimestamp !== false) {
                    $timestamp = $fallbackTimestamp;
                }
            }
        }
        if ($timestamp !== false && $timestamp !== null) {
            $output->outputNotl(date("m/d H:i", $timestamp));
        } else {
            $output->outputNotl('--');
        }
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", $row['txnid']);
        $output->rawOutput("</td><td>");
        $txnType = $normalized['placeholder']
            ? Translator::translate($normalized['placeholder'])
            : ($normalized['txnType'] ?? Translator::translate('Unknown'));
        $output->outputNotl("%s", $txnType);
        $output->rawOutput("</td><td nowrap>");
        $output->outputNotl("%.2f %s", $normalized['gross'], $normalized['currency']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%.2f", $normalized['fee']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%.2f", $normalized['gross'] - $normalized['fee']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("%s", Translator::translate($row['processed'] ? "`@Yes`0" : "`\$No`0"));
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
            $amt = round($normalized['gross'] * 100, 0);
            $memo = $normalized['memo'];
            if ($normalized['placeholder'] && $memo === '') {
                $memo = Translator::translate($normalized['placeholder']);
            }
            $link = "donators.php?op=add1&name=" . rawurlencode($memo) . "&amt=$amt&txnid={$row['txnid']}";
            $itemNumberRaw = $normalized['itemNumber'] ?? (
                $normalized['placeholder'] ? Translator::translate($normalized['placeholder']) : Translator::translate('Unknown')
            );
            $itemNumber = htmlentities($itemNumberRaw, ENT_COMPAT, $settings->getSetting('charset', 'UTF-8'));
            $memoSafe = htmlentities($memo, ENT_COMPAT, $settings->getSetting('charset', 'UTF-8'));
            $output->rawOutput("-=( <a href='$link' title=\"{$itemNumber}\" alt=\"{$itemNumber}\">[{$memoSafe}]</a> )=-");
            Nav::add('', $link);
        }
        $output->rawOutput("</td></tr>");
    }
    $output->rawOutput("</table>");
}
Footer::pageFooter();
