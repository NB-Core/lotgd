<?php

declare(strict_types=1);

use Lotgd\Http;
use Lotgd\Mail;
use Lotgd\Modules\HookHandler;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\Translator;
use Lotgd\Forms;

// translator ready
// addnews ready
// mail ready
define("OVERRIDE_FORCED_NAV", true);
require_once __DIR__ . "/common.php";

Translator::getInstance()->setSchema("mail");
$output = Output::getInstance();
$args   = HookHandler::hook("header-mail", ["done" => 0]);


$op = Http::get('op');

// The core's own operations, and only those. A POST that does not carry this
// page's form token is treated as if nothing had been sent. An $op this page
// does not implement belongs to a module -- modules render into these pages
// through hooks and may post forms of their own, and an old one cannot carry a
// token it has never heard of -- so it passes through untouched.
//
// Here rather than in each branch: a delete keys off $op with its id in the
// query string, so blanking the body alone would not stop it, and this list is
// the page's inventory of what changes state.
//
// The rejection is remembered rather than only logged, because the player has
// to be told something. Blanking $op drops the request back onto the inbox,
// which on its own is indistinguishable from having pressed nothing: a message
// the player spent five minutes writing simply does not arrive, and the page
// that swallowed it looks exactly as it did before. The success path has said
// "Your message was sent!" all along; this is the other half of that sentence.
//
// What the player is not told is findable by the operator: the guard records
// every refusal itself, in the game log's `security` category and in PHP's
// error log, naming this page, the operation and the scope. This page writes
// no log line of its own -- a second one would put the same event in the log
// twice.
$rejectedUnverified = Forms::isUnverifiedCoreOp($op, ['del', 'process', 'send', 'unread']);
if ($rejectedUnverified) {
    http_response_code(400);
    $op = '';
    $_POST = [];
}

$id = (int) Http::get('id');
if ($op == "del" && !$args['done']) {
        Mail::deleteMessage($session['user']['acctid'], $id);
        header("Location: mail.php");
        exit();
} elseif ($op == "process" && !$args['done']) {
        $msg = Http::post('msg');
    if (!is_array($msg) || count($msg) < 1) {
            $session['message'] = "`n`n`\$`bYou cannot delete zero messages!  What does this mean?  You pressed \"Delete Checked\" but there are no messages checked!  What sort of world is this that people press buttons that have no meaning?!?`b`0";
            header("Location: mail.php");
            exit();
    } else {
            Mail::deleteMessages($session['user']['acctid'], $msg);
            header("Location: mail.php");
            exit();
    }
} elseif ($op == "unread" && !$args['done']) {
        Mail::markUnread($session['user']['acctid'], $id);
        header("Location: mail.php");
        exit();
}

Header::popupHeader("Ye Olde Poste Office");
$inbox = Translator::translateInline("Inbox");
$write = Translator::translateInline("Write");

// Build the initial args array
$args = [];
array_push($args, ["mail.php", $inbox]);
array_push($args, ["mail.php?op=address", $write]);
// to use this hook,
// just call array_push($args, array("pagename", "functionname"));,
// where "pagename" is the name of the page to forward the user to,
// and "functionname" is the name of the mail function to add
$mailfunctions = HookHandler::hook("mailfunctions", $args);

// Rendered by the same helper as the bars on the read view rather than by a
// loop of its own. The labels reach here from the mailfunctions hook, so they
// are module-supplied text and were going into the markup unescaped -- but the
// first attempt at fixing that, a direct Forms::linkButton() call, swapped one
// defect for a worse one: this file is strict_types, linkButton takes strings,
// and the pair is only checked for its length. A module returning
// ["mail.php", 5] used to render (interpolation stringifies anything) and would
// then have raised a TypeError, taking the whole mail page with it. Measured:
// in strict mode every type but string throws.
//
// actionBar() already skips an entry it cannot render, and that guard is
// tested, so routing through it fixes the escaping without inventing a second
// copy of the check. Reported by Copilot.
$actions = [];
foreach ($mailfunctions as $mailfunction) {
    // isset() on both keys rather than count() === 2, which checks the wrong
    // thing: an associative pair passes it and then indexing [0] and [1] warns
    // twice. Worse for a list starting at 1, where the URL lands in the label's
    // position. Neither is new -- the interpolation this replaced warned
    // identically -- but hardening hook consumption is what this change is for.
    // Measured, not assumed. Reported by Copilot.
    if (
        !is_array($mailfunction)
        || !isset($mailfunction[0], $mailfunction[1])
        || !is_string($mailfunction[0])
        || !is_string($mailfunction[1])
    ) {
        continue;
    }

    // No need for addnav since mail function pages are (or should be) outside the page nav system.
    $actions[] = ['kind' => 'link', 'url' => $mailfunction[0], 'label' => $mailfunction[1]];
}
$output->rawOutput(Forms::actionBar($actions));
$output->outputNotl("`n`n");
switch (Http::get('even')) {
    case "mailsent":
        $output->output("`vYour message was sent!`n");
        break;
}

// Here, beside the success message, because this is the same sentence with the
// other answer, and a player who has learned where the one appears finds the
// other without looking. Deliberately one line and deliberately vague about
// which of the three states the token was in: that distinction is the
// operator's business and belongs in a log, not in front of the player.
if ($rejectedUnverified) {
    $output->output(
        '`$Your request could not be carried out: the security token of the form was missing or '
        . 'no longer valid. Please try again.`0`n'
    );
}

if ($op == "send") {
        //needs to be handled first.
        require __DIR__ . "/pages/mail/case_send.php";
}

switch ($op) {
    case "read":
    case "address":
    case "write":
        require __DIR__ . "/pages/mail/case_" . $op . ".php";
        break;
    default:
        require __DIR__ . "/pages/mail/case_default.php";
        break;
}
Footer::popupFooter();
