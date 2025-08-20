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
$polling_script .= "var lotgd_timeout_delay_ms = " . ((getsetting('LOGINTIMEOUT', 900) - $start_timeout_show_seconds) * 1000) . ";";
$polling_script .= "var lotgd_clear_delay_ms = " . ((getsetting('LOGINTIMEOUT', 900) - $clear_script_execution_seconds) * 1000) . ";";
$polling_script .= "console.log('Polling variables set:', {poll_interval: lotgd_poll_interval_ms, comment_section: lotgd_comment_section, lastCommentId: lotgd_lastCommentId});";

// Fixed direct AJAX call with proper Jaxon parameter format
$polling_script .= "
// Test direct AJAX call with corrected parameter format
function testDirectAjax() {
    console.log('DIRECT: Testing direct AJAX call...');
    
    // Build Jaxon-compatible POST data
    const formData = new URLSearchParams();
    formData.append('jxncls', 'Lotgd.Async.Handler.Commentary');
    formData.append('jxnmthd', 'pollUpdates');
    formData.append('jxnargs[0]', lotgd_comment_section || 'superuser');
    formData.append('jxnargs[1]', lotgd_lastCommentId || 0);
    
    // Add required Jaxon fields
    formData.append('jxnr', Math.random().toString().substring(2));
    
    console.log('DIRECT: Sending data:', {
        section: lotgd_comment_section,
        lastId: lotgd_lastCommentId,
        formData: formData.toString()
    });
    
    fetch('/async/process.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData.toString()
    })
    .then(response => {
        console.log('DIRECT: Response status:', response.status);
        return response.text();
    })
    .then(data => {
        console.log('DIRECT: Raw response:', data);
        try {
            const json = JSON.parse(data);
            console.log('DIRECT: JSON response:', json);
            
            // Process the response like Jaxon would
            if (json.jxnobj && Array.isArray(json.jxnobj)) {
                json.jxnobj.forEach(cmd => {
                    console.log('DIRECT: Processing command:', cmd);
                    if (cmd.id && cmd.prop && cmd.data !== undefined) {
                        const element = document.getElementById(cmd.id);
                        if (element) {
                            if (cmd.prop === 'innerHTML') {
                                element.innerHTML = cmd.data;
                                console.log('DIRECT: Updated', cmd.id, 'with:', cmd.data);
                            }
                        }
                    }
                });
            }
        } catch (e) {
            console.error('DIRECT: Failed to parse JSON:', e);
            console.log('DIRECT: Non-JSON response (might be error page):', data.substring(0, 500));
        }
    })
    .catch(error => {
        console.error('DIRECT: Network error:', error);
    });
}

// Also test individual methods
function testIndividualMethods() {
    // Test Mail status
    console.log('DIRECT: Testing Mail.mailStatus...');
    const mailData = new URLSearchParams();
    mailData.append('jxncls', 'Lotgd.Async.Handler.Mail');
    mailData.append('jxnmthd', 'mailStatus');
    mailData.append('jxnargs[0]', 'true');
    mailData.append('jxnr', Math.random().toString().substring(2));
    
    fetch('/async/process.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: mailData.toString()
    })
    .then(response => response.text())
    .then(data => {
        try {
            const json = JSON.parse(data);
            console.log('DIRECT: Mail response:', json);
        } catch (e) {
            console.log('DIRECT: Mail response (non-JSON):', data.substring(0, 200));
        }
    });
}

// Inline polling solution using direct AJAX
var active_poll_interval;
function startInlinePolling() {
    if (typeof lotgd_poll_interval_ms === 'undefined') {
        console.log('INLINE: lotgd_poll_interval_ms not defined');
        return;
    }
    
    console.log('INLINE: Starting direct AJAX polling with interval:', lotgd_poll_interval_ms);
    console.log('INLINE: Comment section:', lotgd_comment_section, 'Last ID:', lotgd_lastCommentId);
    
    if (active_poll_interval) {
        clearInterval(active_poll_interval);
    }
    
    active_poll_interval = setInterval(function() {
        testDirectAjax();
    }, lotgd_poll_interval_ms);
}

// Start polling after a short delay
setTimeout(function() {
    console.log('INLINE: Starting inline polling...');
    // Test direct AJAX immediately
    testDirectAjax();
    // Test individual methods
    setTimeout(testIndividualMethods, 2000);
    // Start interval polling
    setTimeout(startInlinePolling, 4000);
}, 3000);
";

$polling_script .= "</script>";
$polling_script .= "<div id='notify'></div>";

$pre_headscript .= $polling_script;

// Load jQuery and attempt external polling script (as backup)
$pre_headscript .= "<script src='/async/js/jquery.min.js'></script>"
    . "<script src='/async/js/ajax_polling.js' defer></script>";

addnav("", "async/process.php");

