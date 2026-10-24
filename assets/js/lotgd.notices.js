/*
 * The notices in the upper right (Lotgd\Page\SessionPanel, Lotgd\Page\Notices,
 * docs/ReactiveCore.md), for every logged-in player, stacked:
 * - the session: a countdown of its last minutes with "Stay logged in", and
 *   a red note once it has timed out;
 * - new mail, with "Read" (the mail, in the dialog where the skin has one);
 * - a new Message of the Day, with "Read".
 * Mail and the Message of the Day can be dismissed; a dismissed one stays
 * away on the next pages (localStorage).
 *
 * It starts from <meta name="lotgd-session">. The session is counted
 * locally, once a second. Anything that reached the server as the player
 * counts as activity and starts the count again: a page swapped in place, a
 * popup page in the dialog, and, from the async calls, the time left they
 * report (window.lotgd.notices.sync(), called by lotgd.async.js).
 *
 * Mail and the Message of the Day are looked for every `poll` seconds
 * (setting notices_poll_seconds) with one call, Notices.status -- unless an
 * answer that carries them came in meanwhile: the AJAX poll does
 * (window.lotgd.notices.update()), and a realtime announcement of mail asks
 * at once. The header's mail link follows, and so does the tab's title.
 *
 * Tabs share the session's count through localStorage, so playing in one
 * keeps the others quiet. With never_timeout_if_browser_open the page keeps
 * the session alive itself, without a word, and shows the session only when
 * that fails.
 *
 * It lives outside the regions, so a page swapped in place keeps it.
 */
