<?php

declare(strict_types=1);

/**
 * Prepares the mail link JavaScript code snippets used by the
 * interface. It sets variables required by the asynchronous mail and
 * commentary polling.
 */

// Only bootstrap the application when this script is executed directly.
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    require_once __DIR__ . '/common/bootstrap.php';
}

require_once __DIR__ . '/common/settings.php';

global $session;

$timeout = \Lotgd\Async\Handler\Timeout::getInstance();
$checkMailTimeoutSeconds = $timeout->getCheckMailTimeoutSeconds();
$clearScriptExecutionSeconds = $timeout->getClearScriptExecutionSeconds();
$startTimeoutShowSeconds = $timeout->getStartTimeoutShowSeconds();

$maillink_add_after = "<script>";
$maillink_add_after .= "var lotgd_comment_section = " . json_encode($session['last_comment_section'] ?? '') . ";";
$maillink_add_after .= "var lotgd_lastCommentId = " . (int)($session['lastcommentid'] ?? 0) . ";";
$maillink_add_after .= "var lotgd_poll_interval_ms = " . ($checkMailTimeoutSeconds * 1000) . ";";
$maillink_add_after .= "var lotgd_timeout_delay_ms = " . ((getsetting('LOGINTIMEOUT', 900) - $startTimeoutShowSeconds) * 1000) . ";";
$maillink_add_after .= "var lotgd_clear_delay_ms = " . ((getsetting('LOGINTIMEOUT', 900) - $clearScriptExecutionSeconds) * 1000) . ";";
$maillink_add_after .= "</script>";
$maillink_add_after .= "<div id='notify'></div>";
