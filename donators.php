<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";
use Lotgd\Mail;
require_once __DIR__ . "/lib/http.php";

SuAccess::check(SU_EDIT_DONATIONS);

Translator::getInstance()->setSchema("donation");

page_header("Donator's Page");
SuperuserNav::render();


$ret = httpget('ret');
$return = cmd_sanitize($ret);
$return = basename($return);
Translator::getInstance()->setSchema("nav");
addnav("Return whence you came", $return);
Translator::getInstance()->setSchema();

$add = translate_inline("Add Donation");
rawoutput("<form action='donators.php?op=add1&ret=" . rawurlencode($ret) . "' method='POST'>");
addnav("", "donators.php?op=add1&ret=" . rawurlencode($ret) . "");
$name = httppost("name");
if ($name == "") {
    $name = httpget("name");
}
$amt = httppost("amt");
if ($amt == "") {
    $amt = httpget("amt");
}
$reason = httppost("reason");
if ($reason == "") {
    $reason = httpget("reason");
}
$txnid = httppost("txnid");
if ($txnid == "") {
    $txnid = httpget("txnid");
}
if ($reason == "") {
    $reason = translate_inline("manual donation entry");
}


output("`bAdd Donation Points:`b`n");
output("Character: ");
rawoutput("<input name='name' value=\"" . htmlentities($name, ENT_COMPAT, getsetting("charset", "UTF-8")) . "\">");
output("`nPoints: ");
rawoutput("<input name='amt' size='3' value=\"" . htmlentities($amt, ENT_COMPAT, getsetting("charset", "UTF-8")) . "\">");
output("`nReason: ");
rawoutput("<input name='reason' size='30' value=\"" . htmlentities($reason, ENT_COMPAT, getsetting("charset", "UTF-8")) . "\">");
rawoutput("<input type='hidden' name='txnid' value=\"" . htmlentities($txnid, ENT_COMPAT, getsetting("charset", "UTF-8")) . "\">");
output_notl("`n");
if ($txnid > "") {
    output("For transaction: %s`n", $txnid);
}
rawoutput("<input type='submit' class='button' value='$add'>");
rawoutput("</form>");

addnav("Donations");
if (
    ($session['user']['superuser'] & SU_EDIT_PAYLOG) &&
        file_exists("paylog.php")
) {
    addnav("Payment Log", "paylog.php");
}
$op = httpget('op');
if ($op == "add2") {
    $id = httpget('id');
    $amt = httpget('amt');
    $reason = httpget('reason');

    $sql = "SELECT name FROM " . Database::prefix("accounts") . " WHERE acctid=$id;";
    $result = Database::query($sql);
    $row = Database::fetchAssoc($result);
    output("%s donation points added to %s`0, reason: `^%s`0", $amt, $row['name'], $reason);

    $txnid = httpget("txnid");
    $ret = httpget('ret');
    if ($id == $session['user']['acctid']) {
        $session['user']['donation'] += $amt;
    }
    if ($txnid > "") {
        $result = modulehook("donation_adjustments", array("points" => $amt,"amount" => $amt / getsetting('dpointspercurrencyunit', 100),"acctid" => $id,"messages" => array()));
        $points = $result['points'];
        if (!is_array($result['messages'])) {
            $result['messages'] = array($result['messages']);
        }
        foreach ($result['messages'] as $messageid => $message) {
            debuglog($message, false, $id, "donation", 0, false);
        }
    } else {
        $points = $amt;
    }
    // ok to execute when this is the current user, they'll overwrite the
    // value at the end of their page hit, and this will allow the display
    // table to update in real time.
    $sql = "UPDATE " . Database::prefix("accounts") . " SET donation=donation+'$points' WHERE acctid='$id'";
    Database::query($sql);
    modulehook("donation", array("id" => $id, "amt" => $points, "manual" => ($txnid > "" ? false : true)));

    if ($txnid > "") {
        $sql = "UPDATE " . Database::prefix("paylog") . " SET acctid='$id', processed=1 WHERE txnid='$txnid'";
        Database::query($sql);
        debuglog("Received donator points for donating -- Credited manually [$reason]", false, $id, "donation", $points, false);
        redirect("paylog.php");
    } else {
        debuglog("Received donator points -- Manually assigned, not based on a known dollar donation [$reason]", false, $id, "donation", $amt, false);
    }
    Mail::systemMail($id, "Donation Points Added", Translator::getInstance()->sprintfTranslate("`2You have received %s donation points for %s.", $points, $reason));
    httpset('op', "");
    $op = "";
}

