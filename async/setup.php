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

// INLINE POLLING SOLUTION with enhanced debugging
$polling_script .= "
// Enhanced debugging for Jaxon calls
if (typeof jaxon !== 'undefined' && jaxon.request) {
    var originalJaxonRequest = jaxon.request;
    jaxon.request = function(config, options) {
        console.log('JAXON: Making request with config:', config, 'options:', options);
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
        if (typeof Lotgd !== 'undefined' 
            && Lotgd.Async && Lotgd.Async.Handler 
            && Lotgd.Async.Handler.Commentary
            && typeof Lotgd.Async.Handler.Commentary.pollUpdates === 'function') {
            
            console.log('INLINE: Calling pollUpdates with section:', lotgd_comment_section, 'lastId:', lotgd_lastCommentId);
            console.log('INLINE: Function source:', Lotgd.Async.Handler.Commentary.pollUpdates.toString());
            
            // Call pollUpdates and track the response
            try {
                var response = Lotgd.Async.Handler.Commentary.pollUpdates(lotgd_comment_section || '', lotgd_lastCommentId || 0);
                console.log('INLINE: pollUpdates response:', response);
                console.log('INLINE: Response type:', typeof response);
            } catch (e) {
                console.error('INLINE: pollUpdates threw error:', e);
            }
            
        } else {
            console.log('INLINE: Handlers not ready yet');
        }
    }, lotgd_poll_interval_ms);
}

// Test individual methods to see which ones work
function testIndividualMethods() {
    console.log('INLINE: Testing individual methods...');
    
    // Test Mail.mailStatus
    try {
        if (Lotgd.Async.Handler.Mail && typeof Lotgd.Async.Handler.Mail.mailStatus === 'function') {
            console.log('INLINE: Testing Mail.mailStatus...');
            var mailResult = Lotgd.Async.Handler.Mail.mailStatus(true);
            console.log('INLINE: Mail.mailStatus result:', mailResult);
        }
    } catch (e) {
        console.error('INLINE: Mail.mailStatus error:', e);
    }
    
    // Test Timeout.timeoutStatus
    try {
        if (Lotgd.Async.Handler.Timeout && typeof Lotgd.Async.Handler.Timeout.timeoutStatus === 'function') {
            console.log('INLINE: Testing Timeout.timeoutStatus...');
            var timeoutResult = Lotgd.Async.Handler.Timeout.timeoutStatus(true);
            console.log('INLINE: Timeout.timeoutStatus result:', timeoutResult);
        }
    } catch (e) {
        console.error('INLINE: Timeout.timeoutStatus error:', e);
    }
    
    // Test Commentary.commentaryRefresh
    try {
        if (Lotgd.Async.Handler.Commentary && typeof Lotgd.Async.Handler.Commentary.commentaryRefresh === 'function') {
            console.log('INLINE: Testing Commentary.commentaryRefresh...');
            var commentResult = Lotgd.Async.Handler.Commentary.commentaryRefresh(lotgd_comment_section || '', lotgd_lastCommentId || 0);
            console.log('INLINE: Commentary.commentaryRefresh result:', commentResult);
        }
    } catch (e) {
        console.error('INLINE: Commentary.commentaryRefresh error:', e);
    }
}

// Start polling after a short delay
setTimeout(function() {
    console.log('INLINE: Starting inline polling...');
    startInlinePolling();
    
    // Test individual methods after 5 seconds
    setTimeout(testIndividualMethods, 5000);
}, 3000);
";

$polling_script .= "</script>";
$polling_script .= "<div id='notify'></div>";

$pre_headscript .= $polling_script;

// Load jQuery and attempt external polling script (as backup)
$pre_headscript .= "<script src='/async/js/jquery.min.js'></script>"
    . "<script src='/async/js/ajax_polling.js' defer></script>";

addnav("", "async/process.php");

