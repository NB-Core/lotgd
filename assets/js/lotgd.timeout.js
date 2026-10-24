/*
 * The session panel (Lotgd\Page\SessionPanel, docs/ReactiveCore.md): a
 * notice in the upper right that counts down the last minutes of the
 * player's session, with "Stay logged in", and that says so once the session
 * has timed out.
 *
 * It starts from <meta name="lotgd-session"> and counts locally, once a
 * second. Anything that reached the server as the player counts as activity
 * and starts the count again: a page swapped in place, a popup page in the
 * dialog, and, from the async calls, the time left they report
 * (window.lotgd.timeout.sync(), called by lotgd.async.js). Tabs share the
 * count through localStorage, so playing in one keeps the others quiet.
 *
 * With never_timeout_if_browser_open the panel keeps the session alive
 * itself, without a word, and shows only when that fails.
 *
 * It lives outside the regions, so a page swapped in place keeps it.
 */
(function () {
    'use strict';

    const ENDPOINT = '/async/process.php';
    const STORAGE_KEY = 'lotgdSessionDeadline';
    const GRACE = 2;

    let config = null;
    let deadline = 0;
    let panel = null;
    let shown = '';
    let busy = false;
    // Automatic keep-alives (never_timeout_if_browser_open) after a failure.
    let backoff = 0;
    let retryAt = 0;
    let timer = 0;

    function readConfig() {
        const meta = document.querySelector('meta[name="lotgd-session"]');
        if (!meta) {
            return null;
        }
        try {
            const parsed = JSON.parse(meta.getAttribute('content') || '');
            return parsed && Number(parsed.timeout) > 0 ? parsed : null;
        } catch (error) {
            return null;
        }
    }

    function label(key, fallback) {
        const labels = (config && config.labels) || {};
        return typeof labels[key] === 'string' && labels[key] !== '' ? labels[key] : fallback;
    }

    function clock(seconds) {
        const minutes = Math.floor(seconds / 60);
        const rest = seconds % 60;
        return minutes + ':' + (rest < 10 ? '0' : '') + rest;
    }

    function remember() {
        try {
            window.localStorage.setItem(STORAGE_KEY, String(deadline));
        } catch (error) {
            // Private mode or storage off: each tab counts for itself.
        }
    }

    function setDeadline(at, share) {
        deadline = at;
        if (share) {
            remember();
        }
        render();
    }

    /*
     * The panel. Built once, on first use; its texts come from the page.
     */
    function build() {
        panel = document.createElement('div');
        panel.className = 'lotgd-timeout';
        panel.hidden = true;

        const title = document.createElement('div');
        title.className = 'lotgd-timeout__title';
        panel.appendChild(title);

        const text = document.createElement('p');
        text.className = 'lotgd-timeout__text';
        panel.appendChild(text);

        const action = document.createElement('a');
        action.className = 'lotgd-timeout__button';
        action.setAttribute('role', 'button');
        action.addEventListener('click', function (event) {
            if (shown === 'warn') {
                event.preventDefault();
                keepAlive(true);
            }
        });
        panel.appendChild(action);

        document.body.appendChild(panel);
    }

    function show(kind, left) {
        if (!panel) {
            build();
        }
        const title = panel.querySelector('.lotgd-timeout__title');
        const text = panel.querySelector('.lotgd-timeout__text');
        const action = panel.querySelector('.lotgd-timeout__button');
        if (kind !== shown) {
            shown = kind;
            panel.classList.toggle('lotgd-timeout--expired', kind === 'expired');
            // A polite note while there is time, an alert once it is gone.
            panel.setAttribute('role', kind === 'expired' ? 'alert' : 'status');
            panel.setAttribute('aria-live', kind === 'expired' ? 'assertive' : 'polite');
            title.textContent = label('title', 'Session timeout');
            text.textContent = '';
            if (kind === 'expired') {
                text.textContent = label('expired', 'Your session has timed out.');
                action.textContent = label('login', 'Log in again');
                action.setAttribute('href', 'home.php?op=timeout');
            } else {
                const parts = label('countdown', 'You will be logged out in %s.').split('%s');
                text.appendChild(document.createTextNode(parts[0] || ''));
                const time = document.createElement('strong');
                time.className = 'lotgd-timeout__clock';
                // Read out when the panel appears, not every second.
                time.setAttribute('aria-live', 'off');
                text.appendChild(time);
                text.appendChild(document.createTextNode(parts.slice(1).join('%s')));
                action.textContent = label('stay', 'Stay logged in');
                action.setAttribute('href', 'badnav.php');
            }
            panel.hidden = false;
        }
        if (kind === 'warn') {
            panel.querySelector('.lotgd-timeout__clock').textContent = clock(left);
            action.setAttribute('aria-disabled', busy ? 'true' : 'false');
        }
    }

    function hide() {
        if (panel && shown !== '') {
            panel.hidden = true;
        }
        shown = '';
    }

    function render() {
        if (!config) {
            return;
        }
        const left = Math.ceil((deadline - Date.now()) / 1000);
        const warn = Math.max(0, Number(config.warn) || 0);
        // The server counts in whole seconds from a little earlier: past
        // zero it may still let the player in for a moment.
        if (left <= -GRACE) {
            show('expired', 0);
            stop();
            return;
        }
        if (config.never) {
            // Kept alive by the open browser: ask before the last minute.
            if (left <= Math.max(60, Math.min(warn, 120)) && !busy && Date.now() >= retryAt) {
                keepAlive(false);
            }
            if (shown !== '') {
                hide();
            }
            return;
        }
        if (left <= warn) {
            show('warn', Math.max(0, left));
        } else if (shown !== '') {
            hide();
        }
    }

    function stop() {
        if (timer) {
            window.clearInterval(timer);
            timer = 0;
        }
    }

    /*
     * "Stay logged in": an async call that counts as activity. Should it
     * fail for any reason but a lost session, a page does the same.
     */
    function keepAlive(fromPlayer) {
        if (busy) {
            return;
        }
        busy = true;
        render();
        const body = new URLSearchParams();
        body.set('call', JSON.stringify({ class: 'Timeout', method: 'keepAlive', args: [] }));
        window.fetch(new URL(ENDPOINT, window.location.origin).href, {
            method: 'POST',
            mode: 'same-origin',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'X-Lotgd-Async': '1',
                'X-LotGD-Csrf': (config && config.csrf) || '',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body
        }).then(function (response) {
            if (response.status === 401) {
                return { remaining: 0 };
            }
            return response.json().then(function (payload) {
                const session = payload && payload.status === 'ok' && payload.data ? payload.data.session : null;
                if (!response.ok || !session || typeof session.remaining !== 'number') {
                    throw new Error('keep-alive ' + response.status);
                }
                return session;
            });
        }).then(function (session) {
            busy = false;
            backoff = 0;
            sync(session.remaining);
        }, function () {
            busy = false;
            if (fromPlayer) {
                window.location.href = 'badnav.php';
                return;
            }
            // Asked by the page itself: try again later, less often each time.
            backoff = Math.min(backoff ? backoff * 2 : 5, 60);
            retryAt = Date.now() + backoff * 1000;
        });
    }

    /** The time left as the server counts it. */
    function sync(remaining) {
        if (!config || typeof remaining !== 'number' || !isFinite(remaining)) {
            return;
        }
        // Nothing left is final: the server has logged the player out.
        setDeadline(Date.now() + (remaining > 0 ? remaining * 1000 : -GRACE * 1000), true);
    }

    /** Activity the server counted just now: the full length again. */
    function reset() {
        if (config) {
            setDeadline(Date.now() + Number(config.timeout) * 1000, true);
        }
    }

    function start() {
        config = readConfig();
        if (!config) {
            return;
        }
        reset();
        timer = window.setInterval(render, 1000);

        // A page swapped in place reached the server as the player.
        document.addEventListener('htmx:afterRequest', function (event) {
            const xhr = event.detail && event.detail.xhr;
            if (xhr && xhr.status === 200 && xhr.getResponseHeader('X-Lotgd-Partial') === '1') {
                reset();
            }
        });
        // So did a popup page in the dialog (lotgd.overlay.js).
        document.addEventListener('lotgd:activity', reset);
        // Another tab played: its count is ours.
        window.addEventListener('storage', function (event) {
            if (event.key !== STORAGE_KEY || shown === 'expired') {
                return;
            }
            const at = Number(event.newValue) || 0;
            if (at > deadline) {
                setDeadline(at, false);
            }
        });
        // A tab in the background is woken rarely: catch up when it shows.
        document.addEventListener('visibilitychange', render);
        // Back to a page kept in the browser's memory: count from what is known.
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                render();
            }
        });
    }

    window.lotgd = window.lotgd || {};
    window.lotgd.timeout = { sync: sync, reset: reset };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
