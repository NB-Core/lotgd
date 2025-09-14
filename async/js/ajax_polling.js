"use strict";

/**
 * AJAX polling helpers.
 *
 * This module schedules periodic checks for new mail, commentary updates
 * and session timeout status using Jaxon callbacks defined on the server.
 * Timing values are injected by {@code async/maillink.php} as global
 * variables.
 */

console.log('DEBUG: ajax_polling.js loaded');

var active_mail_interval;     // ID of the mail polling interval (unused)
var active_comment_interval;  // ID of the commentary polling interval (unused)
var active_timeout_interval;  // ID of the timeout polling interval (unused)
var active_poll_interval;     // ID of the combined polling interval
var lotgd_lastUnreadMailId = 0;      // Track last mail ID
var lotgd_lastUnreadMailCount = 0;   // Track last unread mail count

/**
 * Display a Web Notification with the given title and message.
 * The browser permission is requested on demand.
 */
function lotgdShowNotification(title, message)
{
    if (!('Notification' in window)) {
        return;
    }
    const icon = document.querySelector('link[rel="icon"][sizes="32x32"]') ? .href || '/images/favicon/favicon-32x32.png';
    if (Notification.permission === 'granted') {
        new Notification(title, {body: message, icon: icon});
    } else if (Notification.permission !== 'denied') {
        Notification.requestPermission().then(function (permission) {
            if (permission === 'granted') {
                new Notification(title, {body: message, icon: icon});
            }
        });
    }
}

/**
 * Handle updated mail status from the server.
 */
function lotgdMailNotify(lastId, count)
{
    if (lotgd_lastUnreadMailId === 0) {
        lotgd_lastUnreadMailId = lastId;
        lotgd_lastUnreadMailCount = count;
        return;
    }
    if (lastId > lotgd_lastUnreadMailId && !document.hasFocus()) {
        var msg = count === 1 ? 'You have 1 unread message' :
            'You have ' + count + ' unread messages';
        lotgdShowNotification('Unread game messages', msg);
    }
    lotgd_lastUnreadMailId = lastId;
    lotgd_lastUnreadMailCount = count;
}

/**
 * Notify about new commentary posts when the page is unfocused.
 */
function lotgdCommentNotify(count)
{
    if (count > 0 && !document.hasFocus()) {
        var msg = count === 1 ? 'A new comment was posted' :
            count + ' new comments were posted';
        lotgdShowNotification('Unread comments', msg);
    }
}

/**
 * Get the correct handler object - Clean implementation.
 * Uses the clean Lotgd.Async.Handler structure with JaxonLotgd fallback.
 */
function getJaxonHandlers()
{
    // Primary: Use the clean structure
    if (typeof Lotgd !== 'undefined'
        && Lotgd.Async && Lotgd.Async.Handler) {
        console.log('DEBUG: Using Lotgd.Async.Handler');
        return Lotgd.Async.Handler;
    }

    // Fallback: Legacy JaxonLotgd structure
    if (typeof JaxonLotgd !== 'undefined'
        && JaxonLotgd.Async && JaxonLotgd.Async.Handler) {
        console.log('DEBUG: Using JaxonLotgd.Async.Handler');
        return JaxonLotgd.Async.Handler;
    }

    console.log('DEBUG: No handlers found');
    return null;
}

/**
 * Start periodic mail polling if the interval is configured.
 */
function set_mail_ajax()
{
    if (typeof lotgd_mail_interval_ms === 'undefined') {
        console.log('DEBUG: lotgd_mail_interval_ms not defined, skipping mail polling');
        return;
    }
    console.log('DEBUG: Starting mail polling with interval:', lotgd_mail_interval_ms);
    active_mail_interval = window.setInterval(function () {
        var handlers = getJaxonHandlers();
        if (handlers && handlers.Mail && typeof handlers.Mail.mailStatus === 'function') {
            console.log('DEBUG: Calling Mail.mailStatus');
            handlers.Mail.mailStatus(1);
        }
    }, lotgd_mail_interval_ms);
}

/**
 * Start periodic commentary refresh if the interval is configured.
 */
function set_comment_ajax()
{
    if (typeof lotgd_comment_interval_ms === 'undefined') {
        console.log('DEBUG: lotgd_comment_interval_ms not defined, skipping comment polling');
        return;
    }
    console.log('DEBUG: Starting comment polling with interval:', lotgd_comment_interval_ms);
    active_comment_interval = window.setInterval(function () {
        var handlers = getJaxonHandlers();
        if (handlers && handlers.Commentary && typeof handlers.Commentary.commentaryRefresh === 'function') {
            console.log('DEBUG: Calling Commentary.commentaryRefresh');
            handlers.Commentary.commentaryRefresh(lotgd_comment_section, lotgd_lastCommentId);
        }
    }, lotgd_comment_interval_ms);
}

