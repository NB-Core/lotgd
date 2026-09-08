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

    /**
     * Attach the session's CSRF token to requests aimed at the async endpoint.
     *
     * Jaxon's own CSRF support is not used: its behaviour, including which
     * header it sets, lives in the jaxon-js runtime, which this project loads
     * from a CDN rather than vendoring. A security control should not depend on
     * an asset that cannot be reviewed or diffed here, so the header is added
     * by wrapping fetch and XMLHttpRequest instead.
     *
     * The token is read at send time, not when the hook is installed:
     * async/setup.php emits the variable *after* this file, and a page that
     * refreshes the value does not have to reinstall the hook.
     *
     * A custom header is also a CSRF defence in its own right -- a cross-origin
     * caller cannot set one without a CORS preflight, which this endpoint does
     * not answer.
     */
    const CSRF_HEADER = 'X-LotGD-Csrf';

    const lotgdDebugSafe = function () {
        if (typeof window.lotgdDebug === 'function') {
            window.lotgdDebug.apply(null, arguments);
        }
    };

    const isAsyncEndpoint = function (url) {
        if (!url) {
            return false;
        }
        try {
            const target = new URL(url, window.location.origin);
            // Origin as well as path. Matching the path alone sends the token
            // to any host that happens to serve /async/process.php -- including
            // a protocol-relative '//host/async/process.php', which reads like
            // a path at a glance. Never guess true here, and that includes a
            // URL that cannot be parsed below.
            return target.origin === window.location.origin
                && target.pathname === '/async/process.php';
        } catch (error) {
            return false;
        }
    };

    const currentCsrfToken = function () {
        return typeof window.lotgd_async_csrf_token === 'string' ? window.lotgd_async_csrf_token : '';
    };

    const installCsrfHeader = function () {
        if (window.__lotgdAsyncCsrfHooked) {
            return;
        }
        window.__lotgdAsyncCsrfHooked = true;

        if (typeof window.fetch === 'function') {
            const nativeFetch = window.fetch;
            window.fetch = function (input, init) {
                const url = typeof input === 'string' ? input : (input && input.url);
                const token = currentCsrfToken();
                if (token && isAsyncEndpoint(url)) {
                    init = Object.assign({}, init);
                    const headers = new Headers((init && init.headers) || (input && input.headers) || {});
                    headers.set(CSRF_HEADER, token);
                    init.headers = headers;
                }
                return nativeFetch.call(this, input, init);
            };
        }

        if (window.XMLHttpRequest && XMLHttpRequest.prototype) {
            const nativeOpen = XMLHttpRequest.prototype.open;
            const nativeSend = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method, url) {
                this.__lotgdAsyncTarget = isAsyncEndpoint(url);
                return nativeOpen.apply(this, arguments);
            };
            XMLHttpRequest.prototype.send = function (body) {
                const token = currentCsrfToken();
                if (this.__lotgdAsyncTarget && token) {
                    try {
                        this.setRequestHeader(CSRF_HEADER, token);
                    } catch (error) {
                        // Header already sent or the request is in a state that
                        // forbids it. The server is in log mode, so a missed
                        // header is a log line, not a broken page.
                        lotgdDebugSafe('could not attach the async CSRF header', error);
                    }
                }
                return nativeSend.call(this, body);
            };
        }
    };

    installCsrfHeader();

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

            },
            { once: true }
        );
    } else {
        enforceRequestUri();

    }
})();
