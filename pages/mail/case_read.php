<?php

declare(strict_types=1);

use Lotgd\Mail;
use Lotgd\PlayerFunctions;
use Lotgd\Sanitize;
use Lotgd\Translator;

/**
 * Build a navigation link for adjacent messages.
 */
function buildNavigationLink(int $id, string $label): string
{
    $charset = getsetting('charset', 'UTF-8');
    $encodedLabel = htmlentities($label, ENT_COMPAT, $charset);

    if ($id > 0) {
        return "<a href='mail.php?op=read&id=$id' class='motd'>$encodedLabel</a>";
    }

    return $encodedLabel;
}

/**
 * Display a mail message.
 */
function mailRead(): void
{
    global $session;

    // Get message id from request
    $idParam = httpget('id');
    if (!isset($idParam) || !is_numeric($idParam) || (int)$idParam <= 0) {
        output('Invalid message ID: ' . $idParam);
        return;
    }
    $messageId = (int) $idParam;

    // Retrieve the message details
    $message = Mail::getMessage($session['user']['acctid'], $messageId);

    if (! $message) {
        output('The requested message could not be found.');

        return;
    }

    // Translate common action labels
    $replyLabel = Translator::translateInline('Reply');
    $deleteLabel = Translator::translateInline('Delete');
    $forwardLabel = Translator::translateInline('Forward');
    $unreadLabel = Translator::translateInline('Mark Unread');
    $reportLabel = Translator::translateInline('Report to Admin');
    $previousLabel = Translator::translateInline('< Previous');
    $nextLabel = Translator::translateInline('Next >');

    // Prepare report data for admins
    $reportMessage = "Abusive Email Report:\nFrom: {$message['name']}\nSubject: {$message['subject']}\nSent: {$message['sent']}\nID: {$message['messageid']}\nBody:\n{$message['body']}";
    $reportPlayer = (int) $message['msgfrom'];

    // Determine sender status
    $statusImage = '';
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
    } elseif ($message['name'] === '') {
        $message['name'] = Translator::translateInline('`^Deleted User');
    } else {
        $online = (int) PlayerFunctions::isPlayerOnline($message['acctid']);
        $status = $online ? 'online' : 'offline';
        $statusImage = "<img src='images/$status.gif' alt='$status'>";
    }

    // Show NEW marker if message is unread
    if (! $message['seen']) {
        output('`b`#NEW`b`n');
    } else {
        output('`n');
    }

    // IDs for adjacent messages
    $adjacentIds = Mail::adjacentMessageIds($session['user']['acctid'], $messageId);
    $previousId = $adjacentIds['prev'];
    $nextId = $adjacentIds['next'];

    // Message headers
    output('`b`2From:`b `^%s', $message['name']);
    output_notl($statusImage . '`n', true);
    output('`b`2Subject:`b `^%s`n', $message['subject']);
    output('`b`2Sent:`b `^%s`n', $message['sent']);

    // Top controls with navigation
    rawoutput('<table style="width:50%;border:0;cellspacing:10;"><tr>');
    rawoutput("<td><a href='mail.php?op=write&replyto={$message['messageid']}' class='motd'>$replyLabel</a></td>");
    rawoutput("<td><a href='mail.php?op=address&id={$message['messageid']}' class='motd'>$forwardLabel</a></td>");
    rawoutput('<td>' . buildNavigationLink($previousId, $previousLabel) . '</td>');
    rawoutput('<td nowrap="true">' . buildNavigationLink($nextId, $nextLabel) . '</td>');
    rawoutput('</tr></table><br/>');

    // Message body
    output_notl(Sanitize::sanitizeMb(str_replace("\n", '`n', $message['body'])));

    // Mark as read
    Mail::markRead($session['user']['acctid'], $messageId);

    // Bottom action links and navigation
    rawoutput("<table width='50%' border='0' cellpadding='0' cellspacing='5'><tr>");
    rawoutput("<td><a href='mail.php?op=write&replyto={$message['messageid']}' class='motd'>$replyLabel</a></td>");
    rawoutput("<td><a href='mail.php?op=del&id={$message['messageid']}' class='motd'>$deleteLabel</a></td>");
    rawoutput('</tr><tr>');
    rawoutput("<td><a href='mail.php?op=unread&id={$message['messageid']}' class='motd'>$unreadLabel</a></td>");

    if ((int) $message['msgfrom'] !== 0) {
        $escapedProblem = htmlentities($reportMessage, ENT_COMPAT, getsetting('charset', 'UTF-8'));
        rawoutput("<td><form action=\"petition.php\" method='post'><input type='hidden' name='problem' value=\"$escapedProblem\"/><input type='hidden' name='abuse' value=\"yes\"/><input type='hidden' name='abuseplayer' value=\"$reportPlayer\"/><input type='submit' class='motd' value='$reportLabel'/></form></td>");
    } else {
        rawoutput('<td>&nbsp;</td>');
    }

    rawoutput('</tr><tr>');
    rawoutput('<td nowrap="true">' . buildNavigationLink($previousId, $previousLabel) . '</td>');
    rawoutput('<td nowrap="true">' . buildNavigationLink($nextId, $nextLabel) . '</td>');
    rawoutput('</tr></table>');
}

mailRead();
