/*
 * Partial rendering client (docs/ReactiveCore.md, Phase 2).
 *
 * Loaded after htmx, and only for a player who has partial rendering on and a
 * skin that declares regions. Links inside the content and navigation regions
 * are boosted: the server answers with the content region and out-of-band
 * copies of the other regions. Everything the server cannot answer partially
 * comes back as a page change (HX-Redirect), handled by htmx itself.
 *
 * What this file adds to htmx:
 *  - forms, popup links and links that leave the page stay ordinary;
 *  - a response without the partial header is never swapped in, and an
 *    older page revision never replaces a newer one;
 *  - a 409 (the page was already left from another tab or click) and any
 *    error lead to badnav.php, which shows the stored page -- a request is
 *    never repeated, least of all a POST;
 *  - Back/Forward go to badnav.php instead of re-running an old URL;
 *  - quick keys follow the navigation region after every swap;
 *  - regions can register init/destroy callbacks instead of inline scripts.
 */
(function () {
    'use strict';

    if (typeof window.htmx === 'undefined') {
        return;
    }

    const htmx = window.htmx;
    const RECOVERY_URL = 'badnav.php';
    const CONTENT = '[data-lotgd-region="content"]';
    const BOOSTED = ['content', 'nav'];

    htmx.config.allowScriptTags = false;
    htmx.config.allowEval = false;
    htmx.config.selfRequestsOnly = true;
    htmx.config.historyCacheSize = 0;
    htmx.config.refreshOnHistoryMiss = false;
    htmx.config.defaultSwapStyle = 'innerHTML';
    htmx.config.scrollIntoViewOnBoost = false;

    let displayedRevision = readRevision();
    let inFlight = false;

    function readRevision() {
        const meta = document.querySelector('meta[name="lotgd-page-revision"]');
        const value = meta ? parseInt(meta.getAttribute('content') || '0', 10) : 0;
        return Number.isNaN(value) ? 0 : value;
    }

    function recover() {
        window.location.href = RECOVERY_URL;
    }

    /*
     * Region lifecycle: lotgd.regions.register('stats', {init(el) {}, destroy(el) {}}).
     */
    const handlers = {};
    const regions = {
        register: function (name, callbacks) {
            (handlers[name] = handlers[name] || []).push(callbacks || {});
            document.querySelectorAll('[data-lotgd-region="' + name + '"]').forEach(function (el) {
                if (callbacks && typeof callbacks.init === 'function') {
                    callbacks.init(el);
                }
            });
        },
        run: function (phase, root) {
            Object.keys(handlers).forEach(function (name) {
                root.querySelectorAll('[data-lotgd-region="' + name + '"]').forEach(function (el) {
                    handlers[name].forEach(function (callbacks) {
                        if (typeof callbacks[phase] === 'function') {
                            callbacks[phase](el);
                        }
                    });
                });
            });
        }
    };
    window.lotgd = window.lotgd || {};
    window.lotgd.regions = regions;

    function isOrdinaryLink(el) {
        if (el.tagName === 'FORM') {
            // A form changes state; Phase 2 never sends one partially.
            return true;
        }
        if (el.tagName !== 'A') {
            return false;
        }
        const href = el.getAttribute('href') || '';
        return el.hasAttribute('onclick')
            || el.hasAttribute('target')
            || el.hasAttribute('download')
            || href === ''
            || href.charAt(0) === '#'
            || /^(javascript|mailto):/i.test(href)
            || (el.host && el.host !== window.location.host);
    }

    function boostRegions() {
        BOOSTED.forEach(function (name) {
            document.querySelectorAll('[data-lotgd-region="' + name + '"]').forEach(function (el) {
                el.setAttribute('hx-boost', 'true');
                el.setAttribute('hx-target', CONTENT);
                el.setAttribute('hx-swap', 'innerHTML');
            });
        });
    }

    document.addEventListener('htmx:beforeProcessNode', function (event) {
        const el = event.target;
        if (el && el.tagName && isOrdinaryLink(el)) {
            el.setAttribute('hx-boost', 'false');
        }
    });

    document.addEventListener('htmx:beforeRequest', function (event) {
        if (inFlight) {
            // A second click while the first is under way would only be
            // turned away by the server; do not send it.
            event.preventDefault();
            return;
        }
        inFlight = true;
        document.body.classList.add('lotgd-partial-loading');
    });

    document.addEventListener('htmx:afterRequest', function () {
        inFlight = false;
        document.body.classList.remove('lotgd-partial-loading');
    });

    document.addEventListener('htmx:beforeSwap', function (event) {
        const xhr = event.detail.xhr;

        if (xhr.status === 409) {
            event.detail.shouldSwap = false;
            let target = RECOVERY_URL;
            try {
                const data = JSON.parse(xhr.responseText);
                if (data && typeof data.reload === 'string' && /^[a-z0-9_.-]+\.php(\?.*)?$/i.test(data.reload)) {
                    target = data.reload;
                }
            } catch (e) {
                // Fall back to the recovery page.
            }
            window.location.href = target;
            return;
        }

        if (xhr.status !== 200 || xhr.getResponseHeader('X-Lotgd-Partial') !== '1') {
            // Never swap a full page or an error into a region.
            event.detail.shouldSwap = false;
            recover();
            return;
        }

        const revision = parseInt(xhr.getResponseHeader('X-Lotgd-Page-Revision') || '0', 10);
        if (!Number.isNaN(revision) && revision < displayedRevision) {
            event.detail.shouldSwap = false;
            return;
        }
        displayedRevision = Number.isNaN(revision) ? displayedRevision : revision;
        regions.run('destroy', document);
    });

    document.addEventListener('htmx:afterSettle', function () {
        regions.run('init', document);
    });

    document.addEventListener('htmx:sendError', recover);
    document.addEventListener('htmx:timeout', recover);

    // Back/Forward: the server only knows the latest page.
    document.addEventListener('htmx:historyCacheMiss', function (event) {
        event.preventDefault();
        recover();
    });

    document.addEventListener('keypress', function (event) {
        const target = event.target;
        if (event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }
        if (target && (['INPUT', 'TEXTAREA', 'SELECT'].indexOf(target.nodeName) !== -1 || target.isContentEditable)) {
            return;
        }
        const key = String.fromCharCode(event.charCode || event.keyCode).toUpperCase();
        if (!/^[A-Z0-9]$/.test(key)) {
            return;
        }
        const links = document.querySelectorAll('[data-lotgd-region="nav"] a[accesskey]');
        for (let i = 0; i < links.length; i++) {
            // Twig skins escape the attribute markup Nav builds, so the value
            // can arrive as "V" with its quotes; compare the letter only.
            if ((links[i].getAttribute('accesskey') || '').replace(/["']/g, '').toUpperCase() === key) {
                event.preventDefault();
                links[i].click();
                return;
            }
        }
    });

    function start() {
        boostRegions();
        htmx.process(document.body);
        regions.run('init', document);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
