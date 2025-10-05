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

if (! function_exists('sanitizeSubject')) {
    function sanitizeSubject(string $subject): string
    {
        return str_replace('`n', '', $subject);
    }
}

if (! function_exists('sanitizeBody')) {
    function sanitizeBody(string $body): string
    {
        $body = replaceGameNewlines($body);
        $body = normalizeLineEndings($body);
        return escapeAndTruncateBody($body);
    }
}

if (! function_exists('replaceGameNewlines')) {
    function replaceGameNewlines(string $body): string
    {
        return str_replace('`n', "\n", $body);
    }
}

if (! function_exists('normalizeLineEndings')) {
    function normalizeLineEndings(string $body): string
    {
        $body = str_replace("\r\n", "\n", $body);
        return str_replace("\r", "\n", $body);
    }
}

if (! function_exists('escapeAndTruncateBody')) {
    function escapeAndTruncateBody(string $body): string
    {
        $limit = (int) getsetting('mailsizelimit', 1024);
        return addslashes(substr(stripslashes($body), 0, $limit));
    }
}
