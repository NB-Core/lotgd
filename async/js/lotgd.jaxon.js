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

    if (window.__lotgdAsyncInitialized) {
        return;
    }

    window.__lotgdAsyncInitialized = true;

    window.Lotgd = window.Lotgd || {};
    Lotgd.Async = Lotgd.Async || {};
    Lotgd.Async.Handler = Lotgd.Async.Handler || {};

    if (typeof jaxon === 'undefined') {
        return;
    }

    /**
     * Resolve an absolute async endpoint URL for Jaxon transport.
     *
     * Passkey async requests must always hit /async/process.php. If Jaxon falls back to
     * runmodule.php, forced-nav/page HTML can be returned instead of JSON, breaking callback
     * processing and causing timeout-style failures in passkey setup/challenge operations.
     *
     * @returns {string}
     */
    const getAbsoluteRequestUri = function () {
        return new URL('/async/process.php', window.location.origin).toString();
    };

    /**
     * Enforce and lock Jaxon requestURI so page-local scripts cannot rewrite transport.
     */
    const enforceRequestUri = function () {
        if (!window.jaxon || !jaxon.config) {
            return;
        }

        const finalUri = getAbsoluteRequestUri();
        const descriptor = Object.getOwnPropertyDescriptor(jaxon.config, 'requestURI');
        if (descriptor && descriptor.configurable === false) {
            const current = typeof jaxon.config.requestURI === 'string' ? jaxon.config.requestURI : '';
            if (current !== finalUri) {
                console.warn('[LotGD Async] requestURI lock mismatch detected:', current);
            }
            return;
        }

        try {
            Object.defineProperty(jaxon.config, 'requestURI', {
                configurable: false,
                enumerable: true,
                get: function () {
                    return finalUri;
                },
                set: function () {
                    return finalUri;
                },
            });
        } catch (error) {
            // If requestURI cannot be locked (legacy browser), keep writing the final URI.
            jaxon.config.requestURI = finalUri;
        }
    };

    enforceRequestUri();
    jaxon.config.statusMessages = false;
    jaxon.config.waitCursor = true;
    jaxon.config.version = 'Jaxon 5.x';
    jaxon.config.defaultMode = 'asynchronous';
    jaxon.config.defaultMethod = 'POST';
    jaxon.config.responseType = 'JSON';

    jaxon.dialogs = jaxon.dialogs || {};

    if (jaxon.command && jaxon.command.handler) {
        jaxon.command.handler.register('rd', (command) => {
            const { data: sUrl, delay: nDelay } = command;
            if (nDelay <= 0) {
                window.location = sUrl;
                return true;
            }
            window.setTimeout(() => (window.location = sUrl), nDelay * 1000);
            return true;
        });
    }

    if (jaxon.dom && jaxon.dom.ready) {
        jaxon.dom.ready(function () {
            enforceRequestUri();

            if (!window.__lotgdJaxonUriLogged) {
                window.__lotgdJaxonUriLogged = true;
                // Temporary startup trace to confirm the final request endpoint in browser diagnostics.
                console.debug('[LotGD Async] Effective Jaxon requestURI:', jaxon.config.requestURI);
            }

            if (jaxon.command && jaxon.command.handler) {
                jaxon.command.handler.register('jquery', (args) => jaxon.cmd.script.execute(args));

                jaxon.command.handler.register('bags.set', (args) => {
                    for (const bag in args.data) {
                        jaxon.ajax.parameters.bags[bag] = args.data[bag];
                    }
                });
            }
        });
    } else if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            function () {
                enforceRequestUri();

                if (!window.__lotgdJaxonUriLogged) {
                    window.__lotgdJaxonUriLogged = true;
                    // Temporary startup trace to confirm the final request endpoint in browser diagnostics.
                    console.debug('[LotGD Async] Effective Jaxon requestURI:', jaxon.config.requestURI);
                }
            },
            { once: true }
        );
    } else {
        enforceRequestUri();

        if (!window.__lotgdJaxonUriLogged) {
            window.__lotgdJaxonUriLogged = true;
            // Temporary startup trace to confirm the final request endpoint in browser diagnostics.
            console.debug('[LotGD Async] Effective Jaxon requestURI:', jaxon.config.requestURI);
        }
    }
})();
