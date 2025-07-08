<?php
$maillink_add_pre = $s_js . $s_script;
$maillink_add_after = "<script>";
$maillink_add_after .= "var lotgd_comment_section = " . json_encode($session['last_comment_section'] ?? '') . ";";
$maillink_add_after .= "var lotgd_lastCommentId = " . (int)($session['lastcommentid'] ?? 0) . ";";
$maillink_add_after .= "var lotgd_mail_interval_ms = " . ($check_mail_timeout_seconds * 1000) . ";";
$maillink_add_after .= "var lotgd_comment_interval_ms = 10000;";
$maillink_add_after .= "var lotgd_timeout_interval_ms = " . ($check_timeout_seconds * 1000) . ";";
$maillink_add_after .= "var lotgd_timeout_delay_ms = " . ((getsetting('LOGINTIMEOUT', 900) - $start_timeout_show_seconds) * 1000) . ";";
$maillink_add_after .= "var lotgd_clear_delay_ms = " . ((getsetting('LOGINTIMEOUT', 900) - $clear_script_execution_seconds) * 1000) . ";";
$maillink_add_after .= "</script>";
$maillink_add_after .= "<script src='/ext/js/mail_ajax.js'></script>";
$maillink_add_after .= "<div id='notify'></div>";

