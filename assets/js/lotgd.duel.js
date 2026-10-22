/*
 * The live duel's client (docs/ReactiveCore.md, Phase 7).
 *
 * The duel is drawn by the server into the encounter region
 * (Lotgd\Encounter\Duel::html()): a block marked data-lotgd-duel with its
 * round and the seconds left, and a button per choice. This file posts a
 * choice (Duel.choose), counts the time down, and when it runs out asks for
 * the round once (Duel.resolve) -- both pages do, the server resolves it
 * once. What the round did reaches both pages as an announcement; the region
 * is fetched again right after a move as well, so the page that moved does
 * not wait for it.
 *
 * No inline script: the region is swapped in place, and this file only
 * listens to the document.
 */
(function () {
    'use strict';

    if (window.lotgd && window.lotgd.duel) {
        return;
    }

    // Rounds asked for already, by "encounter:round".
    const asked = {};
    // When the time of a round runs out, by "encounter:round", in ms.
    const ends = {};

    function async() {
        return window.lotgd && window.lotgd.async ? window.lotgd.async : null;
    }

    function refresh() {
        const client = async();
        if (client) {
            client.refresh('encounter');
        }
    }

    function key(block) {
        return block.getAttribute('data-lotgd-duel') + ':' + block.getAttribute('data-lotgd-duel-round');
    }

    function resolve(block, tries) {
        const client = async();
        if (!client) {
            return;
        }
        const id = Number(block.getAttribute('data-lotgd-duel'));
        const round = Number(block.getAttribute('data-lotgd-duel-round'));
        client.call('Duel', 'resolve', [id, round]).then(function (data) {
            const answer = data && data.duel ? data.duel : {};
            if (answer.reason === 'duel-not-yet' && tries > 0) {
                // The server's clock is a little behind this one.
                window.setTimeout(function () {
                    resolve(block, tries - 1);
                }, 1500);
                return;
            }
            refresh();
        }).catch(function () {
            refresh();
        });
    }

    function tick() {
        document.querySelectorAll('[data-lotgd-duel]').forEach(function (block) {
            const id = key(block);
            if (!(id in ends)) {
                ends[id] = Date.now() + (Number(block.getAttribute('data-lotgd-duel-deadline')) || 0) * 1000;
            }
            const left = Math.max(0, Math.ceil((ends[id] - Date.now()) / 1000));
            const counter = block.querySelector('[data-lotgd-duel-left]');
            if (counter) {
                counter.textContent = String(left);
            }
            if (left === 0 && !asked[id]) {
                asked[id] = true;
                // Both pages ask; a little apart, the second finds it done.
                window.setTimeout(function () {
                    resolve(block, 3);
                }, Math.floor(Math.random() * 700));
            }
        });
    }

    document.addEventListener('click', function (event) {
        const button = event.target instanceof Element ? event.target.closest('[data-lotgd-duel-choice]') : null;
        const block = button ? button.closest('[data-lotgd-duel]') : null;
        const client = async();
        if (!button || !block || !client) {
            return;
        }
        event.preventDefault();
        block.querySelectorAll('[data-lotgd-duel-choice]').forEach(function (other) {
            other.disabled = true;
        });
        client.call('Duel', 'choose', [
            Number(block.getAttribute('data-lotgd-duel')),
            Number(block.getAttribute('data-lotgd-duel-round')),
            button.getAttribute('data-lotgd-duel-choice')
        ]).then(refresh, refresh);
    });

    window.setInterval(tick, 500);

    window.lotgd = window.lotgd || {};
    window.lotgd.duel = { tick: tick };
}());
