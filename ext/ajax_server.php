<?php
/* you need to check if somebody timed out.
   if you call common.php and we have a timeout, he will the redirect to index.php?op=timeout, resulting in a full page
   which will (called in 1s intervals) download a lot of useless traffic to him and from your server

   therefore, a common.php is used that will not do a DO_FORCED_NAVIGATION.
   This will just make our mailinfo return a small string in case of a timeout, not an entire error page
 */ 
//if ($_SERVER['REMOTE_ADDR']=="86.123.157.144") {
#	$s=print_r($_POST,true);
#	$s=$_SERVER['REMOTE_ADDR'].$s;
#	file_put_contents("/var/www/html/naruto/debug.txt",$s, FILE_APPEND);
//}
use Jaxon\Response\Response;          // and the Response class
use function Jaxon\jaxon;

function mail_expired($args=false) {
	if ($args===false) return;
	chdir("..");
	$new="Expired";
	$tabtext="Expired";
	$objResponse = jaxon()->newResponse();
	$objResponse->assign("maillink","innerHTML", $new);
	$objResponse->script("document.title=\"".$tabtext."\";");
	global $session;
	$warning='';
	$warning="<br/>".appoencode("`\$`b")."Your session has timed out!".appoencode("`b");
	session_unset();    
	session_destroy(); // destroy if timeout
	$objResponse->assign("notify","innerHTML", $warning);
	return $objResponse;
}

function mail_status($args=false) {
	global $start_timeout_show_seconds;
	chdir("..");
	if ($args===false) return;
	$timeout_setting=getsetting("LOGINTIMEOUT",360); // seconds
	$new=maillink();
	$tabtext=maillinktabtext();
	$objResponse = jaxon()->newResponse();
	$objResponse->assign("maillink","innerHTML", $new);
	if ($tabtext=='') { //empty
		$tabtext=translate_inline('Legend of the Green Dragon','home');
		//		$objResponse->script("if (tab_oldtext!=='' && tab_oldtext!==document.title) {document.title=tab_oldtext; tab_oldtext='';}");
		$objResponse->script("document.title=\"".$tabtext."\";");
	} else {
		//		$objResponse->script("if (tab_oldtext==='') { tab_oldtext=document.title; }");
		//		$objResponse->script("console.log('Text: '+tab_oldtext)");
		$objResponse->script("document.title=\"".$tabtext."\";");
	}
	global $session;
	$warning='';
	$timeout=strtotime($session['user']['laston'])-strtotime(date("Y-m-d H:i:s",strtotime("-".getsetting("LOGINTIMEOUT",900)." seconds")));
	if ($timeout<=1) {
		$warning="<br/>".appoencode("`\$`b")."Your session has timed out!".appoencode("`b");
		session_unset();    
		session_destroy(); // destroy if timeout
	} elseif ($timeout<$start_timeout_show_seconds){
		$m='';
		if ($timeout>60) {
			$min = floor($timeout/60);
			$timeout = $timeout-$min*60;
			$m = sprintf('%s minute',$min);
			if ($min>1) $m.='s';
			$m.=", ";
		}
		$warning="<br/>".appoencode("`t").sprintf("TIMEOUT in $m%s seconds!",$timeout);
	} else $warning='';
	$objResponse->assign("notify","innerHTML", $warning);
	return $objResponse;
}

function timeout_status($args=false) {
	global $start_timeout_show_seconds, $never_timeout_if_browser_open;
	chdir("..");
	if ($args===false) return;
	global $session;
	$warning='';
	if ($never_timeout_if_browser_open==1) {
		$session['user']['laston']=date("Y-m-d H:i:s"); // set to now
		//manual db update
		$sql = "UPDATE ".db_prefix('accounts')." set laston='".$session['user']['laston']."' WHERE acctid=".$session['user']['acctid'];
		db_query($sql);
	}
	$timeout=strtotime($session['user']['laston'])-strtotime(date("Y-m-d H:i:s",strtotime("-".getsetting("LOGINTIMEOUT",900)." seconds")));
	if ($timeout<=1) {
		$warning="".appoencode("`\$`b")."Your session has timed out!".appoencode("`b");
	} elseif ($timeout<$start_timeout_show_seconds){
		$warning="".appoencode("`t").sprintf("TIMEOUT in %s seconds!",$timeout);
	} else $warning=':-)';
	$objResponse = jaxon()->newResponse();
	$objResponse->assign("notify","innerHTML", $warning);
	return $objResponse;
}


function commentary_text($args=false) {
	global $session;
	if ($args===false || !is_array($args)) return;
	$section=$args['section'];
	$message="";
	$limit=25;
	$talkline="says";
	$schema=$args['schema'];
	$viewonly=$args['viewonly'];	
	$new=viewcommentary($section, $message, $limit, $talkline, $schema,$viewonly,1);
	$new=maillink();
	$objResponse = jaxon()->newResponse();
	$objResponse->assign($section,"innerHTML", $new);
}