/**
 * Start periodic session timeout checks if the interval is configured.
 */
function set_timeout_ajax()
{
    if (typeof lotgd_timeout_interval_ms === 'undefined') {
        console.log('DEBUG: lotgd_timeout_interval_ms not defined, skipping timeout polling');
        return;
    }
    console.log('DEBUG: Starting timeout polling with interval:', lotgd_timeout_interval_ms);
    active_timeout_interval = window.setInterval(function () {
        var handlers = getJaxonHandlers();
        if (handlers && handlers.Timeout && typeof handlers.Timeout.timeoutStatus === 'function') {
            console.log('DEBUG: Calling Timeout.timeoutStatus');
            handlers.Timeout.timeoutStatus(1);
        }
    }, lotgd_timeout_interval_ms);
}

/**
 * Start polling for all updates using a single server call.
 */
function set_poll_ajax()
{
    if (typeof lotgd_poll_interval_ms === 'undefined') {
        console.log('DEBUG: lotgd_poll_interval_ms not defined, skipping combined polling');
        return;
    }
    console.log('DEBUG: Starting combined polling with interval:', lotgd_poll_interval_ms);
    window.clearInterval(active_poll_interval); // Clear any existing interval
    active_poll_interval = window.setInterval(function () {
        var handlers = getJaxonHandlers();
        if (handlers && handlers.Commentary && typeof handlers.Commentary.pollUpdates === 'function') {
            console.log('DEBUG: Calling Commentary.pollUpdates');
            handlers.Commentary.pollUpdates(lotgd_comment_section, lotgd_lastCommentId);
        } else {
            console.log('DEBUG: pollUpdates not available, handlers:', handlers);
        }
    }, lotgd_poll_interval_ms);
}

/**
 * Stop all polling intervals. Used after the page unloads to avoid
 * background polling when the user navigates away.
 */
function clear_ajax()
{
    console.log('DEBUG: Clearing all polling intervals');
    var handlers = getJaxonHandlers();
    if (handlers && handlers.Timeout && typeof handlers.Timeout.timeoutStatus === 'function') {
        handlers.Timeout.timeoutStatus(1);   // ensure final timeout message
    }
    window.clearInterval(active_timeout_interval);
    window.clearInterval(active_mail_interval);
    window.clearInterval(active_comment_interval);
    window.clearInterval(active_poll_interval);
}

/**
 * Initialize polling once Jaxon handlers are ready
 */
function initializePolling()
{
    console.log('DEBUG: initializePolling called');
    set_poll_ajax();
    if (typeof lotgd_clear_delay_ms !== 'undefined') {
        console.log('DEBUG: Setting clear timeout for:', lotgd_clear_delay_ms);
        window.setTimeout(clear_ajax, lotgd_clear_delay_ms);
    }
}

/**
 * Start polling with clean handler detection
 */
function startPolling()
{
    console.log('DEBUG: startPolling called');
    // Check if ready flag is set
    if (window.JaxonLotgdReady === true) {
        console.log('DEBUG: JaxonLotgdReady is true, initializing polling');
        initializePolling();
        return;
    }

    // Check if handlers are available
    if (getJaxonHandlers() !== null) {
        console.log('DEBUG: Handlers available, initializing polling');
        initializePolling();
        return;
    }

    console.log('DEBUG: Not ready yet, will retry in 250ms');
    // Wait and retry
    setTimeout(startPolling, 250);
}

// Start polling once DOM is ready and Jaxon handlers are available
console.log('DEBUG: Setting up DOM ready listeners, typeof $ =', typeof $);

// Try both jQuery and vanilla JS approaches
if (typeof $ !== 'undefined') {
    console.log('DEBUG: Using jQuery for DOM ready');
    $(function () {
        console.log('DEBUG: jQuery DOM ready fired');
        // Listen for the JaxonLotgdReady event if available
        if (typeof window.addEventListener === 'function') {
            window.addEventListener('JaxonLotgdReady', function () {
                console.log('DEBUG: JaxonLotgdReady event received');
                initializePolling();
            }, { once: true });
        }

        // Also use the polling fallback method
        startPolling();
    });
} else {
    console.log('DEBUG: jQuery not available, using vanilla JS DOM ready');
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            console.log('DEBUG: Vanilla DOM ready fired');
            startPolling();
        });
    } else {
        console.log('DEBUG: DOM already ready, starting immediately');
        startPolling();
    }
}
