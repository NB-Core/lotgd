<?php

declare(strict_types=1);

/**
 * Placeholder class for future mail handling refactoring.
 */

namespace Lotgd;

use Lotgd\Censor;
use Lotgd\DataCache;
use Lotgd\MySQL\Database;
use Lotgd\Sanitize;
use Lotgd\Settings;
use Lotgd\Translator;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

class Mail
{
    private static ?Settings $settings = null;

    private static function getSettings(): Settings
    {
        if (! self::$settings instanceof Settings) {
            self::$settings = Settings::getInstance();
        }

        return self::$settings;
    }

    /**
     * Send a system generated mail to a user.
     */
    public static function systemMail(int $to, string|array $subject, string|array $body, int $from = 0, bool $noemail = false): void
    {
        if (is_array($subject)) {
            $subject = Translator::sprintfTranslate(...$subject);
        }

        if (is_array($body)) {
            $body = Translator::sprintfTranslate(...$body);
        }

        $sql = 'SELECT prefs,emailaddress FROM ' . Database::prefix('accounts') . " WHERE acctid='$to'";
        $result = Database::query($sql);
        $row = Database::fetchAssoc($result);
        Database::freeResult($result);
        $prefs = isset($row['prefs']) ? @unserialize($row['prefs']) : [];
        $subject = SafeEscape::escape($subject);
        if ($from === 0) {
            $body = SafeEscape::escape($body);
        } else {
            $subject = str_replace(["\n", '`n'], '', $subject);
            $body = SafeEscape::escape($body);
            if (!(isset($prefs['dirtyemail']) && $prefs['dirtyemail'])) {
                $subject = Censor::soap($subject, false, true);
                $body = Censor::soap($body, false, true);
            }
        }

        $settings = self::getSettings();
        $limit = (int) $settings->getSetting('mailsizelimit', 1024);
        $charset = $settings->getSetting('charset', 'UTF-8');
        $body = stripslashes($body);

        if (extension_loaded('mbstring')) {
            $body = mb_substr($body, 0, $limit, $charset);
        } else {
            $body = substr($body, 0, $limit);
        }

        $body = addslashes($body);
        $sql = 'INSERT INTO ' . Database::prefix('mail') . " (msgfrom,msgto,subject,body,sent) VALUES ('" . (int)$from . "','" . (int)$to . "','$subject','$body','" . date('Y-m-d H:i:s') . "')";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("mail-$to");
        $email = false;
        if (isset($prefs['emailonmail']) && $prefs['emailonmail'] && $from > 0) {
            $email = true;
        } elseif (isset($prefs['emailonmail']) && $prefs['emailonmail'] && $from == 0 && isset($prefs['systemmail']) && $prefs['systemmail']) {
            $email = true;
        }
        $emailadd = isset($row['emailaddress']) ? $row['emailaddress'] : '';

        if (!EmailValidator::isValid($emailadd)) {
            $email = false;
        }
        if ($email && !$noemail) {
            $sql = 'SELECT name FROM ' . Database::prefix('accounts') . " WHERE acctid='$from'";
            $result = Database::query($sql);
            $row1 = Database::fetchAssoc($result);
            if (Database::numRows($result) > 0 && $row1['name'] != '') {
                $fromline = Sanitize::fullSanitize($row1['name']);
            } else {
                $fromline = Translator::translateInline('The Green Dragon', 'mail');
            }
            $sql = 'SELECT name FROM ' . Database::prefix('accounts') . " WHERE acctid=$to";
            $result = Database::query($sql);
            $row1 = Database::fetchAssoc($result);
            Database::freeResult($result);
            $toline = Sanitize::fullSanitize($row1['name']);
            $body = preg_replace("'[`]n'", "\n", $body);
            $body = Sanitize::fullSanitize($body);
            $subject = htmlentities(Sanitize::fullSanitize($subject), ENT_COMPAT, $settings->getSetting('charset', 'UTF-8'));
            $original = Settings::getInstance();
            $settings_extended = new Settings('settings_extended');
            $subj = Translator::translateMail($settings_extended->getSetting('notificationmailsubject'), $to);
            $msg = Translator::translateMail($settings_extended->getSetting('notificationmailtext'), $to);
            Settings::setInstance($original);
            $GLOBALS['settings'] = $original;
            $settings = $original;
            $replace = [
                '{subject}' => stripslashes($subject),
                '{sendername}' => $fromline,
                '{receivername}' => $toline,
                '{body}' => stripslashes($body),
                '{gameurl}' => $settings->getSetting('serverurl', 'https://lotgd.com'),
            ];
            $mailbody = str_replace(array_keys($replace), array_values($replace), $msg);
            $mailsubj = str_replace(array_keys($replace), array_values($replace), $subj);
            $mailbody = str_replace('`n', "\n\n", $mailbody);
            $to_array = [$emailadd => $toline];
            $adminEmail = $settings->getSetting('gameadminemail', 'postmaster@localhost');
            $from_array = [$adminEmail => $adminEmail];
            self::send($to_array, $mailbody, $mailsubj, $from_array, false, 'text/plain');
        }
        DataCache::getInstance()->invalidatedatacache("mail-$to");
    }

