<?php

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Mail;
use Lotgd\Nav;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";


SuAccess::check(SU_EDIT_DONATIONS);

Translator::getInstance()->setSchema("donation");

Header::pageHeader("Donator's Page");
SuperuserNav::render();


$ret = Http::get('ret');
$return = cmd_sanitize($ret);
$return = basename($return);
Translator::getInstance()->setSchema("nav");
Nav::add("Return whence you came", $return);
Translator::getInstance()->setSchema();

$add = Translator::translate("Add Donation");
$output->rawOutput("<form action='donators.php?op=add1&ret=" . rawurlencode($ret) . "' method='POST'>");
Nav::add("", "donators.php?op=add1&ret=" . rawurlencode($ret) . "");
$name = Http::post("name");
if ($name == "") {
    $name = Http::get("name");
}
$amt = Http::post("amt");
if ($amt == "") {
    $amt = Http::get("amt");
}
$reason = Http::post("reason");
if ($reason == "") {
    $reason = Http::get("reason");
}
$txnid = Http::post("txnid");
if ($txnid == "") {
    $txnid = Http::get("txnid");
}
if ($reason == "") {
    $reason = Translator::translate("manual donation entry");
}


$output->output("`bAdd Donation Points:`b`n");
$output->output("Character: ");
$output->rawOutput("<input name='name' value=\"" . htmlentities($name, ENT_COMPAT, getsetting("charset", "UTF-8")) . "\">");
$output->output("`nPoints: ");
$output->rawOutput("<input name='amt' size='3' value=\"" . htmlentities($amt, ENT_COMPAT, getsetting("charset", "UTF-8")) . "\">");
$output->output("`nReason: ");
$output->rawOutput("<input name='reason' size='30' value=\"" . htmlentities($reason, ENT_COMPAT, getsetting("charset", "UTF-8")) . "\">");
$output->rawOutput("<input type='hidden' name='txnid' value=\"" . htmlentities($txnid, ENT_COMPAT, getsetting("charset", "UTF-8")) . "\">");
$output->outputNotl("`n");
if ($txnid > "") {
    $output->output("For transaction: %s`n", $txnid);
}
$output->rawOutput("<input type='submit' class='button' value='$add'>");
$output->rawOutput("</form>");

Nav::add("Donations");
if (
    ($session['user']['superuser'] & SU_EDIT_PAYLOG) &&
        file_exists("paylog.php")
) {
    Nav::add("Payment Log", "paylog.php");
}
$op = Http::get('op');
if ($op == "add2") {
    $id = Http::get('id');
    $amt = Http::get('amt');
    $reason = Http::get('reason');

    $sql = "SELECT name FROM " . Database::prefix("accounts") . " WHERE acctid=$id;";
    $result = Database::query($sql);
    $row = Database::fetchAssoc($result);
    $output->output("%s donation points added to %s`0, reason: `^%s`0", $amt, $row['name'], $reason);

    $txnid = Http::get("txnid");
    $ret = Http::get('ret');
    if ($id == $session['user']['acctid']) {
        $session['user']['donation'] += $amt;
    }
    if ($txnid > "") {
        $result = HookHandler::hook("donation_adjustments", array("points" => $amt,"amount" => $amt / getsetting('dpointspercurrencyunit', 100),"acctid" => $id,"messages" => array()));
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
    HookHandler::hook("donation", array("id" => $id, "amt" => $points, "manual" => ($txnid > "" ? false : true)));

    if ($txnid > "") {
        $sql = "UPDATE " . Database::prefix("paylog") . " SET acctid='$id', processed=1 WHERE txnid='$txnid'";
        Database::query($sql);
        debuglog("Received donator points for donating -- Credited manually [$reason]", false, $id, "donation", $points, false);
        redirect("paylog.php");
    } else {
        debuglog("Received donator points -- Manually assigned, not based on a known dollar donation [$reason]", false, $id, "donation", $amt, false);
    }
    Mail::systemMail($id, "Donation Points Added", Translator::getInstance()->sprintfTranslate("`2You have received %s donation points for %s.", $points, $reason));
    Http::set('op', "");
    $op = "";
}

if ($op == "") {
    $sql = "SELECT name,donation,donationspent FROM " . Database::prefix("accounts") . " WHERE donation>0 ORDER BY donation DESC LIMIT 25";
    $result = Database::query($sql);

    $name = Translator::translate("Name");
    $points = Translator::translate("Points");
    $spent = Translator::translate("Spent");

    $output->rawOutput("<table border='0' cellpadding='3' cellspacing='1' bgcolor='#999999'>");
    $output->rawOutput("<tr class='trhead'><td>$name</td><td>$points</td><td>$spent</td></tr>");
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        $output->rawOutput("<tr class='" . ($i % 2 ? "trlight" : "trdark") . "'>");
        $output->rawOutput("<td>");
        $output->outputNotl("`^%s`0", $row['name']);
        $output->rawOutput("</td><td>");
        $output->outputNotl("`@%s`0", number_format($row['donation']));
        $output->rawOutput("</td><td>");
        $output->outputNotl("`%%s`0", number_format($row['donationspent']));
        $output->rawOutput("</td>");
        $output->rawOutput("</tr>");
    }
    $output->rawOutput("</table>", true);
} elseif ($op == "add1") {
    $search = "%";
    $name = Http::post('name');
    if ($name == '') {
        $name = Http::get('name');
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
    $ret = Http::get('ret');
    $amt = Http::post('amt');
    if ($amt == '') {
        $amt = Http::get("amt");
    }
    $reason = Http::post("reason");
    if ($reason == "") {
        $reason = Http::get("reason");
    }
    $txnid = Http::post('txnid');
    if ($txnid == '') {
        $txnid = Http::get("txnid");
    }
    $output->output("Confirm the addition of %s points to:`n", $amt);
    if ($reason) {
        $output->output("(Reason: `^`b`i%s`i`b`0)`n`n", $reason);
    }
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $row = Database::fetchAssoc($result);
        if ($ret != "") {
            $output->rawOutput("<a href='donators.php?op=add2&id={$row['acctid']}&amt=$amt&ret=" . rawurlencode($ret) . "&reason=" . rawurlencode($reason) . "'>");
        } else {
            $output->rawOutput("<a href='donators.php?op=add2&id={$row['acctid']}&amt=$amt&reason=" . rawurlencode($reason) . "&txnid=$txnid'>");
        }
        $output->outputNotl("%s (%s/%s)", $row['name'], $row['donation'], $row['donationspent']);
        $output->rawOutput("</a>");
        $output->outputNotl("`n");
        if ($ret != "") {
            Nav::add("", "donators.php?op=add2&id={$row['acctid']}&amt=$amt&ret=" . rawurlencode($ret) . "&reason=" . rawurlencode($reason));
        } else {
            Nav::add("", "donators.php?op=add2&id={$row['acctid']}&amt=$amt&reason=" . rawurlencode($reason) . "&txnid=$txnid");
        }
    }
}
Footer::pageFooter();
