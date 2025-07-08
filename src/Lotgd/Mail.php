<?php
declare(strict_types=1);
namespace Lotgd;

/**
 * Placeholder class for future mail handling refactoring.
 */
use Lotgd\Settings;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mail
{
    // TODO: Move functions from lib/mail.php here

    /**
     * Send a system generated mail to a user.
     */
    public static function systemMail(int $to, string $subject, string $body, int $from = 0, bool $noemail = false): void
    {
        $sql = 'SELECT prefs,emailaddress FROM ' . db_prefix('accounts') . " WHERE acctid='$to'";
        $result = db_query($sql);
        $row = db_fetch_assoc($result);
        db_free_result($result);
        $prefs = isset($row['prefs']) ? @unserialize($row['prefs']) : [];
        $subject = SafeEscape::escape($subject);
        if ($from === 0) {
            $body = SafeEscape::escape($body);
        } else {
            $subject = str_replace(["\n", '`n'], '', $subject);
            $body = SafeEscape::escape($body);
            if (!(isset($prefs['dirtyemail']) && $prefs['dirtyemail'])) {
                $subject = soap($subject, false, 'mail');
                $body = soap($body, false, 'mail');
            }
        }

        $body = addslashes(substr(stripslashes($body), 0, (int)getsetting('mailsizelimit', 1024)));
        $sql = 'INSERT INTO ' . db_prefix('mail') . " (msgfrom,msgto,subject,body,sent) VALUES ('" . (int)$from . "','" . (int)$to . "','$subject','$body','" . date('Y-m-d H:i:s') . "')";
        db_query($sql);
        invalidatedatacache("mail-$to");
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
            $sql = 'SELECT name FROM ' . db_prefix('accounts') . " WHERE acctid='$from'";
            $result = db_query($sql);
            $row1 = db_fetch_assoc($result);
            if (db_num_rows($result) > 0 && $row1['name'] != '') {
                $fromline = full_sanitize($row1['name']);
            } else {
                $fromline = translate_inline('The Green Dragon', 'mail');
            }
            $sql = 'SELECT name FROM ' . db_prefix('accounts') . " WHERE acctid=$to";
            $result = db_query($sql);
            $row1 = db_fetch_assoc($result);
            db_free_result($result);
            $toline = full_sanitize($row1['name']);
            $body = preg_replace("'[`]n'", "\n", $body);
            $body = full_sanitize($body);
            $subject = htmlentities(full_sanitize($subject), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'));
            $settings_extended = new Settings('settings_extended');
            $subj = translate_mail($settings_extended->getSetting('notificationmailsubject'), $to);
            $msg = translate_mail($settings_extended->getSetting('notificationmailtext'), $to);
            $replace = [
                '{subject}' => stripslashes($subject),
                '{sendername}' => $fromline,
                '{receivername}' => $toline,
                '{body}' => stripslashes($body),
                '{gameurl}' => getsetting('serverurl', 'https://lotgd.com'),
            ];
            $mailbody = str_replace(array_keys($replace), array_values($replace), $msg);
            $mailsubj = str_replace(array_keys($replace), array_values($replace), $subj);
            $mailbody = str_replace('`n', "\n\n", $mailbody);
            $to_array = [$emailadd => $toline];
            $from_array = [getsetting('gameadminemail', 'postmaster@localhost') => getsetting('gameadminemail', 'postmaster@localhost')];
            self::send($to_array, $mailbody, $mailsubj, $from_array, false, 'text/plain');
        }
        invalidatedatacache("mail-$to");
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
        $host = getsetting('gamemailhost', 'localhost');
        $mailusername = getsetting('gamemailusername', '');
        $mailpassword = getsetting('gamemailpassword', '');
        $smtpauth = getsetting('gamailsmtpauth', false);
        $smtpsecure = getsetting('gamemailsmtpsecure', 'tls');
        $port = getsetting('gamemailsmtpport', '587');

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
            output("`\$An error has been encountered, please report this: %s`n`n", $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Delete a single mail message for a user.
     */
    public static function deleteMessage(int $userId, int $messageId): void
    {
        $sql = 'DELETE FROM ' . db_prefix('mail') . " WHERE msgto=$userId AND messageid=$messageId";
        db_query($sql);
        invalidatedatacache("mail-$userId");
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
        $sql = 'DELETE FROM ' . db_prefix('mail') . " WHERE msgto=$userId AND messageid IN (\'$ids\')";
        db_query($sql);
        invalidatedatacache("mail-$userId");
    }

    /**
     * Mark a message as unread.
     */
    public static function markUnread(int $userId, int $messageId): void
    {
        $sql = 'UPDATE ' . db_prefix('mail') . " SET seen=0 WHERE msgto=$userId AND messageid=$messageId";
        db_query($sql);
        invalidatedatacache("mail-$userId");
    }

    /**
     * Count messages in a user's inbox.
     */
    public static function inboxCount(int $userId, bool $onlyUnread = false): int
    {
        $extra = $onlyUnread ? ' AND seen=0' : '';
        $sql = 'SELECT count(messageid) AS count FROM ' . db_prefix('mail') . " WHERE msgto=$userId $extra";
        $result = db_query($sql);
        $row = db_fetch_assoc($result);
        return (int) ($row['count'] ?? 0);
    }

    /**
     * Determine if a user's inbox is currently full.
     */
    public static function isInboxFull(int $userId, bool $onlyUnread = false): bool
    {
        $limit = getsetting('inboxlimit', 50);
        return self::inboxCount($userId, $onlyUnread) >= $limit;
    }

    /**
     * Retrieve all messages for a user's inbox ordered as requested.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getInbox(int $userId, string $order = 'sent', string $direction = 'DESC'): array
    {
        $mail = db_prefix('mail');
        $acc = db_prefix('accounts');

        $allowed = ['subject', 'name', 'sent'];
        if (!in_array($order, $allowed, true)) {
            $order = 'sent';
        }

        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT subject,messageid,$acc.name,$acc.acctid,msgfrom,seen,sent "
             . "FROM $mail LEFT JOIN $acc ON $acc.acctid=$mail.msgfrom "
             . "WHERE msgto='$userId' ORDER BY $order $direction";

        $result = db_query($sql);
        $messages = [];
        while ($row = db_fetch_assoc($result)) {
            $messages[] = $row;
        }

        return $messages;
    }

    /**
     * Fetch a single message with account information.
     */
    public static function getMessage(int $userId, int $messageId): ?array
    {
        $mail = db_prefix('mail');
        $acc = db_prefix('accounts');
        $sql = "SELECT $mail.*,$acc.name,$acc.acctid FROM $mail "
             . "LEFT JOIN $acc ON $acc.acctid=$mail.msgfrom "
             . "WHERE msgto='$userId' AND messageid='$messageId'";

        $result = db_query($sql);
        if (db_num_rows($result) > 0) {
            return db_fetch_assoc($result);
        }

        return null;
    }

    /**
     * Mark a message as read.
     */
    public static function markRead(int $userId, int $messageId): void
    {
        $sql = 'UPDATE ' . db_prefix('mail')
            . " SET seen=1 WHERE msgto='$userId' AND messageid='$messageId'";
        db_query($sql);
        invalidatedatacache("mail-$userId");
    }

    /**
     * Find the previous and next message IDs around a specific message.
     *
     * @return array{prev:int,next:int}
     */
    public static function adjacentMessageIds(int $userId, int $messageId): array
    {
        $mail = db_prefix('mail');
        $sql = "SELECT messageid FROM $mail WHERE msgto=$userId" .
            " AND messageid < $messageId ORDER BY messageid DESC LIMIT 1";
        $result = db_query($sql);
        $prev = db_num_rows($result) > 0 ? (int)db_fetch_assoc($result)['messageid'] : 0;

        $sql = "SELECT messageid FROM $mail WHERE msgto=$userId" .
            " AND messageid > $messageId ORDER BY messageid LIMIT 1";
        $result = db_query($sql);
        $next = db_num_rows($result) > 0 ? (int)db_fetch_assoc($result)['messageid'] : 0;

        return ['prev' => $prev, 'next' => $next];
    }
}
