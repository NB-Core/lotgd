<?php
/* Function send_mail
 * $to is an array of type "emailaddress"=>"Name of the Emailholder"
 * $from is an array of type "emailaddress"=>"Name of the Emailholder"
 * $cc is an array of type "emailaddress"=>"Name of the Emailholder"
 * $contenttype is the MIME type
 */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once('ext/vendor/autoload.php');

function send_email($to, $body, $subject, $from, $cc=false,$contenttype="text/plain") {
	/**
	 * Simple example script using PHPMailer with exceptions enabled
	 * @package phpmailer
	 * @version $Id$
	 */

	$host = getsetting('gamemailhost','localhost');
	$mailusername = getsetting('gamemailusername','');
	$mailpassword = getsetting('gamemailpassword','');
	$smtpauth = getsetting('gamemailsmtpauth',false);
	$smtpsecure = getsetting('gamemailsmtpsecure','tls');
	$port = getsetting('gamemailsmtpport','587');

	try {
		$mail = new PHPMailer(true); //New instance, with exceptions enabled

		$body             = preg_replace('/\\\\/','', $body); //Strip backslashes

		$mail->IsSendmail();  // tell the class to use Sendmail
		if ($mailpassword !== '') {
		//if no password is given, it will be considered standard server send
			$mail->isSMTP();
			$mail->Host = $host;;
			$mail->Username = $mailusername;
			$mail->Password = $mailpassword;
			if ($smtpauth != false) {
				$mail->SMTPAuth = $smtpauth;
				$mail->SMTPSecure = 'tls';
				$mail->Port = $port;
			}
		}
		//only one
		foreach ($from as $add=>$name) {
			$mail->AddReplyTo($add,$name);

			$mail->From       = $add;
			$mail->FromName   = $name;
		}

		if ($cc!==false) {
			foreach ($cc as $add=>$name) {
				$mail->AddCC($add,$name);
			}
		}


		foreach ($to as $add=>$name) {
			$mail->AddAddress($add,$name);
		}

		$mail->Subject  = $subject;

		$mail->WordWrap   = 80; // set word wrap
		$mail->CharSet = 'utf-8';
		$mail->SetLanguage ("en");

		$mail->Body = $body;

		if ($contenttype != "text/plain") {
			$mail->AltBody    = "To view the message, please use an HTML compatible email viewer!"; // optional, comment out and test
			$mail->IsHTML(true); // send as HTML
		}
		$mail->Send();
		return true;
#echo 'Message has been sent.';
	} catch (Exception $e) {
		output("`\$An error has been encountered, please report this: %s`n`n", $mail->ErrorInfo);
	}
}
?>
