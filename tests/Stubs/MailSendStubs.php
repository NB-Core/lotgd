<?php

declare(strict_types=1);

use Lotgd\Mail;

if (! function_exists('httpset')) {
    function httpset(string $name, $value, bool $persistent = false): void
    {
        $_GET[$name] = $value;
    }
}

if (! function_exists('mailSend')) {
    function mailSend(): void
    {
        global $session, $mail_send_accounts;
        $login = (string) httppost('to');
        $subject = sanitizeSubject((string) httppost('subject'));
        $body = sanitizeBody((string) httppost('body'));
        $acctid = $mail_send_accounts[$login] ?? 0;
        if ($acctid) {
            Mail::systemMail($acctid, $subject, $body, (int) $session['user']['acctid']);
        }
    }
}

function sanitizeSubject(string $subject): string
{
    return str_replace('`n', '', $subject);
}

function sanitizeBody(string $body): string
{
    $body = replaceGameNewlines($body);
    $body = normalizeLineEndings($body);
    return escapeAndTruncateBody($body);
}

function replaceGameNewlines(string $body): string
{
    return str_replace('`n', "\n", $body);
}

function normalizeLineEndings(string $body): string
{
    $body = str_replace("\r\n", "\n", $body);
    return str_replace("\r", "\n", $body);
}

function escapeAndTruncateBody(string $body): string
{
    $limit = (int) getsetting('mailsizelimit', 1024);
    return addslashes(substr(stripslashes($body), 0, $limit));
}