(function () {
    'use strict';

    const ENDPOINT = '/async/process.php';
    const STORAGE_KEY = 'lotgdSessionDeadline';
    // The newest mail and the Message of the Day the player has seen to.
    const MAIL_KEY = 'lotgdNoticeMail';
    const MOTD_KEY = 'lotgdNoticeMotd';
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
    function stack() {
        let box = document.querySelector('.lotgd-notices');
        if (!box) {
            box = document.createElement('div');
            box.className = 'lotgd-notices';
            document.body.appendChild(box);
        }
        return box;
    }

    function build() {
        panel = document.createElement('div');
        panel.className = 'lotgd-notice lotgd-timeout';
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

        // The session first: it is the one that cannot wait.
        stack().insertBefore(panel, stack().firstChild);
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
    function post(className, method) {
        const body = new URLSearchParams();
        body.set('call', JSON.stringify({ class: className, method: method, args: [] }));
        return window.fetch(new URL(ENDPOINT, window.location.origin).href, {
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
                // Logged out on the server.
                return { session: { remaining: 0 } };
            }
            return response.json().then(function (payload) {
                if (!response.ok || !payload || payload.status !== 'ok' || !payload.data) {
                    throw new Error(className + '.' + method + ' ' + response.status);
                }
                return payload.data;
            });
        });
    }

    function keepAlive(fromPlayer) {
        if (busy) {
            return;
        }
        busy = true;
        render();
        post('Timeout', 'keepAlive').then(function (data) {
            const session = data.session;
            if (!session || typeof session.remaining !== 'number') {
                throw new Error('keep-alive');
            }
            return session;
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

    /*
     * Mail and the Message of the Day.
     */
    const seen = { mail: 0, unread: 0, motd: '' };
    const items = {};
    let lastStatus = 0;
    let asking = false;

    function stored(key) {
        try {
            return window.localStorage.getItem(key) || '';
        } catch (error) {
            return '';
        }
    }

    function store(key, value) {
        try {
            window.localStorage.setItem(key, String(value));
        } catch (error) {
            // Without storage a dismissed notice comes back on the next page.
        }
    }

    // A popup page: in the dialog where the skin has one, else its window.
    function openPage(url) {
        if (config.overlay && window.lotgd && window.lotgd.overlay && typeof window.lotgd.overlay.open === 'function') {
            window.lotgd.overlay.open(url);
            return;
        }
        const size = String(config.popup || '550x330').split('x');
        window.open(url, url.replace(/\W/g, ''), 'scrollbars=yes,resizable=yes,width=' + size[0] + ',height=' + size[1]);
    }

    function notice(kind, title, text, url, done) {
        let item = items[kind];
        if (!item) {
            item = document.createElement('div');
            item.className = 'lotgd-notice lotgd-notice--' + kind;
            item.setAttribute('role', 'status');
            item.setAttribute('aria-live', 'polite');
            const head = document.createElement('div');
            head.className = 'lotgd-notice__title';
            const dismiss = document.createElement('button');
            dismiss.type = 'button';
            dismiss.className = 'lotgd-notice__dismiss';
            dismiss.setAttribute('aria-label', label('dismiss', 'Dismiss'));
            dismiss.addEventListener('click', function () {
                item.hidden = true;
                item.lotgdDone();
            });
            const body = document.createElement('p');
            body.className = 'lotgd-notice__text';
            const read = document.createElement('a');
            read.className = 'lotgd-timeout__button lotgd-notice__button';
            read.setAttribute('role', 'button');
            read.addEventListener('click', function (event) {
                event.preventDefault();
                item.hidden = true;
                item.lotgdDone();
                openPage(read.getAttribute('href'));
            });
            item.appendChild(dismiss);
            item.appendChild(head);
            item.appendChild(body);
            item.appendChild(read);
            stack().appendChild(item);
            items[kind] = item;
        }
        item.lotgdDone = done;
        item.querySelector('.lotgd-notice__title').textContent = title;
        item.querySelector('.lotgd-notice__text').textContent = text;
        const read = item.querySelector('.lotgd-notice__button');
        read.textContent = label('read', 'Read');
        read.setAttribute('href', url);
        item.hidden = false;
    }

    function drop(kind) {
        if (items[kind]) {
            items[kind].hidden = true;
        }
    }

    function title(unread) {
        const base = document.title.replace(/^\(\d+\) /, '');
        document.title = unread > 0 ? '(' + unread + ') ' + base : base;
    }

    function desktop(text) {
        // Only where the player allowed it before; never asked from here.
        if (document.hidden && 'Notification' in window && window.Notification.permission === 'granted') {
            try {
                new window.Notification(label('mailTitle', 'New mail'), { body: text });
            } catch (error) {
                // Some browsers only allow it from a service worker.
            }
        }
    }

    /** What the server says about mail and the Message of the Day. */
    function update(status) {
        if (!config || !status || typeof status !== 'object') {
            return;
        }
        lastStatus = Date.now();
        const mail = status.mail || {};
        const unread = Math.max(0, Number(mail.unread) || 0);
        const lastId = Math.max(0, Number(mail.lastId) || 0);
        if (typeof status.mailLink === 'string' && (unread !== seen.unread || lastId !== seen.mail)) {
            // The header's link, as the page draws it.
            document.querySelectorAll('[data-lotgd-slot="mail"]').forEach(function (slot) {
                slot.innerHTML = status.mailLink;
            });
        }
        const acknowledged = Number(stored(MAIL_KEY)) || 0;
        if (unread === 0) {
            drop('mail');
            store(MAIL_KEY, Math.max(acknowledged, lastId));
        } else if (lastId > acknowledged) {
            const text = unread === 1
                ? label('mailOne', 'You have 1 unread message.')
                : label('mailMany', 'You have %s unread messages.').replace('%s', String(unread));
            if (lastId > seen.mail) {
                desktop(text);
            }
            notice('mail', label('mailTitle', 'New mail'), text, 'mail.php', function () {
                store(MAIL_KEY, lastId);
            });
        }
        seen.mail = lastId;
        seen.unread = unread;
        title(unread);

        const motd = typeof status.motd === 'string' ? status.motd : '';
        // A page that opens the Message of the Day itself needs no notice.
        const opening = document.querySelector('meta[name="lotgd-overlay-open"][content="motd.php"]');
        if (motd === '' || motd === stored(MOTD_KEY) || opening) {
            drop('motd');
        } else {
            notice('motd', label('motdTitle', 'Message of the Day'), label('motdText', 'There is a new Message of the Day.'), 'motd.php', function () {
                store(MOTD_KEY, motd);
            });
        }
        seen.motd = motd;
    }

    /** Look now (a realtime announcement of mail, the dialog closed). */
    function refresh() {
        if (!config || asking || shown === 'expired') {
            return;
        }
        asking = true;
        post('Notices', 'status').then(function (data) {
            if (data.notices) {
                update(data.notices);
            }
            if (data.session) {
                sync(Number(data.session.remaining));
            }
        }).catch(function () {}).then(function () {
            asking = false;
        });
    }

    function poll() {
        const every = Math.max(0, Number(config.poll) || 0) * 1000;
        if (every === 0) {
            return;
        }
        window.setInterval(function () {
            // An answer that carried the notices came in meanwhile.
            if (Date.now() - lastStatus >= every - 1000) {
                refresh();
            }
        }, every);
    }

    function start() {
        config = readConfig();
        if (!config) {
            return;
        }
        reset();
        timer = window.setInterval(render, 1000);

        // What this page shows; newer mail than that is a notice.
        const mail = config.mail || {};
        seen.mail = Math.max(0, Number(mail.lastId) || 0);
        seen.unread = Math.max(0, Number(mail.unread) || 0);
        if (stored(MAIL_KEY) === '') {
            // The first page in this browser: the mail it shows is known.
            store(MAIL_KEY, seen.mail);
        }
        update({ mail: mail, motd: config.motd || '' });
        lastStatus = Date.now();
        poll();
        // A page swapped in place brings its own title.
        document.addEventListener('htmx:afterSettle', function () {
            title(seen.unread);
        });

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
    window.lotgd.notices = { sync: sync, reset: reset, update: update, refresh: refresh };
    // The name lotgd.async.js and modules used for the session alone.
    window.lotgd.timeout = window.lotgd.notices;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
