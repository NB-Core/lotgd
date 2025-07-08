// JavaScript used for AJAX mail and timeout checks
// This file was extracted from ext/ajax_maillink.php
var active_mail_interval;
var active_comment_interval;
var active_timeout_interval;

function set_mail_ajax() {
    if (typeof lotgd_mail_interval_ms === 'undefined') return;
    active_mail_interval = window.setInterval(function() {
        if (typeof jaxon_mail_status === 'function') {
            jaxon_mail_status(1);
        }
    }, lotgd_mail_interval_ms);
}

function set_comment_ajax() {
    if (typeof lotgd_comment_interval_ms === 'undefined') return;
    active_comment_interval = window.setInterval(function() {
        if (typeof jaxon_commentary_refresh === 'function') {
            jaxon_commentary_refresh(lotgd_comment_section, lotgd_lastCommentId);
        }
    }, lotgd_comment_interval_ms);
}

function set_timeout_ajax() {
    if (typeof lotgd_timeout_interval_ms === 'undefined') return;
    active_timeout_interval = window.setInterval(function() {
        if (typeof jaxon_timeout_status === 'function') {
            jaxon_timeout_status(1);
        }
    }, lotgd_timeout_interval_ms);
}

function clear_ajax() {
    window.clearInterval(active_timeout_interval);
    window.clearInterval(active_mail_interval);
    window.clearInterval(active_comment_interval);
}

$(window).ready(function() {
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
