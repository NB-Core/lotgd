<?php

$mail_debug=0; //debug
$never_timeout_if_browser_open=0;

if ($mail_debug==0) {
	$check_mail_timeout_seconds = 15; 	// how often check for new mail
	$check_timeout_seconds = 2;  		// how often checking for timeout
	$start_timeout_show_seconds = 120;  	//when should the counter start to display (time left)
	$clear_script_execution_seconds = -1;	//	when javascript should stop checking (ddos)
}
//test
if ($mail_debug==1) {
	$check_mail_timeout_seconds = 500; 	// how often check for new mail
	$check_timeout_seconds = 5;  		// how often checking for timeout
	$start_timeout_show_seconds = 999; 	//when should the counter start to display (time left)
	$clear_script_execution_seconds = -1; 	//	when javascript should stop checking (ddos)
}
