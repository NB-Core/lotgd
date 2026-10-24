/*
 * Tabbed forms with a search field (Forms::showFormTabbed()): the
 * preferences, the game settings, the user editor.
 *
 * The server writes the form as one table, every row marked with its
 * section (data-section), an empty container above it
 * (#showFormSection<id>) and, after it, a marker that carries the sections
 * in data-lotgd-showform. This file builds the search field and the tabs
 * into the container and filters the rows; no inline script, so a page with
 * such a form can be swapped in place (lotgd.partial.js) and is set up again
 * after every swap.
 *
 * Also here: a form marked data-lotgd-min-password checks the length of its
 * new password (pass1) before it is sent. The server checks again.
 */
(function () {
    'use strict';

    const READY = 'data-lotgd-showform-ready';

    function readConfig(marker) {
        try {
            const config = JSON.parse(marker.getAttribute('data-lotgd-showform') || '{}');
            return config && typeof config === 'object' ? config : null;
        } catch (e) {
            return null;
        }
    }

    function setup(marker) {
        if (marker.hasAttribute(READY)) {
            return;
        }
        const config = readConfig(marker);
        const id = config ? String(config.id) : '';
        const table = id !== '' ? document.getElementById('showFormTable' + id) : null;
        const container = id !== '' ? document.getElementById('showFormSection' + id) : null;
        if (!table || !container) {
            return;
        }
        marker.setAttribute(READY, '1');
        const sections = config.sections || {};
        let activeSection = '';
        let query = '';

        container.textContent = '';

        const searchWrapper = document.createElement('div');
        searchWrapper.id = 'showFormTable' + id + '_search';
        searchWrapper.className = 'datatable-search';
        searchWrapper.style.cssText = 'display: block; margin: 0 0 6px 0;';
        const search = document.createElement('input');
        search.type = 'search';
        search.id = 'showFormTable' + id + '_search_input';
        search.placeholder = config.search || 'Search';
        search.setAttribute('aria-label', config.search || 'Search');
        search.style.cssText = 'display: block; width: 100%; max-width: 320px;';
        searchWrapper.appendChild(search);
        container.appendChild(searchWrapper);

        const tabList = document.createElement('div');
        tabList.setAttribute('role', 'group');
        tabList.setAttribute('aria-label', config.label || '');
        tabList.id = 'showFormTablist' + id;
        tabList.className = 'prefs-tabs';
        container.appendChild(tabList);

        function appendTab(sectionId, label, sectionName) {
            const tab = document.createElement('button');
            tab.type = 'button';
            tab.id = 'showFormTab' + sectionId;
            tab.className = 'trhead';
            tab.setAttribute('data-showform-tab', id);
            tab.setAttribute('data-section-id', String(sectionId));
            tab.setAttribute('data-section', sectionName || '');
            tab.setAttribute('aria-pressed', 'false');
            tab.style.cssText = 'float: left; cursor: pointer; padding: 5px; border: 1px solid #000000;';
            tab.appendChild(document.createTextNode(label));
            tabList.appendChild(tab);
        }

        appendTab(0, config.all || 'All', '');
        Object.keys(sections).forEach(function (key) {
            appendTab(key, sections[key], sections[key]);
        });

        const spacer = document.createElement('div');
        spacer.style.display = 'block';
        spacer.innerHTML = '&nbsp;';
        container.appendChild(spacer);

        // Posted with the form, so the page comes back on the same tab.
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'showFormTabIndex[' + id + ']';
        hidden.id = 'showFormTabIndex-' + id;
        container.appendChild(hidden);

        function filter() {
            const needle = query.toLowerCase();
            let visibleIndex = 0;
            table.querySelectorAll('tbody tr').forEach(function (row) {
                const inSection = !activeSection || (row.getAttribute('data-section') || '') === activeSection;
                const matches = !needle || (row.textContent || '').toLowerCase().indexOf(needle) !== -1;
                const show = inSection && matches;
                row.style.display = show ? '' : 'none';
                if (row.classList.contains('trhead')) {
                    return;
                }
                row.classList.remove('trlight', 'trdark');
                if (show) {
                    row.classList.add(visibleIndex % 2 === 0 ? 'trlight' : 'trdark');
                    visibleIndex++;
                }
            });
        }

        function activate(tab) {
            activeSection = tab.getAttribute('data-section') || '';
            hidden.value = tab.getAttribute('data-section-id') || '0';
            tabList.querySelectorAll('[data-showform-tab]').forEach(function (other) {
                other.classList.remove('is-active');
                other.setAttribute('aria-pressed', 'false');
                other.tabIndex = -1;
            });
            tab.classList.add('is-active');
            tab.setAttribute('aria-pressed', 'true');
            tab.tabIndex = 0;
            table.setAttribute('aria-labelledby', tab.id);
            filter();
        }

        search.addEventListener('input', function () {
            query = search.value;
            filter();
        });
        // Enter in the search field would send the form.
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });

        tabList.addEventListener('click', function (event) {
            const tab = event.target.closest('[data-showform-tab]');
            if (tab && tabList.contains(tab)) {
                activate(tab);
            }
        });

        tabList.addEventListener('keydown', function (event) {
            const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
            if (keys.indexOf(event.key) === -1) {
                return;
            }
            const tabs = Array.prototype.slice.call(tabList.querySelectorAll('[data-showform-tab]'));
            if (!tabs.length) {
                return;
            }
            const current = Math.max(0, tabs.indexOf(document.activeElement));
            let next = current;
            if (event.key === 'ArrowLeft') {
                next = (current - 1 + tabs.length) % tabs.length;
            } else if (event.key === 'ArrowRight') {
                next = (current + 1) % tabs.length;
            } else if (event.key === 'Home') {
                next = 0;
            } else {
                next = tabs.length - 1;
            }
            event.preventDefault();
            tabs[next].focus();
            activate(tabs[next]);
        });

        const start = document.getElementById('showFormTab' + (sections[config.start] !== undefined ? config.start : 0));
        activate(start && tabList.contains(start) ? start : tabList.firstChild);
    }

    function setupAll(root) {
        (root || document).querySelectorAll('[data-lotgd-showform]').forEach(setup);
    }

    // Before any other submit handler (htmx sends boosted forms from its own).
    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!form || !form.hasAttribute || !form.hasAttribute('data-lotgd-min-password')) {
            return;
        }
        const min = parseInt(form.getAttribute('data-lotgd-min-password') || '0', 10);
        const field = form.querySelector('[name="pass1"]');
        if (field && field.value.length > 0 && field.value.length < min) {
            event.preventDefault();
            event.stopPropagation();
            window.alert(form.getAttribute('data-lotgd-password-warning') || '');
        }
    }, true);

    document.addEventListener('htmx:afterSettle', function () {
        setupAll(document);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setupAll(document);
        });
    } else {
        setupAll(document);
    }

    window.lotgd = window.lotgd || {};
    window.lotgd.showform = { setup: setupAll };
}());
