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
 *  - forms are sent in place only where the server lists them (meta
 *    lotgd-partial-forms); every other form, popup links and links that
 *    leave the page stay ordinary;
 *  - a form the server turns away before it ran (X-Lotgd-Resubmit) is sent
 *    again as an ordinary page change, so nothing typed is lost;
 *  - a response without the partial header is never swapped in, and an
 *    older page revision never replaces a newer one;
 *  - a 409 (the page was already left from another tab or click) and any
 *    error lead to badnav.php, which shows the stored page -- a request is
 *    never repeated, least of all a POST;
 *  - Back/Forward go to badnav.php instead of re-running an old URL;
 *  - quick keys follow the navigation region after every swap;
 *  - regions can register init/destroy callbacks instead of inline scripts;
 *  - the async client (lotgd.async.js, which polls and swaps the realtime
 *    regions) gets the values of every page swapped in.
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
        restoreAddress();
    });

    // badnav.php showing a page that could not change in place: the page ran
    // under its own address, so the location bar shows that one again. The
    // page is not loaded twice; a reload gets the stored page as before.
    function restoreAddress() {
        const meta = document.querySelector('meta[name="lotgd-address"]');
        if (!meta || !/\/badnav\.php$/.test(window.location.pathname) || typeof window.history.replaceState !== 'function') {
            return;
        }
        let target = null;
        try {
            target = new URL(meta.getAttribute('content') || '', window.location.href);
        } catch (error) {
            return;
        }
        if (target.origin === window.location.origin && /\.php$/.test(target.pathname)) {
            window.history.replaceState(window.history.state, '', target.pathname + target.search);
        }
    }
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

    // The form targets of the page shown that the server answers partially
    // (PartialMode::pageForms()): from the meta tag on a full page load, from
    // X-Lotgd-Partial-Forms after every swap.
    let partialForms = null;
    function formsAllowed() {
        if (partialForms === null) {
            const meta = document.querySelector('meta[name="lotgd-partial-forms"]');
            partialForms = parseForms(meta ? meta.getAttribute('content') : null);
        }
        return partialForms;
    }

    function parseForms(raw) {
        try {
            const list = raw ? JSON.parse(raw) : [];
            return Array.isArray(list) ? list.map(formKey).filter(Boolean) : [];
        } catch (e) {
            return [];
        }
    }

    // A target as script name plus query, however the page wrote it.
    function formKey(target) {
        try {
            const url = new URL(String(target), window.location.href);
            if (url.host !== window.location.host) {
                return '';
            }
            return (url.pathname.split('/').pop() || '') + url.search;
        } catch (e) {
            return '';
        }
    }

    function isPartialForm(form) {
        if (form.hasAttribute('data-lotgd-classic')
            || form.hasAttribute('target')
            || form.hasAttribute('onsubmit')
            // Sent by the async client (lotgd.async.js) or as ever.
            || form.hasAttribute('data-lotgd-commentary-form')
            || form.hasAttribute('data-lotgd-comment-remove')) {
            return false;
        }
        if ((form.getAttribute('method') || 'GET').toUpperCase() !== 'POST') {
            return false;
        }
        const key = formKey(form.getAttribute('action') || window.location.href);
        return key !== '' && formsAllowed().indexOf(key) !== -1;
    }

    function isOrdinaryLink(el) {
        if (el.tagName === 'FORM') {
            return !isPartialForm(el);
        }
        if (el.tagName !== 'A') {
            return false;
        }
        const href = el.getAttribute('href') || '';
        return el.hasAttribute('onclick')
            || el.hasAttribute('target')
            || el.hasAttribute('download')
            // A picture for a lightbox: its script opens it on the click.
            || el.hasAttribute('data-lightbox')
            || /(^|\s)lightbox/i.test(el.getAttribute('rel') || '')
            || href === ''
            || href.charAt(0) === '#'
            || /^(javascript|mailto):/i.test(href)
            || (el.host && el.host !== window.location.host)
            // Only a game page can be swapped in: a picture, a file or a
            // directory loads as the browser would load it.
            || !/\.php$/i.test(el.pathname || '');
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

    // The button a form was sent with: a form sent again as a page change
    // must carry its name and value too.
    document.addEventListener('submit', function (event) {
        if (event.target && event.target.tagName === 'FORM') {
            event.target.lotgdSubmitter = event.submitter || null;
        }
    }, true);

    function resubmit(form) {
        form.setAttribute('data-lotgd-classic', '');
        form.setAttribute('hx-boost', 'false');
        const submitter = form.lotgdSubmitter;
        if (submitter && submitter.name) {
            const field = document.createElement('input');
            field.type = 'hidden';
            field.name = submitter.name;
            field.value = submitter.value;
            form.appendChild(field);
        }
        // The native submit: no submit event, so htmx leaves it alone.
        HTMLFormElement.prototype.submit.call(form);
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

        if (xhr.getResponseHeader('X-Lotgd-Resubmit') === '1') {
            // Turned away before the page ran: send the form as it is.
            event.detail.shouldSwap = false;
            const form = event.detail.requestConfig && event.detail.requestConfig.elt;
            if (form && form.tagName === 'FORM') {
                resubmit(form);
            } else {
                recover();
            }
            return;
        }

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
        // Before the swap: htmx decides which of the new forms to boost as
        // it processes them.
        partialForms = parseForms(xhr.getResponseHeader('X-Lotgd-Partial-Forms'));
        regions.run('destroy', document);
    });

    document.addEventListener('htmx:afterSettle', function (event) {
        applyAsyncState(event.detail && event.detail.xhr);
        applyRegionVersions(event.detail && event.detail.xhr);
        regions.run('init', document);
        // A browser focuses [autofocus] on page load only; a swapped form
        // (the bank's amount field) gets it here.
        const focus = document.querySelector(CONTENT + ' [autofocus]');
        if (focus && typeof focus.focus === 'function') {
            focus.focus();
        }
        // A skin that shows the page title in its layout marks it; the
        // <title> came with the answer.
        document.querySelectorAll('[data-lotgd-title]').forEach(function (element) {
            element.textContent = document.title;
        });
        // A mobile menu the link was followed from closes, as a page change
        // would have left it (Bootstrap's offcanvas, where the skin uses it).
        const Offcanvas = window.bootstrap && window.bootstrap.Offcanvas;
        document.querySelectorAll('[data-lotgd-close-on-swap].show').forEach(function (element) {
            const panel = Offcanvas && Offcanvas.getInstance(element);
            if (panel) {
                panel.hide();
            }
        });
    });

    function asyncClient() {
        return window.lotgd && window.lotgd.async ? window.lotgd.async : null;
    }

    function readHeaderJson(xhr, name) {
        const raw = xhr && xhr.getResponseHeader ? xhr.getResponseHeader(name) : null;
        if (!raw) {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    /*
     * Realtime players: a partial answer swapped in the stats and header
     * slots too, and brings their versions.
     */
    function applyRegionVersions(xhr) {
        const shown = readHeaderJson(xhr, 'X-Lotgd-Region-Versions');
        if (shown && asyncClient()) {
            asyncClient().syncRegionVersions(shown);
        }
    }

    /*
     * AJAX players: the poll starts from the comment section and the last
     * ids of the page it was loaded with. A partial answer brings the values
     * for the new page.
     */
    function applyAsyncState(xhr) {
        const state = readHeaderJson(xhr, 'X-Lotgd-Async-State');
        if (state && asyncClient()) {
            asyncClient().setState(state);
        }
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

    // Sanitize::stripEmoji() with the patterns the page uses
    // (Sanitize::emojiPatternsForScript()), and character references that
    // the page would show as such an emoji. Patterns are built at run time,
    // so a browser without \p{} support only skips this.
    let decoder = null;
    function stripEmoji(text, patterns) {
        if (!Array.isArray(patterns) || patterns.length === 0) {
            return text;
        }
        try {
            const regexes = patterns.map(function (p) { return new RegExp(p, 'gu'); });
            const strip = function (value) {
                return regexes.reduce(function (acc, re) { return acc.replace(re, ''); }, value);
            };
            decoder = decoder || document.createElement('textarea');
            return strip(text).replace(/&(?:#[xX][0-9a-fA-F]{1,6}|#[0-9]{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/g, function (ref) {
                decoder.innerHTML = ref;
                const char = decoder.value;
                return char !== ref && char !== '' && strip(char) === '' ? '' : ref;
            });
        } catch (e) {
            return text;
        }
    }

    function renderPreview(field) {
        let cfg;
        try {
            cfg = JSON.parse(field.getAttribute('data-lotgd-preview') || '{}');
        } catch (e) {
            return;
        }
        let t = field.value || '';
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

        // Emoji go as the page removes them (Sanitize::stripEmoji(),
        // commentary_emoji); the counter above still counts what is stored.
        if (Array.isArray(cfg.emojiPatterns) && cfg.emojiPatterns.length > 0) {
            t = t.slice(0, x) + stripEmoji(t.slice(x), cfg.emojiPatterns);
        }

        const colors = cfg.colors || {};
        // Bold and italic where the game allows them, nested properly and
        // closed at the end (Sanitize::balanceFormatting()).
        const open = [];
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
                    } else if (z === '`') {
                        // An escaped backtick shows as one, as appoencode() does.
                        out += '`';
                    } else if (cfg.formatting && (z === 'b' || z === 'i')) {
                        const at = open.indexOf(z);
                        if (at < 0) {
                            open.push(z);
                            out += '<' + z + '>';
                        } else {
                            const after = open.slice(at + 1);
                            after.slice().reverse().forEach(function (inner) { out += '</' + inner + '>'; });
                            out += '</' + z + '>';
                            after.forEach(function (inner) { out += '<' + inner + '>'; });
                            open.splice(at, 1);
                        }
                    } else if (Object.prototype.hasOwnProperty.call(colors, z)) {
                        out += '</span><span class="' + colors[z] + '">';
                    }
                    x++;
                } else {
                    // A backtick at the very end is shown, as the page shows it.
                    out += '`';
                }
            } else {
                out += y;
            }
        }

        open.slice().reverse().forEach(function (inner) { out += '</' + inner + '>'; });
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
        // Any one visible character, as the full page's script takes it:
        // the core gives Comment Moderation "," (",?Comment Moderation").
        const key = String.fromCharCode(event.charCode || event.keyCode).toUpperCase();
        if (key.length !== 1 || key.trim() === '') {
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

    function runOn(phase, element) {
        (handlers[element.getAttribute('data-lotgd-region')] || []).forEach(function (callbacks) {
            if (typeof callbacks[phase] === 'function') {
                callbacks[phase](element);
            }
        });
    }

    function start() {
        boostRegions();
        htmx.process(document.body);
        regions.run('init', document);
        if (asyncClient()) {
            // A realtime region fetched again is swapped like any region.
            asyncClient().setRegionSwapper(function (element, html) {
                runOn('destroy', element);
                element.innerHTML = html;
                htmx.process(element);
                runOn('init', element);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
