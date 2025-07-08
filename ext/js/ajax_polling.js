"use strict";

/**
 * AJAX polling helpers.
 *
 * This module schedules periodic checks for new mail, commentary updates
 * and session timeout status using Jaxon callbacks defined on the server.
 * Timing values are injected by {@code ext/ajax_maillink.php} as global
 * variables.
 */

var active_mail_interval;     // ID of the mail polling interval
var active_comment_interval;  // ID of the commentary polling interval
var active_timeout_interval;  // ID of the timeout polling interval
var lotgd_lastMailCount = 0;  // Track last unread mail count

/**
 * Display a Web Notification with the given title and message.
 * The browser permission is requested on demand.
 */
function lotgdShowNotification(title, message) {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'granted') {
        new Notification(title, {body: message, icon: '/favicon.ico'});
    } else if (Notification.permission !== 'denied') {
        Notification.requestPermission().then(function(permission) {
            if (permission === 'granted') {
                new Notification(title, {body: message, icon: '/favicon.ico'});
            }
        });
    }
}

/**
 * Handle updated unread mail count from the server.
 */
function lotgdMailNotify(count) {
    if (count > lotgd_lastMailCount && !document.hasFocus()) {
        var msg = count === 1 ? 'You have 1 unread message' :
            'You have ' + count + ' unread messages';
        lotgdShowNotification('Legend of the Green Dragon', msg);
    }
    lotgd_lastMailCount = count;
}

/**
 * Notify about new commentary posts when the page is unfocused.
 */
function lotgdCommentNotify(count) {
    if (count > 0 && !document.hasFocus()) {
        var msg = count === 1 ? 'A new comment was posted' :
            count + ' new comments were posted';
        lotgdShowNotification('Legend of the Green Dragon', msg);
    }
}

/**
 * Start periodic mail polling if the interval is configured.
 * Invokes the {@code jaxon_mail_status} callback every
 * {@code lotgd_mail_interval_ms} milliseconds.
 */
function set_mail_ajax() {
    if (typeof lotgd_mail_interval_ms === 'undefined') return;
    active_mail_interval = window.setInterval(function() {
        if (typeof jaxon_mail_status === 'function') {
            jaxon_mail_status(1);
        }
    }, lotgd_mail_interval_ms);
}

/**
 * Start periodic commentary refresh if the interval is configured.
 * Calls the {@code jaxon_commentary_refresh} callback with the
 * last known comment ID so only new posts are fetched.
 */
function set_comment_ajax() {
    if (typeof lotgd_comment_interval_ms === 'undefined') return;
    active_comment_interval = window.setInterval(function() {
        if (typeof jaxon_commentary_refresh === 'function') {
            jaxon_commentary_refresh(lotgd_comment_section, lotgd_lastCommentId);
        }
    }, lotgd_comment_interval_ms);
}

/**
 * Start periodic session timeout checks if the interval is configured.
 * Calls the {@code jaxon_timeout_status} callback to determine how
 * much time the user has left before being logged out.
 */
function set_timeout_ajax() {
    if (typeof lotgd_timeout_interval_ms === 'undefined') return;
    active_timeout_interval = window.setInterval(function() {
        if (typeof jaxon_timeout_status === 'function') {
            jaxon_timeout_status(1);
        }
    }, lotgd_timeout_interval_ms);
}

/**
 * Stop all polling intervals. Used after the page unloads to avoid
 * background polling when the user navigates away.
 */
function clear_ajax() {
    window.clearInterval(active_timeout_interval);
    window.clearInterval(active_mail_interval);
    window.clearInterval(active_comment_interval);
}

// Start polling once the DOM is ready using configuration variables
// supplied by the server. Commentary and timeout checks only run
// if the corresponding variables are present.
$(function() {
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }
    set_mail_ajax();
    if (typeof lotgd_comment_section !== 'undefined' && lotgd_comment_section) {
        set_comment_ajax();
    }
    if (typeof lotgd_timeout_delay_ms !== 'undefined') {
        window.setTimeout(set_timeout_ajax, lotgd_timeout_delay_ms);
    }
    if (typeof lotgd_clear_delay_ms !== 'undefined') {
        window.setTimeout(clear_ajax, lotgd_clear_delay_ms);
    }
});
