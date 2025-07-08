<?php
declare(strict_types=1);
/**
 * Configuration values controlling the AJAX polling behaviour for
 * mail checks, timeouts and intervals. Adjust these values to tune
 * the client-side update frequency.
 */

$mail_debug=0; //debug
$never_timeout_if_browser_open=0;

if ($mail_debug==0) {
	$check_mail_timeout_seconds = 10; 	// how often check for new mail
	$check_timeout_seconds = 5;  		// how often checking for timeout
	$start_timeout_show_seconds = 300;  	//when should the counter start to display (time left)
	$clear_script_execution_seconds = -1;	//	when javascript should stop checking (ddos)
}
//test
if ($mail_debug==1) {
	$check_mail_timeout_seconds = 500; 	// how often check for new mail
	$check_timeout_seconds = 5;  		// how often checking for timeout
	$start_timeout_show_seconds = 999; 	//when should the counter start to display (time left)
	$clear_script_execution_seconds = -1; 	//	when javascript should stop checking (ddos)
}
