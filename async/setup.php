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

// Working AJAX polling solution with fixed JavaScript
$polling_script .= "
// Test the simple method first
function testSimpleMethod() {
    console.log('TEST: Calling simple test method...');
    
    const formData = new URLSearchParams();
    formData.append('jxncls', 'Lotgd.Async.Handler.Commentary');
    formData.append('jxnmthd', 'test');
    formData.append('jxnr', Math.random().toString().substring(2));
    
    fetch('/async/process.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData.toString()
    })
    .then(response => {
        console.log('TEST: Simple method response status:', response.status);
        return response.text().then(data => ({status: response.status, data: data}));
    })
    .then(result => {
        if (result.status === 200) {
            console.log('TEST: Simple method works! Response:', result.data);
            try {
                const json = JSON.parse(result.data);
                console.log('TEST: JSON response:', json);
                
                // Process the response
                if (json.jxnobj && Array.isArray(json.jxnobj)) {
                    json.jxnobj.forEach(cmd => {
                        if (cmd.id && cmd.prop && cmd.data !== undefined) {
                            const element = document.getElementById(cmd.id);
                            if (element && cmd.prop === 'innerHTML') {
                                element.innerHTML = cmd.data;
                                console.log('TEST: Updated', cmd.id, 'with:', cmd.data);
                            }
                        }
                    });
                }
                
                // If simple method works, try pollUpdates
                setTimeout(testPollingMethod, 1000);
            } catch (e) {
                console.log('TEST: Non-JSON response:', result.data.substring(0, 200));
            }
        } else {
            console.error('TEST: Simple method failed with status:', result.status);
            console.log('TEST: Error response:', result.data.substring(0, 500));
        }
    })
    .catch(error => {
        console.error('TEST: Simple method network error:', error);
    });
}

// Test the improved pollUpdates method
function testPollingMethod() {
    console.log('POLLING: Testing improved pollUpdates...');
    
    const formData = new URLSearchParams();
    formData.append('jxncls', 'Lotgd.Async.Handler.Commentary');
    formData.append('jxnmthd', 'pollUpdates');
    formData.append('jxnargs[0]', lotgd_comment_section || 'superuser');
    formData.append('jxnargs[1]', String(lotgd_lastCommentId || 0));
    formData.append('jxnr', Math.random().toString().substring(2));
    
    console.log('POLLING: Sending:', formData.toString());
    
    fetch('/async/process.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData.toString()
    })
    .then(response => {
        console.log('POLLING: Response status:', response.status);
        return response.text().then(data => ({status: response.status, data: data}));
    })
    .then(result => {
        if (result.data.includes('Application Error')) {
            console.error('POLLING: Still getting server error:', result.data.substring(0, 500));
        } else if (result.status === 200) {
            console.log('POLLING: Success! Response length:', result.data.length);
            try {
                const json = JSON.parse(result.data);
                console.log('POLLING: JSON response with', json.jxnobj?.length || 0, 'commands');
                
                // Process the response
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
                        if (cmd.cmd === 'scr' && cmd.data) {
                            try {
                                eval(cmd.data);
                                console.log('POLLING: Executed script:', cmd.data);
                            } catch (e) {
                                console.error('POLLING: Script error:', e);
                            }
                        }
                    });
                    
                    console.log('POLLING: Updates -', updates);
                }
                
                // If successful, start regular polling
                startRegularPolling();
            } catch (e) {
                console.error('POLLING: JSON parse error:', e);
                console.log('POLLING: Raw response:', result.data.substring(0, 300));
            }
        } else {
            console.error('POLLING: Failed with status:', result.status);
            console.log('POLLING: Error response:', result.data.substring(0, 500));
        }
    })
    .catch(error => {
        console.error('POLLING: Network error:', error);
    });
}

// Start regular polling once we know it works
function startRegularPolling() {
    console.log('POLLING: Starting regular polling every', lotgd_poll_interval_ms, 'ms');
    
    setInterval(function() {
        console.log('POLLING: Regular poll...');
        testPollingMethod();
    }, lotgd_poll_interval_ms);
}

// Initialize with testing
setTimeout(function() {
    console.log('POLLING: Initializing with step-by-step testing...');
    testSimpleMethod();
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

