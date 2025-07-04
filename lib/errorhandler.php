<?php
use Lotgd\Backtrace;

function logd_error_handler($errno, $errstr, $errfile, $errline){
	global $session;
	static $in_error_handler = 0;
	// If we have used the @ operator, just don't report anything!
	if (!error_reporting()) return;
	ini_set('display_errors', 1);
	$in_error_handler++;
	if ($in_error_handler > 1){//prevents the error handler from being re-called when we're already within a call of it.
		if ($errno & (E_USER_WARNING | E_WARNING)){
			echo "PHP Warning: \"$errstr\"<br>in <b>$errfile</b> at <b>$errline</b>.  Additionally this occurred while within logd_error_handler().<br>";
		}elseif ($errno & (E_USER_ERROR | E_ERROR)){
			echo "PHP ERROR: \"$errstr\"<br>in <b>$errfile</b> at <b>$errline</b>.  Additionally this occurred while within logd_error_handler().<br>";
		}
		$in_error_handler--;
		return;
	}
	switch($errno){
	case E_NOTICE:
	case E_USER_NOTICE:
		if (getsetting('show_notices', 0) &&
				$session['user']['superuser'] & SU_SHOW_PHPNOTICE) {
			debug("PHP Notice: \"$errstr\"<br>in <b>$errfile</b> at <b>$errline</b>.");
		}
		break;
	case E_WARNING:
	case E_USER_WARNING:
		tlschema("errorhandler");
		if ($session['user']['superuser'] & SU_DEBUG_OUTPUT == SU_DEBUG_OUTPUT) {
			output("PHP Warning: \"%s\"`nin `b%s`b at `b%s`b.`n",$errstr,$errfile,$errline,true);
			tlschema();
			$backtrace = Backtrace::show();
			rawoutput($backtrace);
		} else $backtrace="";
		if (getsetting("notify_on_warn",0) > ""){
			//$args = func_get_args();
			//call_user_func_array("logd_error_notify",$args);
			logd_error_notify($errno, $errstr, $errfile, $errline, $backtrace);
		}
		break;
	case E_ERROR:
	case E_USER_ERROR:
		echo sprintf("PHP ERROR: \"%s\"<br>in <b>%s</b> at <b>%s</b>.<br>",$errstr,$errfile,$errline);
		$backtrace = Backtrace::show();
		echo $backtrace;
		if (getsetting("notify_on_error",0) > ""){
			//$args = func_get_args();
			//call_user_func_array("logd_error_notify",$args);
			logd_error_notify($errno, $errstr, $errfile, $errline, $backtrace);
		}
		die();
		break;
	}
	$in_error_handler--;
}

function logd_error_notify($errno, $errstr, $errfile, $errline, $backtrace){
	global $session;
	$sendto = explode(";",getsetting("notify_address",""));
	$howoften = getsetting("notify_every",30);
	reset($sendto);
       $data = datacache("error_notify",86400);
       if (!is_array($data)){
               $data = array('firstrun'=>false,'errors'=>array());
               if (!updatedatacache("error_notify", $data)) {
                       error_log('Unable to write datacache for error_notify');
               }
       } else {
               if (!isset($data['errors']) || !is_array($data['errors'])){
                       $data['errors'] = array();
               }
               if (!array_key_exists('firstrun',$data)){
                       $data['firstrun'] = false;
               }
       }
	$do_notice = false;
	if (!array_key_exists($errstr,$data['errors'])){
		$do_notice = true;
	}elseif (strtotime("now") - ($data['errors'][$errstr]) > $howoften * 60) {
		$do_notice = true;
	}
	if (!isset($data['firstrun']))
		$data['firstrun']=false;
	if ($data['firstrun']){
		debug("First run, not notifying users.");
	}else{
		if ($do_notice){

			/***
			  * Set up the mime bits
			 **/
			require_once("sanitize.php");
			$userstr = "";
			if ($session && isset($session['user']['name']) && isset($sesson['user']['acctid'])) {
				$userstr = "Error triggered by user " . $session['user']['name'] . " (" . $session['user']['acctid'] . ")\n";
			}
			$plain_text = "$userstr$errstr in $errfile ($errline)\n".sanitize_html($backtrace);
			$html_text = "<html><body>$errstr in $errfile ($errline)<hr>$backtrace</body></html>";

			$semi_rand = md5(time());
			$hostname = (isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'not called from browser, no hostname');
			$subject = "$hostname $errno";

			$body = $html_text; //send as html
			foreach ($sendto as $key=>$email) {
				debug("Notifying $email of this error.");

                                $from = array(getsetting("gameadminemail", "postmaster@localhost") => getsetting("gameadminemail", "postmaster@localhost"));
                                \Lotgd\Mail::send(array($email => $email), $body, $subject, $from, false, "text/html");
/*				mail($email, $subject, $body,
					"From: " . $from . "\n" .
					"MIME-Version: 1.0\n" .
					"Content-Type: multipart/alternative;\n" .
					"     boundary=" . $mime_boundary_header);
*/			}
			//mark the time that notice was last sent for this error.
			$data['errors'][$errstr] = strtotime("now");
		}else{
			debug("Not notifying users for this error, it's only been ".round((strtotime("now") - $data['errors'][$errstr]) / 60,2)." minutes.");
		}
	}
       if (!updatedatacache("error_notify", $data)) {
               error_log('Unable to write datacache for error_notify');
       }
       debug($data);
}
set_error_handler("logd_error_handler");
?>
