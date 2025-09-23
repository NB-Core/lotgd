<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\Http;
use Lotgd\Page\Footer;
use Lotgd\Modules\HookHandler;

// mail ready
// addnews ready
// translator ready
ob_start();
$payment_errors = '';
set_error_handler('payment_error');
define("ALLOW_ANONYMOUS", true);

require_once __DIR__ . "/common.php";

Translator::getInstance()->setSchema("payment");

// Send an empty HTTP 200 OK response to acknowledge receipt of the notification
header('HTTP/1.1 200 OK');


// read the post from PayPal system and add 'cmd'
$req = 'cmd=_notify-validate';

$post = Http::allPost();
reset($post);
foreach ($post as $key => $value) {
    $value = urlencode(stripslashes($value));
    $req .= "&$key=$value";
}

// Set up the acknowledgement request headers
$header  = "POST /cgi-bin/webscr HTTP/1.1\r\n";                    // HTTP POST request
$header .= "Content-Type: application/x-www-form-urlencoded\r\n";
$header .= "Content-Length: " . strlen($req) . "\r\n";
$header .= "Host: www.paypal.com\r\n";
$header .= "Connection: close\r\n\r\n";

// Open a socket for the acknowledgement request

$fp = fsockopen('ssl://www.paypal.com', 443, $errno, $errstr, 30);
//$fp = fsockopen ('www.paypal.com', 80, $errno, $errstr, 30);
//$fp = fsockopen ('ssl://www.sandbox.paypal.com', 443, $errno, $errstr, 30);

// assign posted variables to local variables
$item_name = Http::post('item_name');
$item_number = Http::post('item_number');
$payment_status = Http::post('payment_status');
$payment_amount = Http::post('mc_gross');
$payment_currency = Http::post('mc_currency');
$txn_id = Http::post('txn_id');
$receiver_email = Http::post('business'); //formerly receiver_email, but with using multiple emails for paypal it's gross
$payer_email = Http::post('payer_email');
$payment_fee = Http::post('mc_fee');

$response = '';
if (!$fp) {
    // HTTP ERROR
    payment_error(E_ERROR, "Unable to open socket to verify payment", __FILE__, __LINE__);
} else {
    fputs($fp, $header . $req);
    while (!feof($fp)) {
        $res = fgets($fp, 1024);
        $response .= $res;

        if (strcmp(trim($res), "VERIFIED") == 0) {
            // check the payment_status is Completed
            // check that txn_id has not been previously processed
            // check that receiver_email is your Primary PayPal email
            // check that payment_amount/payment_currency are correct
            // process payment
            if ($payment_status == "Completed" || $payment_status == 'Refunded') {
                if ($payment_status == 'Refunded') {
                    //sanitize the data to look like a completed transaction
                    $payment_amount = $mc_gross;
                    $payment_fee = 0;
                    $txn_type = 'refund';
                }
                $sql = "SELECT * FROM " . Database::prefix("paylog") . " WHERE txnid='{$txn_id}'";
                $result = Database::query($sql);
                if (Database::numRows($result) == 1) {
                    $emsg .= "Already logged this transaction ID ($txn_id)\n";
                    payment_error(E_ERROR, $emsg, __FILE__, __LINE__);
                }
                if (
                    ($receiver_email != "logd@mightye.org") &&
                    ($receiver_email != getsetting("paypalemail", ""))
                ) {
                    $emsg = "This payment isn't to me(" . getsetting("paypalemail", "") . ")!  It's to $receiver_email.\n";
                    payment_error(E_WARNING, $emsg, __FILE__, __LINE__);
                }
                writelog($response);
            } else {
                HookHandler::hook("donation-error", $post);
                payment_error(E_ERROR, "Payment Status isn't 'Completed' it's '$payment_status'", __FILE__, __LINE__);
            }
        } elseif (strcmp(trim($res), "INVALID") == 0) {
            // log for manual investigation
            payment_error(E_ERROR, "Payment Status is 'INVALID'!\n\nPOST data:`n" . serialize($_POST), __FILE__, __LINE__);
        }
    }
    fclose($fp);
}

