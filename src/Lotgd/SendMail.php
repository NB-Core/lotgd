<?php
namespace Lotgd;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Wrapper around PHPMailer for sending game emails.
 */
class SendMail
{
    /**
     * Send an email via the configured mail server.
     *
     * @param array       $to          Address list as ['email' => 'Name']
     * @param string      $body        Message body
     * @param string      $subject     Subject line
     * @param array       $from        From address as ['email' => 'Name']
     * @param array|false $cc          Optional CC list
     * @param string      $contenttype Mime type of body
     *
     * @return bool True on success
     */
    public static function send($to, $body, $subject, $from, $cc = false, string $contenttype = 'text/plain')
    {
        require_once __DIR__ . '/../autoload.php';

        $host = getsetting('gamemailhost', 'localhost');
        $mailusername = getsetting('gamemailusername', '');
        $mailpassword = getsetting('gamemailpassword', '');
        $smtpauth = getsetting('gamemailsmtpauth', false);
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
            foreach ($from as $add => $name) {
                $mail->AddReplyTo($add, $name);
                $mail->From = $add;
                $mail->FromName = $name;
            }
            if ($cc !== false) {
                foreach ($cc as $add => $name) {
                    if (!filter_var($add, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }
                    $mail->AddCC($add, $name);
                }
            }
            foreach ($to as $add => $name) {
                if (!filter_var($add, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $mail->AddAddress($add, $name);
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
            output('`$An error has been encountered, please report this: %s`n`n', $mail->ErrorInfo);
            return false;
        }
    }
}
