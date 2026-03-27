<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Payment\IpnPaymentProcessor;
use Lotgd\Payment\IpnStatus;
use Lotgd\Translator;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;
use Lotgd\Settings;

// mail ready
// addnews ready
// translator ready
ob_start();
$payment_errors = '';
set_error_handler('payment_error');
define("ALLOW_ANONYMOUS", true);

require_once __DIR__ . "/common.php";

/** @var Settings $settings */
$settings = Settings::getInstance();

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
$header  = "POST /cgi-bin/webscr HTTP/1.1\r\n";
$header .= "Content-Type: application/x-www-form-urlencoded\r\n";
$header .= "Content-Length: " . strlen($req) . "\r\n";
$header .= "Host: www.paypal.com\r\n";
$header .= "Connection: close\r\n\r\n";

// Open a socket for the acknowledgement request
$fp = fsockopen('ssl://www.paypal.com', 443, $errno, $errstr, 30);

// assign posted variables to local variables
$item_name = Http::post('item_name');
$item_number = Http::post('item_number');
$payment_status = Http::post('payment_status');
$payment_amount = Http::post('mc_gross');
$payment_currency = Http::post('mc_currency');
$txn_id = Http::post('txn_id');
$receiver_email = Http::post('business');
$payer_email = Http::post('payer_email');
$payment_fee = Http::post('mc_fee');
$txn_type = Http::post('txn_type');

$response = '';
if (! $fp) {
    payment_error(E_ERROR, "Unable to open socket to verify payment", __FILE__, __LINE__);
} else {
    fputs($fp, $header . $req);
    while (! feof($fp)) {
        $res = fgets($fp, 1024);
        $response .= $res;

        if (strcmp(trim($res), "VERIFIED") == 0) {
            $normalizedStatus = IpnStatus::normalize((string) $payment_status, (float) $payment_fee, (string) $txn_type);
            if ($normalizedStatus['accepted']) {
                $payment_fee = (string) $normalizedStatus['paymentFee'];
                $txn_type = $normalizedStatus['txnType'];

                if (
                    ($receiver_email != "logd@mightye.org")
                    && ($receiver_email != $settings->getSetting('paypalemail', ''))
                ) {
                    $emsg = "This payment isn't to me(" . $settings->getSetting('paypalemail', '') . ")!  It's to $receiver_email.\n";
                    payment_error(E_WARNING, $emsg, __FILE__, __LINE__);
                }

                writelog($response);
            } else {
                HookHandler::hook("donation-error", $post);
                payment_error(E_ERROR, "Payment Status isn't 'Completed' it's '$payment_status'", __FILE__, __LINE__);
            }
        } elseif (strcmp(trim($res), "INVALID") == 0) {
            payment_error(E_ERROR, "Payment Status is 'INVALID'!\n\nPOST data:`n" . serialize($_POST), __FILE__, __LINE__);
        }
    }
    fclose($fp);
}

/**
 * Persist and process a verified payment callback.
 */
function writelog(string $response): void
{
    global $settings;
    global $post;
    global $item_number, $payment_amount, $txn_id, $payment_fee, $txn_type;

    $processor = new IpnPaymentProcessor(
        Database::getDoctrineConnection(),
        Database::prefix('accounts'),
        Database::prefix('paylog')
    );

    $result = $processor->processVerifiedPayment(
        $post,
        [
            'itemNumber' => (string) $item_number,
            'response' => $response,
            'txnId' => (string) $txn_id,
            'paymentAmount' => (string) $payment_amount,
            'paymentFee' => (string) $payment_fee,
            'txnType' => (string) $txn_type,
            'processDate' => date("Y-m-d H:i:s"),
            'pointsPerCurrencyUnit' => (float) $settings->getSetting('dpointspercurrencyunit', 100),
        ],
        static fn (array $hookData): array => HookHandler::hook("donation_adjustments", $hookData)
    );

    foreach ($result->warnings as $warning) {
        payment_error(E_WARNING, $warning, __FILE__, __LINE__);
    }
    foreach ($result->errors as $error) {
        payment_error(E_ERROR, $error, __FILE__, __LINE__);
    }

    if ($result->credited) {
        debuglog("Received donator points for donating -- Credited Automatically", false, $result->accountId, "donation", $result->creditedPoints, false);
        foreach ($result->debugMessages as $message) {
            debuglog($message, false, $result->accountId, "donation", 0, false);
        }

        HookHandler::hook("donation", [
            "id" => $result->accountId,
            "amt" => $result->donationAmount * $settings->getSetting('dpointspercurrencyunit', 100),
            "manual" => false,
        ]);
    }

    if ($result->paylogInserted) {
        HookHandler::hook("donation-processed", $post);
    }
}

function payment_error($errno, $errstr, $errfile, $errline)
{
    global $payment_errors;
    if (!is_int($errno) || (is_int($errno) && ($errno & error_reporting()))) {
        $payment_errors .= "Error $errno: $errstr in $errfile on $errline\n";
    }
}

$adminEmail = $settings->getSetting('gameadminemail', 'postmaster@localhost.com');
if ($payment_errors > "") {
    $subj = translate_mail("Payment Error", 0);
    $sanitizedInfo = sprintf("Txn ID: %s\nStatus: %s\nAmount: %0.2f %s\nPayer: %s\nReceiver: %s", $txn_id, $payment_status, (float)$payment_amount, $payment_currency, $payer_email, $receiver_email);
    error_log($payment_errors . "\n" . $sanitizedInfo);
    mail($adminEmail, $subj, $payment_errors . "\n" . $sanitizedInfo, "From: " . $settings->getSetting('gameadminemail', 'postmaster@localhost.com'));
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
            "From: " . $settings->getSetting('gameadminemail', 'postmaster@localhost.com')
        );
    }
}
ob_end_clean();
