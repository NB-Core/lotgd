<?php

declare(strict_types=1);

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Lotgd\Mail;
use Lotgd\Http;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\DataCache;

/**
 * Handle sending of in-game mail.
 *
 * @return void
 */
function mailSend(): void
{
    global $session, $op, $id;

    $output = Output::getInstance();
    $settings = Settings::getInstance();

    // Capture and validate input values once
    $to = (string) Http::post('to');
    $subject = (string) Http::post('subject');
    $body = (string) Http::post('body');
    $sendClose = Http::postIsset('sendclose');
    $sendBack = Http::postIsset('sendback');
    $return = (int) Http::post('returnto');

    if ($to === '' || $body === '') {
        $output->output('Missing required fields.`n');
        return;
    }

    $conn = Database::getDoctrineConnection();
    $table = Database::prefix('accounts');

    $recipient = $conn->fetchAssociative(
        "SELECT acctid FROM $table WHERE login = :login",
        ['login' => $to],
        ['login' => ParameterType::STRING]
    );

    if ($recipient === false || ! array_key_exists('acctid', $recipient)) {
        $output->output('Could not find the recipient, please try again.`n');

        return;
    }

    $acctid = (int) $recipient['acctid'];
    $checkUnread = (bool) $settings->getSetting('onlyunreadmails', true);

    if (Mail::isInboxFull($acctid, $checkUnread)) {
        $output->output('`$You cannot send that person mail, their mailbox is full!`0`n`n');

        return;
    }

    $subject = sanitizeSubject($subject);
    $body = sanitizeBody($body);

    Mail::systemMail($acctid, $subject, $body, (int) $session['user']['acctid']);
    DataCache::getInstance()->invalidatedatacache("mail-{$acctid}");
    $output->output('Your message was sent!`n');

    if ($sendClose) {
        $output->rawOutput("<script language='javascript'>window.close();</script>");
    }

    if ($sendBack) {
        $return = 0;
    }

    if ($return > 0) {
        $op = 'read';
        Http::set('op', 'read');
        $id = $return;
        Http::set('id', (string) $id, true);
    } else {
        $op = '';
        Http::set('op', '');
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
    $settings = Settings::getInstance();
    $limit = (int) $settings->getSetting('mailsizelimit', 1024);
    $charset = (string) $settings->getSetting('charset', 'UTF-8');

    if (function_exists('mb_substr')) {
        $body = mb_substr($body, 0, $limit, $charset);
    } else {
        $body = substr($body, 0, $limit);
    }

    return $body;
}

mailSend();
