<?php

declare(strict_types=1);

use Lotgd\Mail;

/**
 * Handle sending of in-game mail.
 *
 * @return void
 */
function MailSend(): void
{
    global $session, $op, $id;

    // Capture and validate input values once
    $to = (string) httppost('to');
    $subject = (string) httppost('subject');
    $body = (string) httppost('body');
    $sendClose = (bool) httppost('sendclose');
    $sendBack = (bool) httppost('sendback');
    $return = (int) httppost('returnto');

    if ($to === '' || $subject === '' || $body === '') {
        output('Missing required fields.`n');

        return;
    }

    $sql = 'SELECT acctid FROM ' . db_prefix('accounts') . " WHERE login='$to'";
    $result = db_query($sql);

    if (db_num_rows($result) <= 0) {
        output('Could not find the recipient, please try again.`n');

        return;
    }

    $row = db_fetch_assoc($result);
    $checkUnread = (bool) getsetting('onlyunreadmails', true);

    if (Mail::isInboxFull($row['acctid'], $checkUnread)) {
        output('`$You cannot send that person mail, their mailbox is full!`0`n`n');

        return;
    }

    $subject = sanitizeSubject($subject);
    $body = sanitizeBody($body);

    Mail::systemMail($row['acctid'], $subject, $body, (int) $session['user']['acctid']);
    invalidatedatacache("mail-{$row['acctid']}");
    output('Your message was sent!`n');

    if ($sendClose) {
        rawoutput("<script language='javascript'>window.close();</script>");
    }

    if ($sendBack) {
        $return = 0;
    }

    if ($return > 0) {
        $op = 'read';
        httpset('op', 'read');
        $id = $return;
        httpset('id', $id);
    } else {
        $op = '';
        httpset('op', '');
    }
}

/**
 * Remove game newline codes from the subject.
 */
function sanitizeSubject(string $subject): string
{
    return str_replace('`n', '', $subject);
}

/**
 * Sanitize the message body.
 */
function sanitizeBody(string $body): string
{
    // Replace game-specific newline markers.
    $body = replaceGameNewlines($body);

    // Normalize Windows and Mac line endings to Unix style.
    $body = normalizeLineEndings($body);

    // Truncate to the configured limit and escape for storage.
    return escapeAndTruncateBody($body);
}

/**
 * Replace `n codes with actual newlines.
 */
function replaceGameNewlines(string $body): string
{
    return str_replace('`n', "\n", $body);
}

/**
 * Normalize line endings to Unix style.
 */
function normalizeLineEndings(string $body): string
{
    $body = str_replace("\r\n", "\n", $body);

    return str_replace("\r", "\n", $body);
}

/**
 * Strip slashes, truncate to limit and escape for storage.
 */
function escapeAndTruncateBody(string $body): string
{
    $limit = (int) getsetting('mailsizelimit', 1024);

    return addslashes(substr(stripslashes($body), 0, $limit));
}

MailSend();

