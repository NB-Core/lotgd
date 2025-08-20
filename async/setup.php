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

// TEST: Try a direct AJAX call to see if the issue is with Jaxon or the server
$polling_script .= "
// Test direct AJAX call without Jaxon functions
function testDirectAjax() {
    console.log('DIRECT: Testing direct AJAX call...');
    
    fetch('/async/process.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'jxncls=Lotgd.Async.Handler.Commentary&jxnmthd=pollUpdates&jxnargs[]=superuser&jxnargs[]=20991005'
    })
    .then(response => response.text())
    .then(data => {
        console.log('DIRECT: Response:', data);
        try {
            const json = JSON.parse(data);
            console.log('DIRECT: JSON response:', json);
        } catch (e) {
            console.log('DIRECT: Non-JSON response:', data);
        }
    })
    .catch(error => {
        console.error('DIRECT: Error:', error);
    });
}

// Enhanced debugging for Jaxon calls
if (typeof jaxon !== 'undefined' && jaxon.request) {
    var originalJaxonRequest = jaxon.request;
    jaxon.request = function(config, options) {
        console.log('JAXON: Making request with config:', config, 'options:', options);
        console.log('JAXON: jaxon.config:', jaxon.config);
        try {
            var result = originalJaxonRequest.call(this, config, options);
            console.log('JAXON: Request result:', result);
            return result;
        } catch (e) {
            console.error('JAXON: Request failed:', e);
            return false;
        }
    };
}

// Inline polling solution
var active_poll_interval;
function startInlinePolling() {
    if (typeof lotgd_poll_interval_ms === 'undefined') {
        console.log('INLINE: lotgd_poll_interval_ms not defined');
        return;
    }
    
    console.log('INLINE: Starting polling with interval:', lotgd_poll_interval_ms);
    console.log('INLINE: Comment section:', lotgd_comment_section, 'Last ID:', lotgd_lastCommentId);
    
    if (active_poll_interval) {
        clearInterval(active_poll_interval);
    }
    
    active_poll_interval = setInterval(function() {
        // Try direct AJAX call instead of Jaxon functions
        testDirectAjax();
    }, lotgd_poll_interval_ms);
}

// Start polling after a short delay
setTimeout(function() {
    console.log('INLINE: Starting inline polling...');
    // Test direct AJAX immediately
    testDirectAjax();
    // Start interval polling
    startInlinePolling();
}, 3000);
";

$polling_script .= "</script>";
$polling_script .= "<div id='notify'></div>";

$pre_headscript .= $polling_script;

// Load jQuery and attempt external polling script (as backup)
$pre_headscript .= "<script src='/async/js/jquery.min.js'></script>"
    . "<script src='/async/js/ajax_polling.js' defer></script>";

addnav("", "async/process.php");

