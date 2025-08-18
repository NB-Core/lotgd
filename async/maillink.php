<?php

declare(strict_types=1);

/**
 * Prepares the mail link JavaScript code snippets used by the
 * interface. It injects Jaxon scripts and variables required by the
 * asynchronous mail and commentary polling.
 */
 
require_once __DIR__ . '/common/bootstrap.php';
require_once __DIR__ . '/common/jaxon.php';

global $jaxon, $session;
$s_js = $jaxon->getJs();
$s_script = $jaxon->getScript();
$maillink_add_pre = $s_js . $s_script;
$maillink_add_after = "<script>";
$maillink_add_after .= "var lotgd_comment_section = " . json_encode($session['last_comment_section'] ?? '') . ";";
$maillink_add_after .= "var lotgd_lastCommentId = " . (int)($session['lastcommentid'] ?? 0) . ";";
$maillink_add_after .= "var lotgd_poll_interval_ms = " . ($check_mail_timeout_seconds * 1000) . ";";
$maillink_add_after .= "var lotgd_timeout_delay_ms = " . ((getsetting('LOGINTIMEOUT', 900) - $start_timeout_show_seconds) * 1000) . ";";
$maillink_add_after .= "var lotgd_clear_delay_ms = " . ((getsetting('LOGINTIMEOUT', 900) - $clear_script_execution_seconds) * 1000) . ";";
$maillink_add_after .= "</script>";
$maillink_add_after .= "<script src='/async/js/ajax_polling.js' defer></script>";
$maillink_add_after .= "<div id='notify'></div>";
