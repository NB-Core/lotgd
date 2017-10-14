<?php

define("OVERRIDE_FORCED_NAV",true);
/* you need to check if somebody timed out.
if you call common.php and we have a timeout, he will the redirect to index.php?op=timeout, resulting in a full page
which will (called in 1s intervals) download a lot of useless traffic to him and from your server

therefore, a common.php is used that will not do a DO_FORCED_NAVIGATION.
This will just make our mailinfo return a small string in case of a timeout, not an entire error page
*/ 
require("mailinfo_common.php");

function mail_status($args=false) {
	if ($args===false) return;
	$timeout_setting=getsetting("LOGINTIMEOUT",360); // seconds
	$new=maillink();
	$tabtext=maillinktabtext();
	$objResponse = new xajaxResponse();
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
	} elseif ($timeout<1800){
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
	if ($args===false) return;
	$timeout_setting=120; // seconds
	global $session;
	$warning='';
	$never_timeout_if_browser_open=1;
	if ($never_timeout_if_browser_open==1) {
		$session['user']['laston']=date("Y-m-d H:i:s"); // set to now
		//manual db update
		$sql = "UPDATE ".db_prefix('accounts')." set laston='".$session['user']['laston']."' WHERE acctid=".$session['user']['acctid'];
		db_query($sql);
	}
	$timeout=strtotime($session['user']['laston'])-strtotime(date("Y-m-d H:i:s",strtotime("-".getsetting("LOGINTIMEOUT",900)." seconds")));
	if ($timeout<=1) {
		$warning="<br/>".appoencode("`\$`b")."Your session has timed out!".appoencode("`b");
	} elseif ($timeout<920){
		$warning="<br/>".appoencode("`t").sprintf("TIMEOUT in %s seconds!",$timeout);
	} else $warning='';
	$objResponse = new xajaxResponse();
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
	$objResponse = new xajaxResponse();
	$objResponse->assign($section,"innerHTML", $new);
}



require("mailinfo_base.php");
$xajax->processRequest();





?>
