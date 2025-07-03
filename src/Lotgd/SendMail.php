<?php
namespace Lotgd;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Simple wrapper around PHPMailer to send emails.
 */
class SendMail
{
    /**
     * Send an email using the configured mail server settings.
     *
     * @param array $to    Array of address => name
     * @param string $body Email body
     * @param string $subject Subject
     * @param array $from  Array of address => name
     * @param array|false $cc Optional CC list
     * @param string $contenttype MIME type
     */
    public static function send(array $to, string $body, string $subject, array $from, $cc = false, string $contenttype = 'text/plain'): bool
    {
        $host = getsetting('gamemailhost', 'localhost');
        $mailusername = getsetting('gamemailusername', '');
        $mailpassword = getsetting('gamemailpassword', '');
        $smtpauth = getsetting('gamailsmtpauth', false); // possible typo but keep
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
                    $mail->SMTPSecure = 'tls';
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
            output("`$An error has been encountered, please report this: %s`n`n", $mail->ErrorInfo);
            return false;
        }
    }
}