function writelog($response)
{
    global $post;
    global $item_name, $item_number, $payment_status, $payment_amount;
    global $payment_currency, $txn_id, $receiver_email, $payer_email;
    global $payment_fee,$txn_type;
    $match = array();
    preg_match("'([^:]*):([^/])*'", $item_number, $match);
    if (isset($match[1]) && $match[1] > "") {
        $sql = "SELECT acctid FROM " . Database::prefix("accounts") . " WHERE login='{$match[1]}'";
        $result = Database::query($sql);
        $row = Database::fetchAssoc($result);
        $acctid = $row['acctid'];
        if ($acctid > 0) {
            $donation = $payment_amount;
            // if it's a reversal, it'll only post back to us the amount
            // we received back, with out counting the fees, which we
            // receive under a different transaction, but get no
            // notification for.
            if ($txn_type == "reversal") {
                $donation -= $payment_fee;
            }

            $hookresult = HookHandler::hook("donation_adjustments", array("points" => $donation * getsetting('dpointspercurrencyunit', 100),"amount" => $donation,"acctid" => $acctid,"messages" => array()));
            //updated to make a setting here for each Dollar, Euro, Shekel
            $hookresult['points'] = round($hookresult['points']);

            $sql = "UPDATE " . Database::prefix("accounts") . " SET donation = donation + '{$hookresult['points']}' WHERE acctid=$acctid";

            $result = Database::query($sql);
            debuglog("Received donator points for donating -- Credited Automatically", false, $acctid, "donation", $hookresult['points'], false);
            if (!is_array($hookresult['messages'])) {
                $hookresult['messages'] = array($hookresult['messages']);
            }
            foreach ($hookresult['messages'] as $id => $message) {
                debuglog($message, false, $acctid, "donation", 0, false);
            }
            if (Database::affectedRows() > 0) {
                $processed = 1;
            }
        }
    } else {
        $match[1] = "";
    }
    if ($match[1] > "" && $acctid > 0) {
        HookHandler::hook("donation", array("id" => $acctid, "amt" => $donation * getsetting('dpointspercurrencyunit', 100), "manual" => false));
    }
    $sql = "
                INSERT INTO " . Database::prefix("paylog") . " (
			info,
			response,
			txnid,
			amount,
			name,
			acctid,
			processed,
			filed,
			txfee,
			processdate
		)VALUES (
			'" . addslashes(serialize($post)) . "',
			'" . addslashes($response) . "',
			'$txn_id',
			'$payment_amount',
			'{$match[1]}',
			" . (int)(isset($acctid) ? $acctid : 0) . ",
			" . (int)(isset($processed) ? $processed : 0) . ",
			0,
			'$payment_fee',
			'" . date("Y-m-d H:i:s") . "'
		)";
    if (isset($acctid)) {
        debuglog($sql, false, $acctid, "donation", 0, false);
    }
    $result = Database::query($sql);
    HookHandler::hook("donation-processed", $post);
    $err = Database::error();
    if ($err) {
        payment_error(E_ERROR, "SQL: $sql\nERR: $err", __FILE__, __LINE__);
    }
}

function payment_error($errno, $errstr, $errfile, $errline)
{
    global $payment_errors;
    if (!is_int($errno) || (is_int($errno) && ($errno & error_reporting()))) {
        $payment_errors .= "Error $errno: $errstr in $errfile on $errline\n";
    }
}

$adminEmail = getsetting("gameadminemail", "postmaster@localhost.com");
if ($payment_errors > "") {
    $subj = translate_mail("Payment Error", 0);
    // $payment_errors not translated
        $sanitizedInfo = sprintf("Txn ID: %s\nStatus: %s\nAmount: %0.2f %s\nPayer: %s\nReceiver: %s", $txn_id, $payment_status, (float)$payment_amount, $payment_currency, $payer_email, $receiver_email);
        error_log($payment_errors . "\n" . $sanitizedInfo);
        mail($adminEmail, $subj, $payment_errors . "\n" . $sanitizedInfo, "From: " . getsetting("gameadminemail", "postmaster@localhost.com"));
}
$output = ob_get_contents();
if (!empty($output)) {
        error_log("Unexpected payment output: " . $output);
        $sanitizedInfo = sprintf(
            "Txn ID: %s\nStatus: %s\nAmount: %s %s\nPayer: %s\nReceiver: %s",
            $txn_id,
            $payment_status,
            $payment_amount,
            $payment_currency,
            $payer_email,
            $receiver_email
        );
    if ($adminEmail == "") {
            error_log("Admin email not configured; payment issue details: " . $sanitizedInfo);
    } else {
            mail(
                $adminEmail,
                "Serious LoGD Payment Problems on {$_SERVER['HTTP_HOST']}",
                $sanitizedInfo,
                "From: " . getsetting("gameadminemail", "postmaster@localhost.com")
            );
    }
}
ob_end_clean();
