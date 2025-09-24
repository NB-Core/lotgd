<?php

declare(strict_types=1);

use Lotgd\Mail;
use Lotgd\PlayerFunctions;
use Lotgd\Sanitize;
use Lotgd\Translator;
use Lotgd\Http;
use Lotgd\Output;
use Lotgd\Settings;

/**
 * Build a navigation link for adjacent messages.
 */
function buildNavigationLink(int $id, string $label): string
{
    $settings = Settings::getInstance();
    $charset = $settings->getSetting('charset', 'UTF-8');
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

    $output = Output::getInstance();
    $settings = Settings::getInstance();

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

    // Top controls with navigation
    $output->rawOutput('<table style="width:50%;border:0;cellspacing:10;"><tr>');
    $output->rawOutput("<td><a href='mail.php?op=write&replyto={$message['messageid']}' class='motd'>$replyLabel</a></td>");
    $output->rawOutput("<td><a href='mail.php?op=address&id={$message['messageid']}' class='motd'>$forwardLabel</a></td>");
    $output->rawOutput('<td>' . buildNavigationLink($previousId, $previousLabel) . '</td>');
    $output->rawOutput('<td nowrap="true">' . buildNavigationLink($nextId, $nextLabel) . '</td>');
    $output->rawOutput('</tr></table><br/>');

    // Message body
    $output->outputNotl('%s', Sanitize::sanitizeMb(str_replace("\n", '`n', $message['body'])));

    // Mark as read
    Mail::markRead($session['user']['acctid'], $messageId);

    // Bottom action links and navigation
    $output->rawOutput("<table width='50%' border='0' cellpadding='0' cellspacing='5'><tr>");
    $output->rawOutput("<td><a href='mail.php?op=write&replyto={$message['messageid']}' class='motd'>$replyLabel</a></td>");
    $output->rawOutput("<td><a href='mail.php?op=del&id={$message['messageid']}' class='motd'>$deleteLabel</a></td>");
    $output->rawOutput('</tr><tr>');
    $output->rawOutput("<td><a href='mail.php?op=unread&id={$message['messageid']}' class='motd'>$unreadLabel</a></td>");

    if ((int) $message['msgfrom'] !== 0) {
        $escapedProblem = htmlentities($reportMessage, ENT_COMPAT, $settings->getSetting('charset', 'UTF-8'));
        $output->rawOutput("<td><form action=\"petition.php\" method='post'><input type='hidden' name='problem' value=\"$escapedProblem\"/><input type='hidden' name='abuse' value=\"yes\"/><input type='hidden' name='abuseplayer' value=\"$reportPlayer\"/><input type='submit' class='motd' value='$reportLabel'/></form></td>");
    } else {
        $output->rawOutput('<td>&nbsp;</td>');
    }

    $output->rawOutput('</tr><tr>');
    $output->rawOutput('<td nowrap="true">' . buildNavigationLink($previousId, $previousLabel) . '</td>');
    $output->rawOutput('<td nowrap="true">' . buildNavigationLink($nextId, $nextLabel) . '</td>');
    $output->rawOutput('</tr></table>');
}

mailRead();