if ($op == "") {
    $sql = "SELECT name,donation,donationspent FROM " . Database::prefix("accounts") . " WHERE donation>0 ORDER BY donation DESC LIMIT 25";
    $result = Database::query($sql);

    $name = translate_inline("Name");
    $points = translate_inline("Points");
    $spent = translate_inline("Spent");

    rawoutput("<table border='0' cellpadding='3' cellspacing='1' bgcolor='#999999'>");
    rawoutput("<tr class='trhead'><td>$name</td><td>$points</td><td>$spent</td></tr>");
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        rawoutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'>");
        rawoutput("<td>");
        output_notl("`^%s`0", $row['name']);
        rawoutput("</td><td>");
        output_notl("`@%s`0", number_format($row['donation']));
        rawoutput("</td><td>");
        output_notl("`%%s`0", number_format($row['donationspent']));
        rawoutput("</td>");
        rawoutput("</tr>");
    }
    rawoutput("</table>", true);
} elseif ($op == "add1") {
    $search = "%";
    $name = httppost('name');
    if ($name == '') {
        $name = httpget('name');
    }
    $search = str_replace("'", "\'", $name);
    $sql = "SELECT name,acctid,donation,donationspent FROM " . Database::prefix("accounts") . " WHERE login LIKE '$search' or name LIKE '$search' LIMIT 100";
    $result = Database::query($sql);
    if (Database::numRows($result) == 0) {
        for ($i = 0; $i < strlen($name); $i++) {
            $z = substr($name, $i, 1);
            if ($z == "'") {
                $z = "\'";
            }
            $search .= $z . "%";
        }

        $sql = "SELECT name,acctid,donation,donationspent FROM " . Database::prefix("accounts") . " WHERE login LIKE '$search' or name LIKE '$search' LIMIT 100";
        $result = Database::query($sql);
    }
    $ret = httpget('ret');
    $amt = httppost('amt');
    if ($amt == '') {
        $amt = httpget("amt");
    }
    $reason = httppost("reason");
    if ($reason == "") {
        $reason = httpget("reason");
    }
    $txnid = httppost('txnid');
    if ($txnid == '') {
        $txnid = httpget("txnid");
    }
    output("Confirm the addition of %s points to:`n", $amt);
    if ($reason) {
        output("(Reason: `^`b`i%s`i`b`0)`n`n", $reason);
    }
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        if ($ret != "") {
            rawoutput("<a href='donators.php?op=add2&id={$row['acctid']}&amt=$amt&ret=" . rawurlencode($ret) . "&reason=" . rawurlencode($reason) . "'>");
        } else {
            rawoutput("<a href='donators.php?op=add2&id={$row['acctid']}&amt=$amt&reason=" . rawurlencode($reason) . "&txnid=$txnid'>");
        }
        output_notl("%s (%s/%s)", $row['name'], $row['donation'], $row['donationspent']);
        rawoutput("</a>");
        output_notl("`n");
        if ($ret != "") {
            addnav("", "donators.php?op=add2&id={$row['acctid']}&amt=$amt&ret=" . rawurlencode($ret) . "&reason=" . rawurlencode($reason));
        } else {
            addnav("", "donators.php?op=add2&id={$row['acctid']}&amt=$amt&reason=" . rawurlencode($reason) . "&txnid=$txnid");
        }
    }
}
page_footer();
