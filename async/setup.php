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

// Working direct AJAX polling solution
$polling_script .= "
// Working direct AJAX polling - calls the combined pollUpdates method
function pollUpdatesDirectly() {
    console.log('DIRECT: Calling pollUpdates...');
    
    const formData = new URLSearchParams();
    formData.append('jxncls', 'Lotgd.Async.Handler.Commentary');
    formData.append('jxnmthd', 'pollUpdates');
    formData.append('jxnargs[0]', lotgd_comment_section || 'superuser');
    formData.append('jxnargs[1]', String(lotgd_lastCommentId || 0));
    formData.append('jxnr', Math.random().toString().substring(2));
    
    console.log('DIRECT: Polling with section:', lotgd_comment_section, 'lastId:', lotgd_lastCommentId);
    
    fetch('/async/process.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData.toString()
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        return response.text();
    })
    .then(data => {
        try {
            const json = JSON.parse(data);
            console.log('DIRECT: Success! Response:', json);
            
            // Process the Jaxon response commands
            if (json.jxnobj && Array.isArray(json.jxnobj)) {
                json.jxnobj.forEach(cmd => {
                    if (cmd.id && cmd.prop && cmd.data !== undefined) {
                        const element = document.getElementById(cmd.id);
                        if (element) {
                            if (cmd.prop === 'innerHTML') {
                                element.innerHTML = cmd.data;
                                console.log('DIRECT: Updated', cmd.id);
                            }
                        }
                    }
                    
                    // Handle script commands for comment ID updates
                    if (cmd.cmd === 'scr' && cmd.data) {
                        try {
                            eval(cmd.data);
                            console.log('DIRECT: Executed script:', cmd.data);
                        } catch (e) {
                            console.error('DIRECT: Script error:', e);
                        }
                    }
                });
                
                // Count response types
                const mailCount = json.jxnobj.filter(cmd => cmd.id === 'maillink').length;
                const notifyCount = json.jxnobj.filter(cmd => cmd.id === 'notify').length;
                const commentCount = json.jxnobj.filter(cmd => cmd.id && cmd.id.includes('comment')).length;
                console.log('DIRECT: Response contains - Mail:', mailCount, 'Notify:', notifyCount, 'Comments:', commentCount);
            }
        } catch (e) {
            console.error('DIRECT: JSON parse error:', e);
            console.log('DIRECT: Raw response:', data.substring(0, 500));
        }
    })
    .catch(error => {
        console.error('DIRECT: Request failed:', error);
    });
}

// Standalone polling system using direct AJAX
var polling_interval;
function startDirectPolling() {
    if (typeof lotgd_poll_interval_ms === 'undefined') {
        console.log('DIRECT: No polling interval defined');
        return;
    }
    
    console.log('DIRECT: Starting polling every', lotgd_poll_interval_ms, 'ms');
    
    // Clear any existing interval
    if (polling_interval) {
        clearInterval(polling_interval);
    }
    
    // Start regular polling
    polling_interval = setInterval(pollUpdatesDirectly, lotgd_poll_interval_ms);
}

// Initialize polling
setTimeout(function() {
    console.log('DIRECT: Starting direct polling system...');
    // Test immediately
    pollUpdatesDirectly();
    // Start regular polling
    setTimeout(startDirectPolling, 5000);
}, 3000);
";

$polling_script .= "</script>";
$polling_script .= "<div id='notify'></div>";

$pre_headscript .= $polling_script;

// Load jQuery and attempt external polling script (as backup)
$pre_headscript .= "<script src='/async/js/jquery.min.js'></script>"
    . "<script src='/async/js/ajax_polling.js' defer></script>";

addnav("", "async/process.php");

