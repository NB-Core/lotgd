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
