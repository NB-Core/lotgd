<?php

use Lotgd\Http;
use Lotgd\Mail;
use Lotgd\Modules\HookHandler;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\Translator;

// translator ready
// addnews ready
// mail ready
define("OVERRIDE_FORCED_NAV", true);
require_once __DIR__ . "/common.php";

Translator::getInstance()->setSchema("mail");
$output = Output::getInstance();
$args   = HookHandler::hook("header-mail", ["done" => 0]);


$op = Http::get('op');
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

$output->rawOutput("<table width='50%' border='0' cellpadding='0' cellspacing='2'>");
$output->rawOutput("<tr>");
$count_mailfunctions = count($mailfunctions);
for ($i = 0; $i < $count_mailfunctions; ++$i) {
    if (is_array($mailfunctions[$i])) {
        if (count($mailfunctions[$i]) == 2) {
            $page = $mailfunctions[$i][0];
            $name = $mailfunctions[$i][1]; // already translated
            $output->rawOutput("<td><a href='$page' class='motd'>$name</a></td>");
            // No need for addnav since mail function pages are (or should be) outside the page nav system.
        }
    }
}
$output->rawOutput("</tr></table>");
$output->outputNotl("`n`n");
switch (Http::get('even')) {
    case "mailsent":
        $output->output("`vYour message was sent!`n");
        break;
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
