"use strict";

/**
 * AJAX polling helpers.
 *
 * This module schedules periodic checks for new mail, commentary updates
 * and session timeout status using Jaxon callbacks defined on the server.
 * Timing values are injected by {@code async/maillink.php} as global
 * variables.
 */

var active_mail_interval;     // ID of the mail polling interval (unused)
var active_comment_interval;  // ID of the commentary polling interval (unused)
var active_timeout_interval;  // ID of the timeout polling interval (unused)
var active_poll_interval;     // ID of the combined polling interval
var lotgd_lastUnreadMailId = 0;  // Track last unread mail count

/**
 * Display a Web Notification with the given title and message.
 * The browser permission is requested on demand.
 */
function lotgdShowNotification(title, message)
{
    if (!('Notification' in window)) {
        return;
    }
    if (Notification.permission === 'granted') {
        new Notification(title, {body: message, icon: '/favicon.ico'});
    } else if (Notification.permission !== 'denied') {
        Notification.requestPermission().then(function (permission) {
            if (permission === 'granted') {
                new Notification(title, {body: message, icon: '/favicon.ico'});
            }
        });
    }
}

/**
 * Handle updated unread mail count from the server.
 */
function lotgdMailNotify(count)
{
    if (lotgd_lastUnreadMailId === 0) {
        // First time we get the count, just store it
        lotgd_lastUnreadMailId = count;
        return;
    }
    if (count > lotgd_lastUnreadMailId && !document.hasFocus()) {
        var msg = count === 1 ? 'You have 1 unread message' :
            'You have ' + count + ' unread messages';
        lotgdShowNotification('Unread game messages', msg);
    }
    lotgd_lastUnreadMailId = count;
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
 * Start periodic mail polling if the interval is configured.
 * Invokes the {@code JaxonLotgd.Async.Handler.Mail.mailStatus} callback every
 * {@code lotgd_mail_interval_ms} milliseconds.
 */
function set_mail_ajax()
{
    if (typeof lotgd_mail_interval_ms === 'undefined') {
        return;
    }
    active_mail_interval = window.setInterval(function () {
        if (typeof JaxonLotgd !== 'undefined'
            && JaxonLotgd.Async && JaxonLotgd.Async.Handler
            && JaxonLotgd.Async.Handler.Mail
            && typeof JaxonLotgd.Async.Handler.Mail.mailStatus === 'function') {
            JaxonLotgd.Async.Handler.Mail.mailStatus(1);
        }
    }, lotgd_mail_interval_ms);
}

/**
 * Start periodic commentary refresh if the interval is configured.
 * Calls the {@code JaxonLotgd.Async.Handler.Commentary.commentaryRefresh} callback with the
 * last known comment ID so only new posts are fetched.
 */
function set_comment_ajax()
{
    if (typeof lotgd_comment_interval_ms === 'undefined') {
        return;
    }
    active_comment_interval = window.setInterval(function () {
        if (typeof JaxonLotgd !== 'undefined'
            && JaxonLotgd.Async && JaxonLotgd.Async.Handler
            && JaxonLotgd.Async.Handler.Commentary
            && typeof JaxonLotgd.Async.Handler.Commentary.commentaryRefresh === 'function') {
            JaxonLotgd.Async.Handler.Commentary.commentaryRefresh(lotgd_comment_section, lotgd_lastCommentId);
        }
    }, lotgd_comment_interval_ms);
}

/**
 * Start periodic session timeout checks if the interval is configured.
 * Calls the {@code JaxonLotgd.Async.Handler.Timeout.timeoutStatus} callback to determine how
 * much time the user has left before being logged out.
 */
function set_timeout_ajax()
{
    if (typeof lotgd_timeout_interval_ms === 'undefined') {
        return;
    }
    active_timeout_interval = window.setInterval(function () {
        if (typeof JaxonLotgd !== 'undefined'
            && JaxonLotgd.Async && JaxonLotgd.Async.Handler
            && JaxonLotgd.Async.Handler.Timeout
            && typeof JaxonLotgd.Async.Handler.Timeout.timeoutStatus === 'function') {
            JaxonLotgd.Async.Handler.Timeout.timeoutStatus(1);
        }
    }, lotgd_timeout_interval_ms);
}

/**
 * Start polling for all updates using a single server call.
 * Calls {@code JaxonLotgd.Async.Handler.Commentary.pollUpdates} with the current commentary
 * section and last comment id at the configured interval.
 */
function set_poll_ajax()
{
    if (typeof lotgd_poll_interval_ms === 'undefined') {
        return;
    }
    window.clearInterval(active_poll_interval); // Clear any existing interval
    active_poll_interval = window.setInterval(function () {
        if (typeof JaxonLotgd !== 'undefined'
            && JaxonLotgd.Async && JaxonLotgd.Async.Handler
            && JaxonLotgd.Async.Handler.Commentary
            && typeof JaxonLotgd.Async.Handler.Commentary.pollUpdates === 'function') {
            JaxonLotgd.Async.Handler.Commentary.pollUpdates(lotgd_comment_section, lotgd_lastCommentId);
        }
    }, lotgd_poll_interval_ms);
}

/**
 * Stop all polling intervals. Used after the page unloads to avoid
 * background polling when the user navigates away.
 */
function clear_ajax()
{
    if (typeof JaxonLotgd !== 'undefined'
        && JaxonLotgd.Async && JaxonLotgd.Async.Handler
        && JaxonLotgd.Async.Handler.Timeout
        && typeof JaxonLotgd.Async.Handler.Timeout.timeoutStatus === 'function') {
        JaxonLotgd.Async.Handler.Timeout.timeoutStatus(1);   // ensure final timeout message
    }
    window.clearInterval(active_timeout_interval);
    window.clearInterval(active_mail_interval);
    window.clearInterval(active_comment_interval);
    window.clearInterval(active_poll_interval);
}

// Start polling once the DOM is ready using configuration variables
// supplied by the server. Commentary and timeout checks only run
// if the corresponding variables are present.
$(function () {
    set_poll_ajax();
    if (typeof lotgd_clear_delay_ms !== 'undefined') {
        window.setTimeout(clear_ajax, lotgd_clear_delay_ms);
    }
    // Individual polling functions remain defined but are unused
    // set_mail_ajax();
    // set_comment_ajax();
    // set_timeout_ajax();
});
