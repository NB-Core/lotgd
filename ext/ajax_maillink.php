<?php
$maillink_add_pre=$s_js.$s_script;
$maillink_add_after="<script type='text/javascript'>
$(window).ready(function(){
		set_mail_ajax();
		window.setTimeout('set_timeout_ajax()','".((getsetting("LOGINTIMEOUT",900)-$start_timeout_show_seconds)*1000)."');

		window.setTimeout('clear_ajax()','".((getsetting("LOGINTIMEOUT",900)-$clear_script_execution_seconds)*1000)."');
		});
function set_mail_ajax() {
	active_mail_interval=window.setInterval('jaxon_mail_status(1)',".($check_mail_timeout_seconds*1000).");
}
function set_timeout_ajax() {
	active_timeout_interval=window.setInterval('jaxon_timeout_status(1)',".($check_timeout_seconds*1000).");
}
function clear_ajax() {
	window.clearInterval(active_timeout_interval);
	window.clearInterval(active_mail_interval);
}
</script>";

$maillink_add_after.="<div id='notify'></div>";
