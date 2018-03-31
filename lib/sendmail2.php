<?
/* Function send_mail
* $to is an array of type "emailaddress"=>"Name of the Emailholder"
* $from is an array of type "emailaddress"=>"Name of the Emailholder"
* $cc is an array of type "emailaddress"=>"Name of the Emailholder"
* $contenttype is the MIME type
*/
function send_email($to, $body, $subject, $from, $cc=false,$contenttype="text/plain") {
	$eol="\n"; //normall \r\n - but somehow this produces wrong linebreaks...
	$mime_boundary=md5(time());
	$subject=str_replace("\n","",$subject);

	if (!is_array($from)) return false;
	
	list($fromname,$fromaddress)=each($from); //split "email"=>"Name"
	if ($fromname=="" || $fromaddress=="") return false;

	# Common Headers
	$headers = "";
		$headers  = 'MIME-Version: 1.0' . $eol;
		$headers .= 'Content-type: '.$contenttype.'; charset='.getsetting('charset','iso-8859-1').$eol;
	$headers .= 'Content-Transfer-Encoding: 8bit'.$eol;

	$headers .= "From: ".$fromname." <".$fromaddress.">".$eol;
	$to_header="To: ";
	foreach ($to as $email=>$name) {
		$too.= "$email, ";
	}
	$too=substr($too,0,strlen($too)-2);
	$to_header.=$too; //not used in the headers currently
	if ($cc!==false) {
		$ccc="CC: ";
		foreach ($cc as $email=>$name) {
			$ccc.= $name." <$email>";
		}
		$ccc=substr($ccc,0,strlen($too)-2);
		$headers .= $ccc.$eol;
	}
	$headers .= "Reply-To: ".$fromname." <".$fromaddress.">".$eol;
	$headers .= "Return-Path: ".$fromname." <".$fromaddress.">".$eol;		// these two to set reply address
	$headers .= "Message-ID: <".time()."-".$fromaddress.">".$eol;
	$headers .= "X-Mailer: PHP v".phpversion().$eol;					// These two to help avoid spam-filters
	
	$msg=$body;
	
	# SEND THE EMAIL
	ini_set(sendmail_from,$fromaddress);	// the INI lines are to force the From Address to be used !
	$mail_sent = mail($too, $subject, $msg, $headers);
 
	ini_restore(sendmail_from);
 
	return $mail_sent;
}
?>
