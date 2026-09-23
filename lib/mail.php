<?php

/**
 * Legacy wrappers for mail handling functions using the Lotgd\Mail namespace.
 *
 * systemmail(), send_email() and is_email() each have their own 1.x file, and
 * modules load those by name -- require_once("lib/systemmail.php") is the
 * idiom. This file used to declare the three a second time, unguarded, so any
 * request that loaded it alongside one of them died with "Cannot redeclare
 * function", in either order.
 *
 * It includes them instead of copying them. A function_exists() guard would
 * have stopped the fatal and left something worse: whichever file loaded first
 * would decide the signature, and the copies here were stricter than the
 * originals. systemmail() here took string $subject and string $body, while
 * Mail::systemMail() and the 1.x wrapper both accept the translation array a
 * module passes -- so with this file first, that call became a TypeError. The
 * originals are the compatibility contract, and now they are the only
 * declarations.
 */

use Lotgd\Mail;

require_once __DIR__ . '/sendmail.php';
require_once __DIR__ . '/systemmail.php';
require_once __DIR__ . '/is_email.php';

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
