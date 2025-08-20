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

// Fixed parameter format for Jaxon
$polling_script .= "
// Test different parameter formats to find what works
function testParameterFormats() {
    console.log('TESTING: Trying different parameter formats...');
    
    // Format 1: Standard jxnargs array format
    const formData1 = new URLSearchParams();
    formData1.append('jxncls', 'Lotgd.Async.Handler.Commentary');
    formData1.append('jxnmthd', 'pollUpdates');
    formData1.append('jxnargs[0]', lotgd_comment_section || 'superuser');
    formData1.append('jxnargs[1]', String(lotgd_lastCommentId || 0));
    formData1.append('jxnr', Math.random().toString().substring(2));
    
    // Format 2: Different argument encoding
    const formData2 = new URLSearchParams();
    formData2.append('jxncls', 'Lotgd.Async.Handler.Commentary');
    formData2.append('jxnmthd', 'pollUpdates');
    formData2.append('jxnargs[]', lotgd_comment_section || 'superuser');
    formData2.append('jxnargs[]', String(lotgd_lastCommentId || 0));
    formData2.append('jxnr', Math.random().toString().substring(2));
    
    // Format 3: JSON parameter encoding
    const formData3 = new URLSearchParams();
    formData3.append('jxncls', 'Lotgd.Async.Handler.Commentary');
    formData3.append('jxnmthd', 'pollUpdates');
    formData3.append('jxnargs', JSON.stringify([lotgd_comment_section || 'superuser', lotgd_lastCommentId || 0]));
    formData3.append('jxnr', Math.random().toString().substring(2));
    
    console.log('TESTING: Format 1 (indexed):', formData1.toString());
    console.log('TESTING: Format 2 (array):', formData2.toString());
    console.log('TESTING: Format 3 (JSON):', formData3.toString());
    
    // Try format 1 first
    fetch('/async/process.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData1.toString()
    })
    .then(response => {
        console.log('TESTING: Format 1 response status:', response.status);
        return response.text();
    })
    .then(data => {
        if (data.includes('Application Error')) {
            console.log('TESTING: Format 1 failed, trying format 2...');
            
            return fetch('/async/process.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData2.toString()
            });
        } else {
            console.log('TESTING: Format 1 worked!');
            try {
                const json = JSON.parse(data);
                console.log('TESTING: Success with format 1:', json);
            } catch (e) {
                console.log('TESTING: Format 1 non-JSON response:', data.substring(0, 200));
            }
            return null;
        }
    })
    .then(response => {
        if (!response) return null;
        
        console.log('TESTING: Format 2 response status:', response.status);
        return response.text();
    })
    .then(data => {
        if (!data) return null;
        
        if (data.includes('Application Error')) {
            console.log('TESTING: Format 2 failed, trying format 3...');
            
            return fetch('/async/process.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: formData3.toString()
            });
        } else {
            console.log('TESTING: Format 2 worked!');
            try {
                const json = JSON.parse(data);
                console.log('TESTING: Success with format 2:', json);
            } catch (e) {
                console.log('TESTING: Format 2 non-JSON response:', data.substring(0, 200));
            }
            return null;
        }
    })
    .then(response => {
        if (!response) return;
        
        console.log('TESTING: Format 3 response status:', response.status);
        return response.text();
    })
    .then(data => {
        if (!data) return;
        
        console.log('TESTING: Format 3 result:', data.substring(0, 200));
        try {
            const json = JSON.parse(data);
            console.log('TESTING: Success with format 3:', json);
        } catch (e) {
            console.log('TESTING: All formats failed');
        }
    })
    .catch(error => {
        console.error('TESTING: Network error:', error);
    });
}

// Initialize with testing
setTimeout(function() {
    console.log('POLLING: Starting parameter format testing...');
    testParameterFormats();
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

