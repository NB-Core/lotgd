<?php

declare(strict_types=1);

use Lotgd\Mail;
use Lotgd\PlayerFunctions;
use Lotgd\Sanitize;
use Lotgd\Translator;
use Lotgd\Http;
use Lotgd\Output;
use Lotgd\Forms;
use Lotgd\Mail\ReadActions;

/**
 * Display a mail message.
 */
function mailRead(): void
{
    global $session;

    $output = Output::getInstance();

    // Get message id from request
    $idParam = Http::get('id');
    if (!isset($idParam) || !is_numeric($idParam) || (int)$idParam <= 0) {
        $output->output('Invalid message ID: %s', (string) $idParam);
        return;
    }
    $messageId = (int) $idParam;

    // Retrieve the message details
    $message = Mail::getMessage($session['user']['acctid'], $messageId);

    if (! $message) {
        $output->output('The requested message could not be found.');

        return;
    }


    // Determine sender status
    $statusImage = '';
    $senderIdRaw = $message['acctid'] ?? null;
    $senderId = null;

    if (is_int($senderIdRaw)) {
        $senderId = $senderIdRaw;
    } elseif (is_string($senderIdRaw) && ctype_digit($senderIdRaw)) {
        $senderId = (int) $senderIdRaw;
    }

    if ((int) $message['msgfrom'] === 0) {
        $message['name'] = Translator::translateInline('`i`^System`0`i');

        // Translate subject if needed
        $subject = \Lotgd\Serialization::safeUnserialize($message['subject']);
        if ($subject !== false && is_array($subject)) {
            $message['subject'] = Translator::sprintfTranslate(...$subject);
        } else {
            $message['subject'] = $message['subject'];
        }

        // Translate body if needed
        $body = \Lotgd\Serialization::safeUnserialize($message['body']);
        if ($body !== false && is_array($body)) {
            $message['body'] = Translator::sprintfTranslate(...$body);
        } else {
            $message['body'] = $message['body'];
        }
    } else {
        $hasSenderRecord = $senderId !== null && $senderId > 0 && ! empty($message['name']);

        if (! $hasSenderRecord) {
            $message['name'] = Translator::translateInline('`^Deleted User');
        } else {
            $online = PlayerFunctions::isPlayerOnline($senderId);
            $status = $online ? 'online' : 'offline';
            $statusImage = "<img src='images/$status.gif' alt='$status'>";
        }
    }

    // Show NEW marker if message is unread
    if (! $message['seen']) {
        $output->output('`b`#NEW`b`n');
    } else {
        $output->output('`n');
    }

    // IDs for adjacent messages
    $adjacentIds = Mail::adjacentMessageIds($session['user']['acctid'], $messageId);
    $previousId = $adjacentIds['prev'];
    $nextId = $adjacentIds['next'];

    // Message headers
    $output->output('`b`2From:`b `^%s', $message['name']);
    $output->outputNotl('%s', ($statusImage ?? '') . '`n', true);
    $output->output('`b`2Subject:`b `^%s`n', $message['subject']);
    $output->output('`b`2Sent:`b `^%s`n', $message['sent']);

    // Both bars are described rather than assembled: see Lotgd\Mail\ReadActions
    // for why that is a class and not a pair of functions here.
    $output->rawOutput(Forms::actionBar(ReadActions::navigation($message, $previousId, $nextId)));

    // The break either side of the body is what the tables this replaced used
    // to provide: the top one ended in `</table><br/>`, and the bottom one was
    // a `<table cellspacing='5'>`, whose spacing stood above its first row.
    // Both went with the tables, and the controls ended up flat against the
    // message text. It belongs here rather than in the stylesheets: the two
    // container classes are worn elsewhere too -- `.action-bar` by the control
    // cell of ten admin lists, where a top margin would push it out of line
    // with the text in the cell beside it, and `.mail-nav` by the notification
    // strip in every Twig theme's page header -- so a margin on either would
    // move things nobody asked to have moved. This page builds its vertical
    // spacing from the same newline code everywhere else, and a theme that has
    // not taken the action-row CSS at all still gets the break.
    $output->outputNotl('`n');

    // Message body
    $output->outputNotl('%s', Sanitize::sanitizeMb(str_replace("\n", '`n', $message['body'])));

    // Mark as read
    Mail::markRead($session['user']['acctid'], $messageId);

    // The other half of the pair above; this is the one that was reported.
    $output->outputNotl('`n');
    $output->rawOutput(Forms::actionBar(ReadActions::actions($message)));
}

mailRead();
