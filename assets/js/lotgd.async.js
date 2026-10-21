/*
 * The async client (docs/ReactiveCore.md, Phase 6): one transport, one poll.
 *
 * Every async call goes to async/process.php as a POST with one field,
 * `call` ({class, method, args}), the header X-Lotgd-Async (a custom header,
 * so a cross-site request is preflighted and never answered) and the async
 * CSRF token. The answer is JSON: commands this client carries out (assign,
 * append, title, call -- only functions registered with allow()) and data for
 * the caller.
 *
 * The page says what to poll in <meta name="lotgd-async"> (written by
 * async/setup.php): comment section and last ids, the interval, whether the
 * player's updates (mail, timeout, commentary) are polled at all. A realtime
 * page (Phase 4) adds lotgd-realtime, lotgd-event-cursor and
 * lotgd-region-versions; its announcements ride on the same poll, and a
 * region is fetched again only when an announcement names a version higher
 * than the one shown -- an old event that arrives late never replaces newer
 * content.
 *
 * For code written against the Jaxon client this file keeps
 * window.getJaxonHandlers() and window.Lotgd.Async.Handler.<Class>.<method>()
 * (both deprecated): each call goes through call() and applies its commands.
 */
(function () {
    'use strict';

    if (window.lotgd && window.lotgd.async) {
        return;
    }

    const ENDPOINT = '/async/process.php';
    const REGION_SELECTORS = {
        mail: '[data-lotgd-region="header"][data-lotgd-slot="mail"]',
        grants: '[data-lotgd-region="header"][data-lotgd-slot="grants"]',
        stats: '[data-lotgd-region="stats"]'
    };
    const allowed = {};
    // Functions a page defines after this file loads and that answers may
    // call by name: the passkey bridge of the twofactorauth module.
    const LATE_GLOBALS = ['twofactorauthHandleJaxonResponse'];
    let config = null;

    function readMeta(name) {
        const meta = document.querySelector('meta[name="' + name + '"]');
        return meta ? (meta.getAttribute('content') || '') : null;
    }

    function settings() {
        if (config !== null) {
            return config;
        }
        try {
            config = JSON.parse(readMeta('lotgd-async') || 'null') || {};
        } catch (error) {
            config = {};
        }
        return config;
    }

    function debug() {
        if (settings().debug) {
            console.log.apply(console, ['[LotGD async]'].concat(Array.prototype.slice.call(arguments)));
        }
    }

    /*
     * Commands. Only innerHTML is ever set, only allowed functions are run.
     */
    function apply(commands) {
        (commands || []).forEach(function (command) {
            if (!command || typeof command.cmd !== 'string') {
                return;
            }
            if (command.cmd === 'assign' || command.cmd === 'append') {
                const element = document.getElementById(String(command.id));
                if (!element || command.prop !== 'innerHTML') {
                    return;
                }
                if (command.cmd === 'assign') {
                    element.innerHTML = String(command.value);
                } else {
                    element.insertAdjacentHTML('beforeend', String(command.value));
                }
            } else if (command.cmd === 'title') {
                document.title = String(command.value);
            } else if (command.cmd === 'call') {
                const fn = allowed[command.fn]
                    || (LATE_GLOBALS.indexOf(command.fn) !== -1 ? window[command.fn] : undefined);
                if (typeof fn === 'function') {
                    fn.apply(null, Array.isArray(command.args) ? command.args : []);
                } else {
                    debug('refused call', command.fn);
                }
            }
        });
    }

    /**
     * Run a server call; resolves with the answer's data after its commands
     * have run, rejects with {status, payload} for any other answer.
     */
    function call(className, method, args) {
        // The token goes to this site's endpoint and nowhere else, whatever
        // the page says the url is.
        const target = new URL(settings().url || ENDPOINT, window.location.origin);
        if (!(target.origin === window.location.origin && target.pathname === ENDPOINT)) {
            return Promise.reject(new Error('async endpoint refused: ' + target.href));
        }
        const body = new URLSearchParams();
        body.set('call', JSON.stringify({ class: className, method: method, args: args || [] }));
        return window.fetch(target.href, {
            method: 'POST',
            mode: 'same-origin',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'X-Lotgd-Async': '1',
                'X-LotGD-Csrf': settings().csrf || '',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body
        }).then(function (response) {
            return response.json().catch(function () {
                return null;
            }).then(function (payload) {
                if (response.status === 403 && payload && payload.error === 'csrf_invalid') {
                    reloadOnce();
                }
                if (!response.ok || !payload || payload.status !== 'ok') {
                    const error = new Error('async ' + className + '.' + method + ' ' + response.status);
                    error.status = response.status;
                    error.payload = payload;
                    throw error;
                }
                apply(payload.commands);
                return payload.data || {};
            });
        });
    }

    // A token from before the session changed: reload once to get a new one.
    function reloadOnce() {
        try {
            if (window.sessionStorage.getItem('lotgdAsyncReloaded') === '1') {
                return;
            }
            window.sessionStorage.setItem('lotgdAsyncReloaded', '1');
        } catch (error) {
            return;
        }
        window.location.reload();
    }

    function allow(name, fn) {
        if (typeof name === 'string' && typeof fn === 'function') {
            allowed[name] = fn;
        }
    }

    /*
     * Notifications. The page knows the last mail it showed; a newer mail or
     * a higher unread count is announced when the window is not in front.
     */
    const state = { section: '', lastCommentId: 0, lastMailId: 0, mailCount: 0 };
    // The live comment fetch (commentRefresh()): running, and the newest
    // comment id announced but not fetched yet.
    const comments = { fetching: false, wanted: 0 };
    // The pause before fetching the rest of an answer the limit cut short.
    const MORE_DELAY = 1500;

    function windowInFront() {
        return document.visibilityState === 'visible' && document.hasFocus();
    }

    function showNotification(title, message) {
        if (!('Notification' in window)) {
            return;
        }
        const options = { body: message, icon: settings().icon || '/images/favicon/favicon-32x32.png' };
        if (Notification.permission === 'granted') {
            new Notification(title, options);
        } else if (Notification.permission !== 'denied') {
            Notification.requestPermission().then(function (permission) {
                if (permission === 'granted') {
                    new Notification(title, options);
                }
            });
        }
    }

    function mailNotify(lastId, count) {
        if ((lastId > state.lastMailId || count > state.mailCount) && !windowInFront()) {
            showNotification('Unread game messages', count === 1 ? 'You have 1 unread message' : 'You have ' + count + ' unread messages');
        }
        state.lastMailId = lastId;
        state.mailCount = count;
    }

    function commentNotify(count) {
        if (count > 0 && !windowInFront()) {
            showNotification('Unread comments', count === 1 ? 'A new comment was posted' : count + ' new comments were posted');
        }
    }

    allow('lotgdMailNotify', mailNotify);
    allow('lotgdCommentNotify', commentNotify);
    // Kept as globals for skins and modules that called them.
    window.lotgdShowNotification = showNotification;
    window.lotgdMailNotify = mailNotify;
    window.lotgdCommentNotify = commentNotify;

    /*
     * A partial answer (Phase 2) brings the values of the page it swaps in.
     */
    function setState(values) {
        if (!values) {
            return;
        }
        if (typeof values.commentSection === 'string') {
            if (values.commentSection !== state.section) {
                // What was wanted belongs to the section the page left.
                comments.wanted = 0;
            }
            state.section = values.commentSection;
        }
        if (typeof values.lastCommentId === 'number') {
            state.lastCommentId = values.lastCommentId;
        }
        if (typeof values.lastUnreadMailId === 'number') {
            state.lastMailId = values.lastUnreadMailId;
        }
        if (typeof values.lastUnreadMailCount === 'number') {
            state.mailCount = values.lastUnreadMailCount;
        }
    }

    /*
     * Realtime regions.
     */
    const realtime = {
        on: false,
        cursor: 0,
        versions: {},
        seen: [],
        fetching: {},
        wanted: {}
    };
    let swapRegion = function (element, html) {
        element.innerHTML = html;
    };

    function refresh(region, version) {
        if (!REGION_SELECTORS[region] || version <= (realtime.versions[region] || 0)) {
            return;
        }
        // Remembered until a fetch succeeds, so one that fails is tried
        // again with the next poll.
        realtime.wanted[region] = Math.max(realtime.wanted[region] || 0, version);
        if (realtime.fetching[region]) {
            return;
        }
        realtime.fetching[region] = true;
        const asked = realtime.wanted[region];
        call('Realtime', 'region', [region]).then(function (data) {
            // The answer is the state when it was rendered, whatever version
            // the announcement named. A newer announcement that came while it
            // was on its way is kept and fetched next.
            if ((realtime.wanted[region] || 0) <= asked) {
                delete realtime.wanted[region];
            }
            const answer = data.region;
            if (answer && typeof answer.html === 'string' && answer.version > (realtime.versions[region] || 0)) {
                realtime.versions[region] = answer.version;
                document.querySelectorAll(REGION_SELECTORS[region]).forEach(function (element) {
                    swapRegion(element, answer.html);
                });
            }
        }).catch(function () {}).then(function () {
            realtime.fetching[region] = false;
            if ((realtime.wanted[region] || 0) > asked) {
                refresh(region, realtime.wanted[region]);
            }
        });
    }

    /*
     * Live commentary: an announced comment newer than the last one shown
     * fetches the lines after it, into the section the page shows.
     */
    function commentRefresh(version) {
        if (!state.section || version <= (state.lastCommentId || 0)) {
            if (comments.wanted <= (state.lastCommentId || 0)) {
                comments.wanted = 0;
            }
            return;
        }
        comments.wanted = Math.max(comments.wanted, version);
        if (comments.fetching) {
            return;
        }
        comments.fetching = true;
        const asked = comments.wanted;
        const section = state.section;
        let more = false;
        call('Commentary', 'commentaryRefresh', [section, state.lastCommentId || 0]).then(function (data) {
            if (data.commentary && data.commentary.section === state.section) {
                state.lastCommentId = Math.max(state.lastCommentId, Number(data.commentary.lastId) || 0);
                more = data.commentary.more === true;
            }
            if (more) {
                // Cut short by the server's limit: the rest is fetched next,
                // whatever the announcement named.
                comments.wanted = Number.MAX_SAFE_INTEGER;
            } else if (comments.wanted <= asked) {
                // Fetched: what is left wanted is only what was announced
                // while this was on its way (a line the page does not show,
                // from a locked author, would otherwise be asked for again
                // and again).
                comments.wanted = 0;
            }
        }).catch(function () {
            // Kept in comments.wanted: the next poll tries again.
        }).then(function () {
            comments.fetching = false;
            if (more) {
                // After the rate limit's second (ajax_rate_limit_seconds).
                window.setTimeout(function () {
                    commentRefresh(comments.wanted);
                }, MORE_DELAY);
            } else if (comments.wanted > asked) {
                commentRefresh(comments.wanted);
            }
        });
    }

    function announcements(answer) {
        if (!answer || answer.disabled) {
            realtime.on = false;
            return;
        }
        if (answer.resync) {
            Object.keys(REGION_SELECTORS).forEach(function (region) {
                refresh(region, Number.MAX_SAFE_INTEGER);
            });
            commentRefresh(Number.MAX_SAFE_INTEGER);
        }
        (answer.events || []).forEach(function (event) {
            if (realtime.seen.indexOf(event.id) !== -1) {
                return;
            }
            realtime.seen.push(event.id);
            if (realtime.seen.length > 200) {
                realtime.seen.shift();
            }
            if (event.type === 'comment') {
                commentRefresh(event.version);
            } else {
                refresh(event.region, event.version);
            }
        });
        if (typeof answer.cursor === 'number' && answer.cursor > realtime.cursor) {
            realtime.cursor = answer.cursor;
        }
        Object.keys(realtime.wanted).forEach(function (region) {
            refresh(region, realtime.wanted[region]);
        });
        if (comments.wanted > 0) {
            commentRefresh(comments.wanted);
        }
    }

    /**
     * What a partial answer shows now (X-Lotgd-Region-Versions): a fetch
     * still on its way is applied only if it is newer, and a newer state
     * shown before the swap is fetched again.
     */
    function syncRegionVersions(shown) {
        Object.keys(shown || {}).forEach(function (region) {
            const version = Number(shown[region]) || 0;
            const before = realtime.versions[region] || 0;
            realtime.versions[region] = version;
            if (before > version) {
                refresh(region, before);
            }
        });
    }

    /*
     * The one poll.
     */
    let stopped = false;
    let started = false;

    function interval() {
        const updates = settings().updates ? Number(settings().interval) || 10000 : 0;
        const live = realtime.on ? realtime.interval : 0;
        const choices = [updates, live].filter(function (value) {
            return value > 0;
        });
        return choices.length ? Math.max(5000, Math.min.apply(null, choices)) : 0;
    }

    function pollOnce() {
        if (settings().updates) {
            // No section: the page shows no commentary, none is asked for.
            // With realtime on, new comments are announced, not looked for.
            return call('Commentary', 'pollUpdates', [
                state.section || '',
                state.lastCommentId || 0,
                realtime.on ? realtime.cursor : null
            ]).then(function (data) {
                if (data.commentary && data.commentary.section === state.section) {
                    state.lastCommentId = Math.max(state.lastCommentId, Number(data.commentary.lastId) || 0);
                }
                if (data.realtime) {
                    announcements(data.realtime);
                }
            });
        }
        return call('Realtime', 'poll', [realtime.cursor, state.section || '']).then(function (data) {
            announcements(data.realtime);
        });
    }

    function startPolling() {
        if (started) {
            return;
        }
        started = true;
        let delay = interval();
        if (delay === 0) {
            return;
        }
        const base = delay;
        function tick() {
            if (stopped || interval() === 0) {
                return;
            }
            // A hidden tab keeps polling the player's updates: their desktop
            // notifications are for exactly that case. Only a realtime-only
            // poll (regions nobody looks at) waits for the tab to show.
            if (document.hidden && !settings().updates) {
                window.setTimeout(tick, base);
                return;
            }
            pollOnce().then(function () {
                delay = interval() || base;
            }).catch(function (error) {
                if (error && (error.status === 401 || error.status === 404)) {
                    stopped = true;
                    return;
                }
                // Back off on errors, up to five minutes.
                delay = Math.min(delay * 2, 300000);
                debug('poll failed', error && error.message);
            }).then(function () {
                if (!stopped) {
                    window.setTimeout(tick, delay);
                }
            });
        }
        window.setTimeout(tick, delay);
    }

    function start() {
        const own = settings();
        setState({
            commentSection: typeof own.section === 'string' ? own.section : '',
            lastCommentId: Number(own.lastCommentId) || 0,
            lastUnreadMailId: Number(own.lastMailId) || 0,
            lastUnreadMailCount: Number(own.mailCount) || 0
        });
        const live = readMeta('lotgd-realtime');
        if (live !== null) {
            realtime.on = true;
            realtime.interval = Math.max(5, parseInt(live.split('|').pop() || '10', 10) || 10) * 1000;
            realtime.cursor = parseInt(readMeta('lotgd-event-cursor') || '0', 10) || 0;
            try {
                realtime.versions = JSON.parse(readMeta('lotgd-region-versions') || '{}') || {};
            } catch (error) {
                realtime.versions = {};
            }
        }
        if (own.polling !== false && typeof window.fetch === 'function') {
            startPolling();
        }
    }

    /*
     * Deprecated: the Jaxon client's names, for code that still uses them.
     */
    const legacy = new Proxy({}, {
        get: function (target, className) {
            if (typeof className !== 'string') {
                return undefined;
            }
            return new Proxy({}, {
                get: function (inner, method) {
                    if (typeof method !== 'string' || method === 'then') {
                        return undefined;
                    }
                    return function () {
                        return call(className, method, Array.prototype.slice.call(arguments));
                    };
                }
            });
        }
    });
    window.Lotgd = window.Lotgd || {};
    window.Lotgd.Async = window.Lotgd.Async || {};
    window.Lotgd.Async.Handler = legacy;
    window.getJaxonHandlers = function () {
        return legacy;
    };
    // Code that checks where Jaxon would send its requests finds the endpoint.
    window.jaxon = window.jaxon || { config: { requestURI: ENDPOINT } };

    window.lotgd = window.lotgd || {};
    window.lotgd.async = {
        call: call,
        allow: allow,
        setState: setState,
        // A copy, for diagnostics and tests.
        state: function () {
            return { section: state.section, lastCommentId: state.lastCommentId, lastMailId: state.lastMailId, mailCount: state.mailCount, cursor: realtime.cursor };
        },
        syncRegionVersions: syncRegionVersions,
        // Show a region's current content now (after the mail dialog closed).
        refresh: function (region) {
            if (realtime.on) {
                refresh(region, Number.MAX_SAFE_INTEGER);
            } else if (region === 'mail' && settings().updates) {
                call('Mail', 'mailStatus', [true]).catch(function () {});
            }
        },
        // The partial client swaps regions its own way (init/destroy, htmx).
        setRegionSwapper: function (fn) {
            if (typeof fn === 'function') {
                swapRegion = fn;
            }
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
