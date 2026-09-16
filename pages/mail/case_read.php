<?php

declare(strict_types=1);

use Lotgd\Mail;
use Lotgd\PlayerFunctions;
use Lotgd\Sanitize;
use Lotgd\Translator;
use Lotgd\Http;
use Lotgd\Output;
use Lotgd\Forms;
use Lotgd\Security\Escape;

/**
 * The class pair worn by every control in the mail action bars.
 *
 * `button` is the one class every theme defines unqualified, so it lands on an
 * `<a>`, a `<button>` and a `<span>` alike. `mail-nav__link` adds the flex
 * alignment in the themes that define it and is ignored by the three legacy
 * ones that do not -- mail.php's own tab strip has shipped this exact pair to
 * those themes for as long as it has existed, so the degradation is not a
 * guess.
 *
 * It replaces `motd`, which every theme defines only as `a.motd`. That styled
 * the anchors and did nothing whatever for the buttons beside them, which is
 * why Delete, Mark Unread and Report to Admin rendered as bare browser chrome
 * next to styled links.
 */
function mailActionClass(): string
{
    return 'button mail-nav__link';
}

/**
 * A link that looks like the buttons it stands beside.
 *
 * The label is escaped, which it was not before: translated strings come from
 * a table SU_IS_TRANSLATOR writes and are not constants.
 */
function buildActionLink(string $url, string $label): string
{
    return "<a href='" . Escape::html($url) . "' class='" . mailActionClass() . "'>"
        . Escape::html($label) . '</a>';
}

/**
 * Build a navigation link for adjacent messages.
 *
 * With no adjacent message the label still occupies the bar, as a span rather
 * than the bare unwrapped text it used to be: a naked string is not a control,
 * and dropping out of the row moved every button beside it.
 */
function buildNavigationLink(int $id, string $label): string
{
    if ($id > 0) {
        return buildActionLink("mail.php?op=read&id=$id", $label);
    }

    return "<span class='" . mailActionClass() . "' aria-disabled='true'>"
        . Escape::html($label) . '</span>';
}

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

    // Translate common action labels
    $replyLabel = Translator::translateInline('Reply');
    $deleteLabel = Translator::translateInline('Delete');
    $forwardLabel = Translator::translateInline('Forward');
    $unreadLabel = Translator::translateInline('Mark Unread');
    $reportLabel = Translator::translateInline('Report to Admin');
    $previousLabel = Translator::translateInline('< Previous');
    $nextLabel = Translator::translateInline('Next >');
    // Every other delete in the tree asks first -- taunt.php, titleedit.php,
    // masters.php. This one did not.
    $deleteConfirm = Translator::translateInline('Are you sure you wish to delete this message?');

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

    // Top bar: everything that moves you somewhere else. The tables this
    // replaces were invalid in both directions -- one wrote cellspacing into a
    // style attribute, where it is not a property at all, the other used the
    // presentational attributes -- and the lower one was a three-row grid
    // rather than the single row it looked like.
    $output->rawOutput("<div class='mail-nav'>");
    $output->rawOutput(buildNavigationLink($previousId, $previousLabel));
    $output->rawOutput(buildNavigationLink($nextId, $nextLabel));
    $output->rawOutput(buildActionLink("mail.php?op=write&replyto={$message['messageid']}", $replyLabel));
    $output->rawOutput(buildActionLink("mail.php?op=address&id={$message['messageid']}", $forwardLabel));
    $output->rawOutput('</div>');

    // Message body
    $output->outputNotl('%s', Sanitize::sanitizeMb(str_replace("\n", '`n', $message['body'])));

    // Mark as read
    Mail::markRead($session['user']['acctid'], $messageId);

    // Bottom bar: everything that acts on this message, and nothing that
    // merely navigates -- Reply and the two adjacent-message links used to be
    // repeated down here, and Forward appeared only above.
    $actionClass = mailActionClass();
    $output->rawOutput("<div class='mail-nav'>");
    $output->rawOutput(Forms::postButton(
        "mail.php?op=del&id={$message['messageid']}",
        $deleteLabel,
        $deleteConfirm,
        $actionClass
    ));
    $output->rawOutput(Forms::postButton(
        "mail.php?op=unread&id={$message['messageid']}",
        $unreadLabel,
        null,
        $actionClass
    ));

    if ((int) $message['msgfrom'] !== 0) {
        // petition.php only prefills its form from this payload: the branch
        // that writes is the one where abuse is not 'yes'. So this is a
        // navigation carrying a body, which is why it posts. postButton takes
        // the body in $fields and brings a token with it, replacing a
        // hand-built form that had neither.
        $output->rawOutput(Forms::postButton(
            'petition.php',
            $reportLabel,
            null,
            $actionClass,
            null,
            [
                'problem' => $reportMessage,
                'abuse' => 'yes',
                'abuseplayer' => (string) $reportPlayer,
            ]
        ));
    }

    $output->rawOutput('</div>');
}

mailRead();
