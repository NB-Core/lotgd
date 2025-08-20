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

$polling_script .= "console.log('Polling variables set:', {poll_interval: lotgd_poll_interval_ms, comment_section: lotgd_comment_section, lastCommentId: lotgd_lastCommentId, clear_delay: lotgd_clear_delay_ms});";

// Clean, working direct AJAX polling solution
$polling_script .= "
// Clean direct AJAX polling implementation
function pollUpdatesDirectly() {
    console.log('POLLING: Starting poll request...');
    
    const formData = new URLSearchParams();
    formData.append('jxncls', 'Lotgd.Async.Handler.Commentary');
    formData.append('jxnmthd', 'pollUpdates');
    formData.append('jxnargs[0]', lotgd_comment_section || 'superuser');
    formData.append('jxnargs[1]', String(lotgd_lastCommentId || 0));
    formData.append('jxnr', Math.random().toString().substring(2));
    
    fetch('/async/process.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData.toString()
    })
    .then(response => {
        console.log('POLLING: Response status:', response.status);
        if (!response.ok) {
            return response.text().then(text => {
                console.error('POLLING: Server error response:', text.substring(0, 500));
                throw new Error('HTTP ' + response.status + ': ' + text.substring(0, 200));
            });
        }
        return response.text();
    })
    .then(data => {
        try {
            const json = JSON.parse(data);
            console.log('POLLING: Success! Processing', json.jxnobj?.length || 0, 'commands');
            
            // Process Jaxon response commands
            if (json.jxnobj && Array.isArray(json.jxnobj)) {
                let updates = {mail: false, notify: false, comments: false};
                
                json.jxnobj.forEach(cmd => {
                    if (cmd.id && cmd.prop && cmd.data !== undefined) {
                        const element = document.getElementById(cmd.id);
                        if (element && cmd.prop === 'innerHTML') {
                            element.innerHTML = cmd.data;
                            console.log('POLLING: Updated', cmd.id);
                            
                            if (cmd.id === 'maillink') updates.mail = true;
                            if (cmd.id === 'notify') updates.notify = true;
                            if (cmd.id.includes('comment')) updates.comments = true;
                        }
                    }
                    
                    // Execute script commands (e.g., to update comment IDs)
                    if (cmd.cmd === 'scr' && cmd.data) {
                        try {
                            eval(cmd.data);
                            console.log('POLLING: Executed:', cmd.data);
                        } catch (e) {
                            console.error('POLLING: Script error:', e);
                        }
                    }
                });
                
                console.log('POLLING: Updates -', updates);
            }
        } catch (e) {
            console.error('POLLING: Parse error:', e);
            console.log('POLLING: Raw response:', data.substring(0, 200));
        }
    })
    .catch(error => {
        console.error('POLLING: Request failed:', error.message);
    });
}

// Simple polling system
var lotgd_polling_interval;
function startPolling() {
    if (typeof lotgd_poll_interval_ms === 'undefined' || lotgd_poll_interval_ms <= 0) {
        console.log('POLLING: Invalid interval, not starting');
        return;
    }
    
    console.log('POLLING: Starting with interval', lotgd_poll_interval_ms, 'ms');
    
    // Clear any existing interval
    if (lotgd_polling_interval) {
        clearInterval(lotgd_polling_interval);
    }
    
    // Start polling
    lotgd_polling_interval = setInterval(pollUpdatesDirectly, lotgd_poll_interval_ms);
}

// Initialize after a delay
setTimeout(function() {
    console.log('POLLING: Initializing system...');
    // Test once immediately
    pollUpdatesDirectly();
    // Start regular polling
    startPolling();
}, 2000);

// Disable the old ajax_polling.js by overriding its functions
window.set_poll_ajax = function() { console.log('OLD POLLING: Disabled'); };
window.clear_ajax = function() { console.log('OLD POLLING: Disabled'); };
window.initializePolling = function() { console.log('OLD POLLING: Disabled'); };
";

$polling_script .= "</script>";
$polling_script .= "<div id='notify'></div>";

$pre_headscript .= $polling_script;

// Load jQuery but DON'T load the old ajax_polling.js that's interfering
$pre_headscript .= "<script src='/async/js/jquery.min.js'></script>";

addnav("", "async/process.php");

