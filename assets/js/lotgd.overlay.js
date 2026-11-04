/*
 * Popup pages inside the page (docs/ReactiveCore.md, Phase 6b).
 *
 * A link marked data-lotgd-overlay (mail, the MOTD, "write mail" on a bio)
 * opens its page in a dialog instead of a browser window: the page is
 * fetched with the header X-Lotgd-Overlay: 1, and Footer::popupFooter()
 * answers its content alone, the title in X-Lotgd-Overlay-Title. Links and
 * forms inside the dialog load into it the same way; an answer that is not
 * an overlay answer (a page that is no popup) opens where it would have
 * before, in a new window. X-Lotgd-Overlay-Close closes the dialog ("Send and
 * Close"). Content never runs scripts: the popup pages use the declarative
 * handlers at the end of this file.
 *
 * The link keeps its href and its window-opening onclick, so a browser
 * without this file, or a click with a modifier key, behaves as it always did.
 */
(function () {
    'use strict';

    if (window.lotgd && window.lotgd.overlay) {
        return;
    }

    let dialog = null;
    let bodyElement = null;
    let titleElement = null;
    let currentUrl = window.location.href;
    let opener = null;
    let loading = false;

    function build() {
        if (dialog) {
            return;
        }
        dialog = document.createElement('dialog');
        dialog.className = 'lotgd-overlay';
        dialog.setAttribute('aria-labelledby', 'lotgd-overlay-title');
        dialog.innerHTML = '<div class="lotgd-overlay__head">'
            + '<h2 class="lotgd-overlay__title" id="lotgd-overlay-title"></h2>'
            + '<button type="button" class="lotgd-overlay__close" aria-label="Close"></button>'
            + '</div><div class="lotgd-overlay__body"></div>';
        document.body.appendChild(dialog);
        bodyElement = dialog.querySelector('.lotgd-overlay__body');
        titleElement = dialog.querySelector('.lotgd-overlay__title');

        dialog.querySelector('.lotgd-overlay__close').addEventListener('click', close);
        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            close();
        });
        // A click on the backdrop (the dialog element itself) closes it.
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                close();
            }
        });
        dialog.addEventListener('click', inside);
        dialog.addEventListener('submit', submitted);
    }

    function sameSite(url) {
        try {
            const target = new URL(url, currentUrl);
            return target.origin === window.location.origin && /^https?:$/.test(target.protocol) ? target : null;
        } catch (error) {
            return null;
        }
    }

    function show(response) {
        if (response.headers.get('X-Lotgd-Overlay') !== '1') {
            // Not a popup page: where it went before, a window of its own.
            close();
            window.open(response.url, '_blank');
            return null;
        }
        return response.text().then(function (html) {
            currentUrl = response.url;
            // A popup page counts as activity (lotgd.timeout.js).
            document.dispatchEvent(new CustomEvent('lotgd:activity'));
            const title = response.headers.get('X-Lotgd-Overlay-Title');
            titleElement.textContent = title ? decodeURIComponent(title) : '';
            bodyElement.innerHTML = html;
            bodyElement.scrollTop = 0;
            suScan();
            if (response.headers.get('X-Lotgd-Overlay-Close') === '1') {
                close();
                return;
            }
            const focus = bodyElement.querySelector('[autofocus]')
                || bodyElement.querySelector('input:not([type=hidden]), textarea, select, button, a[href]');
            if (focus) {
                focus.focus();
            }
        });
    }

    function load(url, init) {
        const target = sameSite(url);
        if (!target) {
            window.open(url, '_blank');
            return Promise.resolve();
        }
        if (loading) {
            return Promise.resolve();
        }
        loading = true;
        dialog.setAttribute('aria-busy', 'true');
        const options = Object.assign({
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'follow'
        }, init || {});
        options.headers = Object.assign({ 'X-Lotgd-Overlay': '1' }, options.headers || {});
        return window.fetch(target.href, options).then(show).catch(function () {
            // Whatever failed, the page can still be had in a window.
            close();
            window.open(target.href, '_blank');
        }).then(function () {
            loading = false;
            if (dialog) {
                dialog.removeAttribute('aria-busy');
            }
        });
    }

    function open(url, from) {
        build();
        opener = from || document.activeElement;
        currentUrl = window.location.href;
        titleElement.textContent = '';
        bodyElement.innerHTML = '';
        if (!dialog.open) {
            dialog.showModal();
        }
        return load(url);
    }

    function close() {
        if (!dialog || !dialog.open) {
            return;
        }
        dialog.close();
        bodyElement.innerHTML = '';
        if (opener && typeof opener.focus === 'function') {
            opener.focus();
        }
        // What the dialog did (mail read, sent, deleted) shows in the header.
        if (window.lotgd && window.lotgd.async && typeof window.lotgd.async.refresh === 'function') {
            window.lotgd.async.refresh('mail');
        }
        // And in the notices (lotgd.notices.js): unread mail, the MoTD read.
        if (window.lotgd && window.lotgd.notices && typeof window.lotgd.notices.refresh === 'function') {
            window.lotgd.notices.refresh();
        }
    }

    function plainClick(event) {
        return event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
    }

    // Before the link's own onclick, which would open a window.
    document.addEventListener('click', function (event) {
        const link = event.target.closest ? event.target.closest('a[data-lotgd-overlay]') : null;
        if (!link || !plainClick(event) || (dialog && dialog.contains(link)) || !sameSite(link.getAttribute('href') || '')) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        open(link.getAttribute('href'), link);
    }, true);

    function inside(event) {
        if (event.defaultPrevented || !plainClick(event)) {
            return;
        }
        const link = event.target.closest ? event.target.closest('a[href]') : null;
        if (!link || !dialog.contains(link)) {
            return;
        }
        const href = link.getAttribute('href') || '';
        if (href === '' || href.charAt(0) === '#' || /^javascript:/i.test(href)) {
            return;
        }
        event.preventDefault();
        load(href);
    }

    function submitted(event) {
        // A confirm() in onsubmit that said no has prevented it already.
        if (event.defaultPrevented) {
            return;
        }
        const form = event.target;
        event.preventDefault();
        const action = form.getAttribute('action') || currentUrl;
        const data = event.submitter ? new FormData(form, event.submitter) : new FormData(form);
        if ((form.getAttribute('method') || 'GET').toUpperCase() === 'POST') {
            load(new URL(action, currentUrl).href, { method: 'POST', body: data });
            return;
        }
        const url = new URL(action, currentUrl);
        new URLSearchParams(data).forEach(function (value, key) {
            url.searchParams.set(key, value);
        });
        load(url.href);
    }

    /*
     * Declarative handlers for popup pages, in a window as in the dialog.
     *
     *  data-lotgd-check-all="name[]"   a button that ticks every checkbox of
     *                                  that name in its form (unticks them
     *                                  all when any is ticked); with
     *                                  data-lotgd-check-label and
     *                                  data-lotgd-uncheck-label its value
     *                                  follows;
     *  data-lotgd-check-by             a select whose options carry
     *                                  data-lotgd-ids (JSON list of checkbox
     *                                  ids) to tick; the check-all button of
     *                                  the form then shows the uncheck label;
     *  data-lotgd-confirm="text"       a submit button or link that asks first;
     *  data-lotgd-count="id"           a field whose length is written into
     *                                  the element with that id; if that
     *                                  element has data-lotgd-count-max, it
     *                                  gets data-lotgd-count-under (up to the
     *                                  max, XX = characters left) or
     *                                  data-lotgd-count-over (XX = characters
     *                                  over) as markup instead;
     *  data-lotgd-su-warning="to"      a marker (with data-lotgd-su-target =
     *                                  id of the warning element and
     *                                  data-lotgd-superusers = JSON object of
     *                                  logins) that shows the warning while
     *                                  the field with that id holds one of
     *                                  them;
     *  data-lotgd-resize="rows:+5"     a button that changes the rows (or
     *                                  cols) of the textarea it names in
     *                                  data-lotgd-target.
     */
    document.addEventListener('click', function (event) {
        const checkAll = event.target.closest ? event.target.closest('[data-lotgd-check-all]') : null;
        if (checkAll) {
            event.preventDefault();
            const scope = checkAll.form || checkAll.closest('form') || document;
            const name = checkAll.getAttribute('data-lotgd-check-all');
            const boxes = Array.prototype.filter.call(scope.querySelectorAll('input[type=checkbox]'), function (box) {
                return box.name === name;
            });
            // As the inline script did: any box ticked, so unticks all.
            const all = boxes.some(function (box) {
                return box.checked;
            });
            boxes.forEach(function (box) {
                box.checked = !all;
            });
            const label = !all ? checkAll.getAttribute('data-lotgd-uncheck-label') : checkAll.getAttribute('data-lotgd-check-label');
            if (label !== null) {
                checkAll.value = label;
            }
            return;
        }
        const confirmer = event.target.closest ? event.target.closest('[data-lotgd-confirm]') : null;
        if (confirmer && !window.confirm(confirmer.getAttribute('data-lotgd-confirm'))) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }
        const resize = event.target.closest ? event.target.closest('[data-lotgd-resize]') : null;
        if (resize) {
            event.preventDefault();
            const field = document.getElementById(resize.getAttribute('data-lotgd-target') || '');
            const match = /^(rows|cols):([+-]\d+)$/.exec(resize.getAttribute('data-lotgd-resize') || '');
            if (field && match) {
                // The bounds the mail composer always had: rows 4-49, cols 4-149.
                const next = (field[match[1]] || 1) + parseInt(match[2], 10);
                if (next > 3 && next < (match[1] === 'rows' ? 50 : 150)) {
                    field[match[1]] = next;
                }
            }
        }
    }, true);

    document.addEventListener('input', function (event) {
        const field = event.target;
        if (!field || !field.getAttribute || !field.hasAttribute('data-lotgd-count')) {
            return;
        }
        const target = document.getElementById(field.getAttribute('data-lotgd-count'));
        if (!target) {
            return;
        }
        const length = field.value.length;
        const max = parseInt(target.getAttribute('data-lotgd-count-max') || '', 10);
        if (isNaN(max)) {
            target.textContent = String(length);
            return;
        }
        const template = target.getAttribute(length <= max ? 'data-lotgd-count-under' : 'data-lotgd-count-over') || 'XX';
        target.innerHTML = template.replace('XX', String(Math.abs(max - length)));
    });

    document.addEventListener('change', function (event) {
        const select = event.target;
        if (!select || !select.hasAttribute) {
            return;
        }
        if (select.hasAttribute('data-lotgd-check-by')) {
            const option = select.options[select.selectedIndex];
            let ids = [];
            try {
                ids = JSON.parse((option && option.getAttribute('data-lotgd-ids')) || '[]');
            } catch (error) {
                ids = [];
            }
            ids.forEach(function (id) {
                const box = document.getElementById(id);
                if (box && box.type === 'checkbox') {
                    box.checked = true;
                }
            });
            const scope = select.form || select.closest('form') || document;
            const button = scope.querySelector('[data-lotgd-check-all]');
            if (ids.length > 0 && button && button.getAttribute('data-lotgd-uncheck-label') !== null) {
                button.value = button.getAttribute('data-lotgd-uncheck-label');
            }
        }
        if (select.id) {
            suMarkers().forEach(function (marker) {
                if (marker.getAttribute('data-lotgd-su-warning') === select.id) {
                    suWarning(marker);
                }
            });
        }
    });

    function suMarkers() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-lotgd-su-warning]'));
    }

    // Shows the warning element while the recipient is a listed superuser.
    function suWarning(marker) {
        const to = document.getElementById(marker.getAttribute('data-lotgd-su-warning') || '');
        const warning = document.getElementById(marker.getAttribute('data-lotgd-su-target') || '');
        if (!to || !warning) {
            return;
        }
        let logins = {};
        try {
            logins = JSON.parse(marker.getAttribute('data-lotgd-superusers') || '{}') || {};
        } catch (error) {
            logins = {};
        }
        const show = Object.prototype.hasOwnProperty.call(logins, to.value) && logins[to.value];
        warning.style.visibility = show ? 'visible' : 'hidden';
        warning.style.display = show ? 'inline' : 'none';
    }

    // Markers arrive with the page or with the dialog's content (show()),
    // so the first check runs then.
    function suScan() {
        suMarkers().forEach(suWarning);
    }
    document.addEventListener('DOMContentLoaded', suScan);

    function start() {
        const meta = document.querySelector('meta[name="lotgd-overlay-open"]');
        if (meta && meta.getAttribute('content')) {
            open(meta.getAttribute('content'), null);
        }
    }

    window.lotgd = window.lotgd || {};
    window.lotgd.overlay = { open: open, close: close };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
