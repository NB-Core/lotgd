<?php

declare(strict_types=1);

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

$polling_script = "<script>";
$polling_script .= "var lotgd_comment_section = " . json_encode($session['last_comment_section'] ?? '') . ";";
$polling_script .= "var lotgd_lastCommentId = " . (int)($session['lastcommentid'] ?? 0) . ";";
$polling_script .= "var lotgd_poll_interval_ms = " . ($check_mail_timeout_seconds * 1000) . ";";

// Fix timeout calculations to prevent negative values
$login_timeout = getsetting('LOGINTIMEOUT', 900);
$start_timeout_show = max(1, $start_timeout_show_seconds ?? 300);
$clear_script_execution = max($login_timeout, $clear_script_execution_seconds ?? -1);

$polling_script .= "var lotgd_timeout_delay_ms = " . (($login_timeout - $start_timeout_show) * 1000) . ";";
// Only set clear delay if it's positive and reasonable
if ($clear_script_execution > 0 && $clear_script_execution < $login_timeout) {
    $polling_script .= "var lotgd_clear_delay_ms = " . (($login_timeout - $clear_script_execution) * 1000) . ";";
} else {
    $polling_script .= "var lotgd_clear_delay_ms = null;"; // Disable auto-clear
}

$polling_script .= "console.log('AJAX polling initialized:', {interval: lotgd_poll_interval_ms + 'ms', section: lotgd_comment_section});";

// Clean AJAX polling implementation
$polling_script .= "
// AJAX polling implementation
function pollForUpdates() {
    const formData = new URLSearchParams();
    formData.append('jxncls', 'Lotgd.Async.Handler.Commentary');
    formData.append('jxnmthd', 'pollUpdates');
    formData.append('jxnargs[0]', lotgd_comment_section || 'superuser');
    formData.append('jxnargs[1]', String(lotgd_lastCommentId || 0));
    formData.append('jxnr', Math.random().toString().substring(2));
    
    fetch('/async/process.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData.toString()
    })
    .then(response => response.text().then(data => ({status: response.status, data: data})))
    .then(result => {
        if (result.status === 200 && !result.data.includes('Application Error')) {
            try {
                const json = JSON.parse(result.data);
                if (json.jxnobj && Array.isArray(json.jxnobj)) {
                    let hasUpdates = false;
                    
                    json.jxnobj.forEach(cmd => {
                        if (cmd.id && cmd.prop && cmd.data !== undefined) {
                            const element = document.getElementById(cmd.id);
                            if (element && cmd.prop === 'innerHTML') {
                                if (cmd.cmd === 'ap') { // append
                                    element.innerHTML += cmd.data;
                                } else { // assign
                                    element.innerHTML = cmd.data;
                                }
                                hasUpdates = true;
                            }
                        }
                        if (cmd.cmd === 'js' && cmd.data) {
                            try {
                                eval(cmd.data);
                            } catch (e) {
                                console.error('AJAX: Script execution error:', e);
                            }
                        }
                    });
                    
                    if (hasUpdates) {
                        console.log('AJAX: Updates applied (' + json.jxnobj.length + ' commands)');
                    }
                }
            } catch (e) {
                console.error('AJAX: Response parsing error:', e);
            }
        } else if (result.status !== 200) {
            console.error('AJAX: Server error (HTTP ' + result.status + ')');
        }
    })
    .catch(error => {
        console.error('AJAX: Network error:', error);
    });
}

// Start polling system
function startAjaxPolling() {
    console.log('AJAX: Starting polling every ' + (lotgd_poll_interval_ms / 1000) + ' seconds');
    
    // Initial poll
    pollForUpdates();
    
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

