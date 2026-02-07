<?php

declare(strict_types=1);

use Lotgd\Modules\HookHandler;
use Lotgd\MySQL\Database;

/**
 * Base setup for AJAX requests, including the Jaxon library and
 * initial JavaScript dependencies. This file prepares the page for
 * asynchronous features like mail and commentary updates.
 */

require_once __DIR__ . '/common/jaxon.php';

global $jaxon;

$s_js = $jaxon->getJs();
$s_script = $jaxon->getScript();

// Build the script loading sequence ensuring proper dependency order
$pre_headscript = ($pre_headscript ?? '')
    . $jaxon->getCss()
    . $s_js;

// CRITICAL: Add our namespace creation BEFORE the PHP-generated script
// This ensures Lotgd namespace exists when the generated code references it
$pre_headscript .= "<script>" . file_get_contents(__DIR__ . '/js/lotgd.jaxon.js') . "</script>"
    . $s_script;

// Add polling variables directly here
// Load the async settings
require_once __DIR__ . '/common/settings.php';

// Determine favicon for notifications
$default = "<link rel=\"shortcut icon\" HREF=\"/images/favicon/favicon.ico\" TYPE=\"image/x-icon\"/>"
    . "<link rel=\"apple-touch-icon\" sizes=\"180x180\" href=\"/images/favicon/apple-touch-icon.png\">"
    . "<link rel=\"icon\" type=\"image/png\" sizes=\"32x32\" href=\"/images/favicon/favicon-32x32.png\">"
    . "<link rel=\"icon\" type=\"image/png\" sizes=\"16x16\" href=\"/images/favicon/favicon-16x16.png\">"
    . "<link rel=\"manifest\" href=\"/images/favicon/site.webmanifest\">";
$favData = HookHandler::hook('pageparts-favicon', ['favicon-link' => $default]);
if (preg_match('/<link[^>]*rel=\"icon\"[^>]*sizes=\"32x32\"[^>]*href=\"([^\"]+)\"/i', $favData['favicon-link'], $matches)) {
    $favicon32 = $matches[1];
} else {
    $favicon32 = '/images/favicon/favicon-32x32.png';
}

$lastUnreadMailId = 0;
$lastUnreadMailCount = 0;

if (! empty($session['user']['acctid'])) {
    $sql = 'SELECT MAX(messageid) AS lastid, SUM(seen=0) AS unread FROM '
        . Database::prefix('mail')
        . ' WHERE msgto=\'' . $session['user']['acctid'] . '\'';

    $result = Database::query($sql);

    if ($result !== false) {
        $row = Database::fetchAssoc($result) ?: ['lastid' => 0, 'unread' => 0];
        Database::freeResult($result);

        $lastUnreadMailId = (int) ($row['lastid'] ?? 0);
        $lastUnreadMailCount = (int) ($row['unread'] ?? 0);
    }
}

$polling_script = "<script>";
$polling_script .= "var lotgd_comment_section = " . json_encode($session['last_comment_section'] ?? '') . ";";
$polling_script .= "var lotgd_lastCommentId = " . (int)($session['lastcommentid'] ?? 0) . ";";
$polling_script .= "var lotgd_lastUnreadMailId = " . json_encode($lastUnreadMailId) . ";";
$polling_script .= "var lotgd_lastUnreadMailCount = " . json_encode($lastUnreadMailCount) . ";";

$timeout = \Lotgd\Async\Handler\Timeout::getInstance();
$checkMailTimeoutSeconds = $timeout->getCheckMailTimeoutSeconds();
$clearScriptExecutionSeconds = $timeout->getClearScriptExecutionSeconds();
$start_timeout_show = max(1, $timeout->getStartTimeoutShowSeconds());

$polling_script .= "var lotgd_poll_interval_ms = " . ($checkMailTimeoutSeconds * 1000) . ";";

// Fix timeout calculations to prevent negative values
$login_timeout = getsetting('LOGINTIMEOUT', 900);
$clear_script_execution = max($login_timeout, $clearScriptExecutionSeconds);

$polling_script .= "var lotgd_timeout_delay_ms = " . (($login_timeout - $start_timeout_show) * 1000) . ";";
// Only set clear delay if it's positive and reasonable
if ($clear_script_execution > 0 && $clear_script_execution < $login_timeout) {
    $polling_script .= "var lotgd_clear_delay_ms = " . (($login_timeout - $clear_script_execution) * 1000) . ";";
} else {
    $polling_script .= "var lotgd_clear_delay_ms = null;"; // Disable auto-clear
}

