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

    /**
     * Recover a tab whose token no longer matches.
     *
     * The realistic case is a page left open across a deploy or a logout: its
     * inlined token belongs to a session that is gone, so every poll is
     * refused. The refusal is valid JSON, so the existing parse-error pause
     * never sees it and the tab would keep asking every few seconds forever.
     *
     * A reload re-renders the page with a fresh token, which is the only thing
     * that actually fixes it. Guarded so it happens at most once: a reload loop
     * would be worse than the stale tab.
     */
    const handleCsrfRejection = function (response, url) {
        if (!response || response.status !== 403 || !isAsyncEndpoint(url)) {
            return;
        }
        // The guard has to outlive the reload it triggers. On `window` alone it
        // is discarded by the reload, so a failure that persists across page
        // loads -- a proxy stripping the header, say -- would reload, poll,
        // fail, reload again, forever. That is worse than the stale tab this
        // recovery exists for.
        //
        // sessionStorage is scoped to the tab and survives the reload. If it is
        // unavailable (private mode, storage disabled) the fallback is to not
        // reload at all: a tab that stops polling is recoverable by hand, a
        // reload loop is not.
        var guardKey = 'lotgd.async.csrfReloaded';
        try {
            if (window.sessionStorage.getItem(guardKey)) {
                return;
            }
            window.sessionStorage.setItem(guardKey, '1');
        } catch (error) {
            lotgdDebugSafe('async token rejected, but sessionStorage is unavailable; not reloading', error);
            return;
        }

        if (pollingRootInterval() !== null) {
            window.clearInterval(pollingRootInterval());
        }
        lotgdDebugSafe('async token rejected, reloading once to obtain a fresh one');
        window.location.reload();
    };

    /**
     * Where the polling loop keeps its interval id.
     *
     * async/setup.php uses `window.top || window` in all five places it
     * touches __lotgdPollingIntervalId, so this has to resolve the same window
     * or the clearInterval() below silently no-ops and polling keeps running.
     * The try/catch is for a cross-origin frame, where reading window.top
     * throws; setup.php does not guard that, but nothing is lost by doing so.
     */
    const pollingRootInterval = function () {
        try {
            const root = window.top || window;
            return typeof root.__lotgdPollingIntervalId === 'number' ? root.__lotgdPollingIntervalId : null;
        } catch (error) {
            return null;
        }
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
                return nativeFetch.call(this, input, init).then(function (response) {
                    handleCsrfRejection(response, url);
                    return response;
                });
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

    // Jaxon ships httpRequestOptions.mode = 'no-cors'. Under that mode the
    // browser applies the "request-no-cors" header guard and silently drops
    // every header that is not CORS-safelisted -- including X-LotGD-Csrf, and
    // including on same-origin requests. Verified in Chromium: with 'no-cors'
    // the header never reaches the server, with 'same-origin' it does.
    //
    // 'same-origin' is also the honest description of what this client does.
    // enforceRequestUri() pins the transport to /async/process.php on this
    // origin, so a cross-origin request is a bug; under 'no-cors' one would be
    // sent anyway and answered opaquely, under 'same-origin' it fails loudly.
    jaxon.config.httpRequestOptions = Object.assign({}, jaxon.config.httpRequestOptions, {
        mode: 'same-origin',
    });

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
