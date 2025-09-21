// ==UserScript==
// @name         LotGD Jaxon Integration
// @namespace    https://github.com/NB-Core/
// @version      1.0
// @description  Integrate Jaxon AJAX library with LotGD
// @author       NB-Core
// @match        *://yourlotgdwebsite.com/*
// @grant        none
// ==/UserScript==

(function () {
    'use strict';

    // Ensure jaxon config exists
    if (typeof jaxon !== 'undefined') {
        try {
            if (typeof jaxon.config == undefined) {
                jaxon.config = {};
            }
        } catch (e) {
            jaxon = jaxon || {};
            jaxon.config = {};
        }

        jaxon.config.requestURI = "/async/process.php";
        jaxon.config.statusMessages = false;
        jaxon.config.waitCursor = true;
        jaxon.config.version = "Jaxon 4.x";
        jaxon.config.defaultMode = "asynchronous";
        jaxon.config.defaultMethod = "POST";
        jaxon.config.responseType = "JSON";

        // Initialize jaxon dialogs if not already present
        jaxon.dialogs = jaxon.dialogs || {};

        // Register command handlers when DOM is ready
        if (jaxon.dom && jaxon.dom.ready) {
            jaxon.dom.ready(function () {
                if (jaxon.command && jaxon.command.handler) {
                    jaxon.command.handler.register("jquery", (args) => jaxon.cmd.script.execute(args));

                    jaxon.command.handler.register("bags.set", (args) => {
                        for (const bag in args.data) {
                            jaxon.ajax.parameters.bags[bag] = args.data[bag];
                        }
                    });
                }
            });
        }

        // Register redirect command handler
        if (jaxon.command && jaxon.command.handler) {
            jaxon.command.handler.register("rd", (command) => {
                const { data: sUrl, delay: nDelay } = command;
                if (nDelay <= 0) {
                    window.location = sUrl;
                    return true;
                }
                window.setTimeout(() => window.location = sUrl, nDelay * 1000);
                return true;
            });
        }
    }

    // Create the clean namespace that PHP will generate: Lotgd.Async.Handler.*
    window.Lotgd = window.Lotgd || {};
    window.Lotgd.Async = window.Lotgd.Async || {};
    window.Lotgd.Async.Handler = window.Lotgd.Async.Handler || {};

    // Create empty handler objects - PHP will add the methods
    window.Lotgd.Async.Handler.Mail = window.Lotgd.Async.Handler.Mail || {};
    window.Lotgd.Async.Handler.Timeout = window.Lotgd.Async.Handler.Timeout || {};
    window.Lotgd.Async.Handler.Commentary = window.Lotgd.Async.Handler.Commentary || {};

    // Create the JaxonLotgd alias for backward compatibility
    window.JaxonLotgd = {
        Async: {
            Handler: window.Lotgd.Async.Handler
        }
    };

    // Mark as ready
    window.JaxonLotgdReady = true;

    // Dispatch ready event
    if (typeof window.CustomEvent === 'function') {
        window.dispatchEvent(new CustomEvent('JaxonLotgdReady'));
    }

    console.log('Clean Jaxon namespace created: Lotgd.Async.Handler');

    // DEBUGGING: Start polling immediately without waiting for jQuery
    // This should tell us if the problem is jQuery dependency
    console.log('DEBUG: Starting immediate polling test...');
    setTimeout(function () {
        console.log('DEBUG: Checking polling readiness...');
        console.log('DEBUG: typeof Lotgd =', typeof window.Lotgd);
        console.log('DEBUG: JaxonLotgdReady =', window.JaxonLotgdReady);
        console.log('DEBUG: lotgd_poll_interval_ms =', typeof window.lotgd_poll_interval_ms !== 'undefined' ? window.lotgd_poll_interval_ms : 'undefined');

        if (typeof window.Lotgd !== 'undefined'
            && window.Lotgd.Async && window.Lotgd.Async.Handler
            && window.Lotgd.Async.Handler.Commentary
            && typeof window.Lotgd.Async.Handler.Commentary.pollUpdates === 'function'
            && typeof window.lotgd_poll_interval_ms !== 'undefined') {
            console.log('DEBUG: All polling requirements met, testing manual call...');
            try {
                window.Lotgd.Async.Handler.Commentary.pollUpdates(window.lotgd_comment_section || '', window.lotgd_lastCommentId || 0);
                console.log('DEBUG: Manual polling call successful!');
            } catch (e) {
                console.error('DEBUG: Manual polling call failed:', e);
            }
        } else {
            console.error('DEBUG: Polling requirements not met:', {
                Lotgd: typeof window.Lotgd,
                pollUpdates: typeof window.Lotgd?.Async?.Handler?.Commentary?.pollUpdates,
                pollInterval: typeof window.lotgd_poll_interval_ms
            });
        }
    }, 2000);

})();
