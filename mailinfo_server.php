<?php

define("OVERRIDE_FORCED_NAV",true);
require("common.php");

function mail_status($args=false) {
	if ($args===false) return;
	$new=maillink();
	$objResponse = new xajaxResponse();
	$objResponse->assign("maillink","innerHTML", $new);
	global $session;
	$warning='';
	$timeout=strtotime($session['user']['laston'])-strtotime(date("Y-m-d H:i:s",strtotime("-".getsetting("LOGINTIMEOUT",900)." seconds")));
	if ($timeout<200){
		$warning="<br>".appoencode("`t").sprintf("TIMEOUT in %s seconds!",$timeout);
	} elseif ($timeout<0) {
		$warning="<br>".appoencode("`t")."Your session has timed out. Please log in again.";
	} else $warning='';
	$objResponse->assign("notify","innerHTML", $warning);
	return $objResponse;
}
require("mailinfo_common.php");
$xajax->processRequest();





?>
