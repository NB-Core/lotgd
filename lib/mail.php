<?php

/**
 * Legacy wrappers for mail handling functions using the Lotgd\Mail namespace.
 */

use Lotgd\Mail;
use Lotgd\EmailValidator;

function mail_delete_message(int $userId, int $messageId): void
{
    Mail::deleteMessage($userId, $messageId);
}

function mail_delete_messages(int $userId, array $messageIds): void
{
    Mail::deleteMessages($userId, $messageIds);
}

function mail_mark_unread(int $userId, int $messageId): void
{
    Mail::markUnread($userId, $messageId);
}

function mail_inbox_count(int $userId, bool $onlyUnread = false): int
{
    return Mail::inboxCount($userId, $onlyUnread);
}

function mail_is_inbox_full(int $userId, bool $onlyUnread = false): bool
{
    return Mail::isInboxFull($userId, $onlyUnread);
}

function send_email(array $to, string $body, string $subject, array $from, $cc = false, string $contenttype = 'text/plain')
{
    return Mail::send($to, $body, $subject, $from, $cc, $contenttype);
}

function systemmail(int $to, string $subject, string $body, int $from = 0, bool $noemail = false)
{
    Mail::systemMail($to, $subject, $body, $from, $noemail);
}

function is_email(string $email): bool
{
    return EmailValidator::isValid($email);
}
