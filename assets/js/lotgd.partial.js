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
 *  - regions can register init/destroy callbacks instead of inline scripts;
 *  - with realtime on (Phase 4), the page polls async/poll.php for
 *    announcements and fetches a region again when one says it changed.
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

    // The revision the page shows, read once the document is parsed (this
    // script runs from <head>, ahead of the meta tag). null while the page
    // does not say -- a page stored before partial rendering was on for
    // this player: then nothing is sent, and the server decides from its
    // own row as before.
    let displayedRevision = null;
    document.addEventListener('DOMContentLoaded', function () {
        if (displayedRevision === null) {
            displayedRevision = readRevision();
        }
    });
    let inFlight = false;

    function readRevision() {
        const meta = document.querySelector('meta[name="lotgd-page-revision"]');
        if (!meta) {
            return null;
        }
        const value = parseInt(meta.getAttribute('content') || '', 10);
        return Number.isNaN(value) ? null : value;
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

    // Which <head> this page has; the server answers with a full page when
    // the next one needs another. And which revision it shows: a link from
    // a page another tab has left since is turned away, even when the
    // latest page offers the same link.
    document.addEventListener('htmx:configRequest', function (event) {
        const meta = document.querySelector('meta[name="lotgd-head"]');
        if (meta) {
            event.detail.headers['X-Lotgd-Head'] = meta.getAttribute('content') || '';
        }
        if (displayedRevision === null) {
            displayedRevision = readRevision();
        }
        if (displayedRevision !== null) {
            event.detail.headers['X-Lotgd-Page-Revision'] = String(displayedRevision);
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

    document.addEventListener('htmx:afterSettle', function (event) {
        applyAsyncState(event.detail && event.detail.xhr);
        applyRegionVersions(event.detail && event.detail.xhr);
        regions.run('init', document);
    });

    /*
     * Realtime players: a partial answer swapped in the stats and header
     * slots too. Their versions come with it; set by startRealtime().
     */
    let syncRegionVersions = function () {};

    function applyRegionVersions(xhr) {
        const raw = xhr && xhr.getResponseHeader ? xhr.getResponseHeader('X-Lotgd-Region-Versions') : null;
        if (!raw) {
            return;
        }
        try {
            syncRegionVersions(JSON.parse(raw) || {});
        } catch (e) {
            // Keep the versions there are.
        }
    }

    /*
     * AJAX players: the polling script in <head> starts from the comment
     * section and the last ids of the page it was loaded with. A partial
     * answer brings the values for the new page.
     */
    function applyAsyncState(xhr) {
        const raw = xhr && xhr.getResponseHeader ? xhr.getResponseHeader('X-Lotgd-Async-State') : null;
        if (!raw) {
            return;
        }
        let state;
        try {
            state = JSON.parse(raw);
        } catch (e) {
            return;
        }
        if (typeof state.commentSection === 'string') {
            window.lotgd_comment_section = state.commentSection;
        }
        ['lastCommentId', 'lastUnreadMailId', 'lastUnreadMailCount'].forEach(function (key) {
            if (typeof state[key] === 'number') {
                window['lotgd_' + key] = state[key];
            }
        });
    }

    document.addEventListener('htmx:sendError', recover);
    document.addEventListener('htmx:timeout', recover);

    // Back/Forward: the server only knows the latest page.
    document.addEventListener('htmx:historyCacheMiss', function (event) {
        event.preventDefault();
        recover();
    });

    /*
     * Commentary preview (Forms::previewField()): the field carries its
     * settings in data-lotgd-preview instead of an inline script. The output
     * matches the inline previewtext<name>() function it replaces.
     */
    function renderPreview(field) {
        let cfg;
        try {
            cfg = JSON.parse(field.getAttribute('data-lotgd-preview') || '{}');
        } catch (e) {
            return;
        }
        const t = field.value || '';
        let l = cfg.limit;
        let out = "<span class='colLtWhite'>" + (cfg.start || '') + ' ';
        let end = '</span>';
        let x = 0;

        if (cfg.talkline !== null && cfg.talkline !== undefined) {
            if (t.substr(0, 2) === '::') {
                x = 2;
                out += '</span><span class="colLtWhite">';
            } else if (t.substr(0, 1) === ':') {
                x = 1;
                out += '</span><span class="colLtWhite">';
            } else if (t.substr(0, 3) === '/me') {
                x = 3;
                out += '</span><span class="colLtWhite">';
            } else if (cfg.gamemaster && t.substr(0, 5) === '/game') {
                x = 5;
                out = '<span class="colLtWhite">';
            } else {
                out += '</span><span class="colDkCyan">' + cfg.talkline + ', </span><span class="colLtCyan">';
                end += '</span><span class="colDkCyan">';
            }
        }

        if (cfg.showCharsLeft) {
            if (x !== 0) {
                if (field.maxLength !== cfg.maxChars) {
                    field.maxLength = cfg.maxChars;
                }
                l = cfg.maxChars;
            } else {
                field.maxLength = l;
            }
            let charsleft = '';
            if (l - t.length < 0) {
                charsleft += '<span class="colLtRed">';
            }
            charsleft += cfg.youHave + (l - t.length) + cfg.charsLeft + '<br>';
            if (l - t.length < 0) {
                charsleft += '</span>';
            }
            const counter = document.getElementById('charsleft' + cfg.name);
            if (counter) {
                counter.innerHTML = charsleft + '<br/>';
            }
        }

        const colors = cfg.colors || {};
        for (; x < t.length; x++) {
            const y = t.substr(x, 1);
            if (y === '<') {
                out += '&lt;';
            } else if (y === '>') {
                out += '&gt;';
            } else if (y === '`') {
                if (x < t.length - 1) {
                    const z = t.substr(x + 1, 1);
                    if (z === '0') {
                        out += '</span>';
                    } else if (Object.prototype.hasOwnProperty.call(colors, z)) {
                        out += '</span><span class="' + colors[z] + '">';
                    }
                    x++;
                }
            } else {
                out += y;
            }
        }

        const target = document.getElementById('previewtext' + cfg.name);
        if (target) {
            target.innerHTML = out + end + '<br/>';
        }
    }

    ['keyup', 'input'].forEach(function (type) {
        document.addEventListener(type, function (event) {
            const field = event.target;
            if (field && field.hasAttribute && field.hasAttribute('data-lotgd-preview')) {
                renderPreview(field);
            }
        });
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

    /*
     * Realtime (docs/ReactiveCore.md, Phase 4). The page says where to poll,
     * from which delivery number, and which version of each region it shows.
     * An announcement names a region and its new version; the region is
     * fetched again only when that version is higher than the one shown, and
     * the answer is applied only if it is still higher -- an old event that
     * arrives late can never replace newer content. Events are told apart by
     * id, so one delivered twice changes nothing.
     */
    const REGION_SELECTORS = {
        mail: '[data-lotgd-region="header"][data-lotgd-slot="mail"]',
        stats: '[data-lotgd-region="stats"]'
    };

    function runOn(phase, element) {
        (handlers[element.getAttribute('data-lotgd-region')] || []).forEach(function (callbacks) {
            if (typeof callbacks[phase] === 'function') {
                callbacks[phase](element);
            }
        });
    }

    function readMeta(name) {
        const meta = document.querySelector('meta[name="' + name + '"]');
        return meta ? (meta.getAttribute('content') || '') : null;
    }

    function startRealtime() {
        const config = readMeta('lotgd-realtime');
        if (config === null || typeof window.fetch !== 'function') {
            return;
        }
        const parts = config.split('|');
        const url = parts[0];
        const interval = Math.max(5, parseInt(parts[1] || '10', 10) || 10) * 1000;
        let cursor = parseInt(readMeta('lotgd-event-cursor') || '0', 10) || 0;
        let versions = {};
        try {
            versions = JSON.parse(readMeta('lotgd-region-versions') || '{}') || {};
        } catch (error) {
            versions = {};
        }
        const seen = [];
        const fetching = {};
        const wanted = {};
        // What the page shows now is what the partial answer rendered: a
        // fetch still on its way is applied only if it is newer, and a newer
        // state shown before the swap is fetched again.
        syncRegionVersions = function (shown) {
            Object.keys(shown).forEach(function (region) {
                const version = Number(shown[region]) || 0;
                const before = versions[region] || 0;
                versions[region] = version;
                if (before > version) {
                    refresh(region, before);
                }
            });
        };
        let delay = interval;
        let stopped = false;

        function get(query) {
            return window.fetch(url + '?' + query, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Lotgd-Poll': '1' }
            }).then(function (response) {
                if (response.status === 401 || response.status === 404) {
                    stopped = true;
                }
                if (!response.ok) {
                    throw new Error('poll ' + response.status);
                }
                return response.json();
            });
        }

        function refresh(region, version) {
            if (!REGION_SELECTORS[region] || version <= (versions[region] || 0)) {
                return;
            }
            // Remembered until a fetch succeeds, so one that fails is tried
            // again with the next poll.
            wanted[region] = Math.max(wanted[region] || 0, version);
            if (fetching[region]) {
                return;
            }
            fetching[region] = true;
            const asked = wanted[region];
            get('region=' + encodeURIComponent(region)).then(function (answer) {
                // The answer is the state when it was rendered, whatever
                // version the announcement named. A newer announcement that
                // came while it was on its way is kept and fetched next;
                // a failed fetch is tried again with the next poll.
                if ((wanted[region] || 0) <= asked) {
                    delete wanted[region];
                }
                if (answer && typeof answer.html === 'string' && answer.version > (versions[region] || 0)) {
                    versions[region] = answer.version;
                    document.querySelectorAll(REGION_SELECTORS[region]).forEach(function (element) {
                        runOn('destroy', element);
                        element.innerHTML = answer.html;
                        htmx.process(element);
                        runOn('init', element);
                    });
                }
            }).catch(function () {}).then(function () {
                fetching[region] = false;
                if ((wanted[region] || 0) > asked) {
                    refresh(region, wanted[region]);
                }
            });
        }

        function poll() {
            if (stopped) {
                return;
            }
            if (document.hidden) {
                window.setTimeout(poll, interval);
                return;
            }
            get('cursor=' + cursor).then(function (answer) {
                delay = interval;
                if (answer.resync) {
                    Object.keys(REGION_SELECTORS).forEach(function (region) {
                        refresh(region, Number.MAX_SAFE_INTEGER);
                    });
                }
                (answer.events || []).forEach(function (event) {
                    if (seen.indexOf(event.id) !== -1) {
                        return;
                    }
                    seen.push(event.id);
                    if (seen.length > 200) {
                        seen.shift();
                    }
                    refresh(event.region, event.version);
                });
                if (typeof answer.cursor === 'number' && answer.cursor > cursor) {
                    cursor = answer.cursor;
                }
                Object.keys(wanted).forEach(function (region) {
                    refresh(region, wanted[region]);
                });
            }).catch(function () {
                // Back off on errors, up to five minutes.
                delay = Math.min(delay * 2, 300000);
            }).then(function () {
                if (!stopped) {
                    window.setTimeout(poll, delay);
                }
            });
        }

        window.setTimeout(poll, interval);
    }

    function start() {
        boostRegions();
        htmx.process(document.body);
        regions.run('init', document);
        startRealtime();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
