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
            $sql = 'SELECT name FROM ' . db_prefix('accounts') . " WHERE acctid='$to'";
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
}