$polling_script .= "console.log('AJAX polling initialized:', {interval: lotgd_poll_interval_ms + 'ms', section: lotgd_comment_section});";

// Track window focus/visibility state for consistent notification behaviour
$polling_script .= "var lotgd_windowHasFocus = document.hasFocus();";
$polling_script .= "function lotgdUpdateWindowFocusState() { lotgd_windowHasFocus = document.visibilityState === 'visible' && document.hasFocus(); }";
$polling_script .= "window.addEventListener('focus', function () { lotgdUpdateWindowFocusState(); });";
$polling_script .= "window.addEventListener('blur', function () { lotgd_windowHasFocus = false; });";
$polling_script .= "document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { lotgd_windowHasFocus = false; } else { lotgdUpdateWindowFocusState(); } });";
$polling_script .= "function lotgdShouldNotify() { lotgdUpdateWindowFocusState(); return !lotgd_windowHasFocus || document.visibilityState === 'hidden'; }";

// Add missing notification functions and clean AJAX polling implementation
$polling_script .= "
// Notification functions (previously in ajax_polling.js)
function lotgdShowNotification(title, message) {
    if (!('Notification' in window)) {
        return;
    }
    if (Notification.permission === 'granted') {
        new Notification(title, {body: message, icon: '{$favicon32}'});
    } else if (Notification.permission !== 'denied') {
        Notification.requestPermission().then(function (permission) {
            if (permission === 'granted') {
                new Notification(title, {body: message, icon: '{$favicon32}'});
            }
        });
    }
}

function lotgdMailNotify(lastId, count) {
    var baselineId = lotgd_lastUnreadMailId;
    var baselineCount = lotgd_lastUnreadMailCount;

    if ((lastId > baselineId || count > baselineCount) && lotgdShouldNotify()) {
        var msg = count === 1 ? 'You have 1 unread message' :
            'You have ' + count + ' unread messages';
        lotgdShowNotification('Unread game messages', msg);
    }
    lotgd_lastUnreadMailId = lastId;
    lotgd_lastUnreadMailCount = count;
}

function lotgdCommentNotify(count) {
    if (count > 0 && lotgdShouldNotify()) {
        var msg = count === 1 ? 'A new comment was posted' :
            count + ' new comments were posted';
        lotgdShowNotification('Unread comments', msg);
    }
}

// AJAX polling implementation
function getJaxonHandlers() {
    if (typeof Lotgd !== 'undefined'
        && Lotgd.Async && Lotgd.Async.Handler) {
        return Lotgd.Async.Handler;
    }

    if (typeof JaxonLotgd !== 'undefined'
        && JaxonLotgd.Async && JaxonLotgd.Async.Handler) {
        return JaxonLotgd.Async.Handler;
    }

    return null;
}

function pollForUpdates() {
    var handlers = getJaxonHandlers();
    if (handlers && handlers.Commentary && typeof handlers.Commentary.pollUpdates === 'function') {
        handlers.Commentary.pollUpdates(
            lotgd_comment_section || 'superuser',
            lotgd_lastCommentId || 0
        );
        return;
    }

    console.error('AJAX: pollUpdates unavailable in Jaxon client namespace');
}

// Start polling system
function startAjaxPolling() {
    console.log('AJAX: Starting polling every ' + (lotgd_poll_interval_ms / 1000) + ' seconds');
    
    // Regular polling
    setInterval(pollForUpdates, lotgd_poll_interval_ms);
}

// Initialize after page load
setTimeout(function() {
    if (typeof lotgd_poll_interval_ms !== 'undefined' && lotgd_poll_interval_ms > 0) {
        startAjaxPolling();
    }
}, 1000);

// Disable old polling system
window.set_poll_ajax = function() {};
window.clear_ajax = function() {};
window.initializePolling = function() {};
";

$polling_script .= "</script>";
$polling_script .= "<div id='notify'></div>";

$pre_headscript .= $polling_script;

// Load jQuery but skip the old ajax_polling.js
$pre_headscript .= "<script src='/async/js/jquery.min.js'></script>";

addnav("", "async/process.php");