    /**
     * Send an email using PHPMailer with the game settings.
     *
     * @param array       $to          Address => name list for recipients
     * @param string      $body        Email body text
     * @param string      $subject     Subject line
     * @param array       $from        Address => name of sender
     * @param array|false $cc          Optional CC list
     * @param string      $contenttype MIME type of the body
     */
    public static function send(array $to, string $body, string $subject, array $from, $cc = false, string $contenttype = 'text/plain'): bool
    {
        $settings = self::getSettings();
        $host = $settings->getSetting('gamemailhost', 'localhost');
        $mailusername = $settings->getSetting('gamemailusername', '');
        $mailpassword = $settings->getSetting('gamemailpassword', '');
        $smtpauth = $settings->getSetting('gamemailsmtpauth', false);
        $smtpsecure = $settings->getSetting('gamemailsmtpsecure', 'tls');
        $port = $settings->getSetting('gamemailsmtpport', '587');

        try {
            $mail = new PHPMailer(true);
            $body = preg_replace('/\\\\/', '', $body);
            $mail->IsSendmail();
            if ($mailpassword !== '') {
                $mail->isSMTP();
                $mail->Host = $host;
                $mail->Username = $mailusername;
                $mail->Password = $mailpassword;
                if ($smtpauth != false) {
                    $mail->SMTPAuth = $smtpauth;
                    $mail->SMTPSecure = $smtpsecure;
                    $mail->Port = $port;
                }
            }
            foreach ($from as $addr => $name) {
                $mail->AddReplyTo($addr, $name);
                $mail->From = $addr;
                $mail->FromName = $name;
            }
            if ($cc !== false) {
                foreach ($cc as $addr => $name) {
                    if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }
                    $mail->AddCC($addr, $name);
                }
            }
            foreach ($to as $addr => $name) {
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $mail->AddAddress($addr, $name);
            }
            $mail->Subject = $subject;
            $mail->WordWrap = 80;
            $mail->CharSet = 'utf-8';
            $mail->SetLanguage('en');
            $mail->Body = $body;
            if ($contenttype != 'text/plain') {
                $mail->AltBody = 'To view the message, please use an HTML compatible email viewer!';
                $mail->IsHTML(true);
            }
            $mail->Send();
            return true;
        } catch (Exception $e) {
            error_log($mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Delete a single mail message for a user.
     */
    public static function deleteMessage(int $userId, int $messageId): void
    {
        $sql = 'DELETE FROM ' . Database::prefix('mail') . " WHERE msgto=$userId AND messageid=$messageId";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("mail-$userId");
    }

    /**
     * Delete multiple mail messages for a user.
     *
     * @param array $messageIds List of message IDs
     */
    public static function deleteMessages(int $userId, array $messageIds): void
    {
        if (empty($messageIds)) {
            return;
        }
        $ids = implode("','", array_map('intval', $messageIds));
        $sql = 'DELETE FROM ' . Database::prefix('mail') . " WHERE msgto=$userId AND messageid IN ('$ids')";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("mail-$userId");
    }

    /**
     * Mark a message as unread.
     */
    public static function markUnread(int $userId, int $messageId): void
    {
        $sql = 'UPDATE ' . Database::prefix('mail') . " SET seen=0 WHERE msgto=$userId AND messageid=$messageId";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("mail-$userId");
    }

    /**
     * Count messages in a user's inbox.
     */
    public static function inboxCount(int $userId, bool $onlyUnread = false): int
    {
        $extra = $onlyUnread ? ' AND seen=0' : '';
        $sql = 'SELECT count(messageid) AS count FROM ' . Database::prefix('mail') . " WHERE msgto=$userId $extra";
        $result = Database::query($sql);
        $row = Database::fetchAssoc($result);
        return (int) ($row['count'] ?? 0);
    }

    /**
     * Determine if a user's inbox is currently full.
     */
    public static function isInboxFull(int $userId, bool $onlyUnread = false): bool
    {
        $limit = (int) self::getSettings()->getSetting('inboxlimit', 50);
        return self::inboxCount($userId, $onlyUnread) >= $limit;
    }

    /**
     * Retrieve all messages for a user's inbox ordered as requested.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getInbox(int $userId, string $order = 'sent', string $direction = 'DESC'): array
    {
        $mail = Database::prefix('mail');
        $acc = Database::prefix('accounts');

        $allowed = ['subject', 'name', 'sent'];
        if (!in_array($order, $allowed, true)) {
            $order = 'sent';
        }

        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT subject,messageid,$acc.name,$acc.acctid,msgfrom,seen,sent "
             . "FROM $mail LEFT JOIN $acc ON $acc.acctid=$mail.msgfrom "
             . "WHERE msgto='$userId' ORDER BY $order $direction";

        $result = Database::query($sql);
        $messages = [];
        while ($row = Database::fetchAssoc($result)) {
            $messages[] = $row;
        }

        return $messages;
    }

    /**
     * Fetch a single message with account information.
     */
    public static function getMessage(int $userId, int $messageId): ?array
    {
        $mail = Database::prefix('mail');
        $acc = Database::prefix('accounts');
        $sql = "SELECT $mail.*,$acc.name,$acc.acctid,$acc.login FROM $mail "
             . "LEFT JOIN $acc ON $acc.acctid=$mail.msgfrom "
             . "WHERE msgto='$userId' AND messageid='$messageId'";

        $result = Database::query($sql);
        if (Database::numRows($result) > 0) {
            return Database::fetchAssoc($result);
        }

        return null;
    }

    /**
     * Mark a message as read.
     */
    public static function markRead(int $userId, int $messageId): void
    {
        $sql = 'UPDATE ' . Database::prefix('mail')
            . " SET seen=1 WHERE msgto='$userId' AND messageid='$messageId'";
        Database::query($sql);
        DataCache::getInstance()->invalidatedatacache("mail-$userId");
    }

    /**
     * Find the previous and next message IDs around a specific message.
     *
     * @return array{prev:int,next:int}
     */
    public static function adjacentMessageIds(int $userId, int $messageId): array
    {
        $mail = Database::prefix('mail');
        $sql = "SELECT messageid FROM $mail WHERE msgto=$userId" .
            " AND messageid < $messageId ORDER BY messageid DESC LIMIT 1";
        $result = Database::query($sql);
        $prev = Database::numRows($result) > 0 ? (int)Database::fetchAssoc($result)['messageid'] : 0;

        $sql = "SELECT messageid FROM $mail WHERE msgto=$userId" .
            " AND messageid > $messageId ORDER BY messageid LIMIT 1";
        $result = Database::query($sql);
        $next = Database::numRows($result) > 0 ? (int)Database::fetchAssoc($result)['messageid'] : 0;

        return ['prev' => $prev, 'next' => $next];
    }
}
