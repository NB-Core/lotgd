// Notices in the upper right: new mail and a new MoTD, classic and AJAX players.
const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const OUT = process.env.OUT || '.';
const SKIN = process.env.SKIN || 'twig:aurora';
const tag = SKIN.replace(/[:.]/g, '-');
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const results = [];
function check(n, ok, i = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + n + (i ? ' -- ' + i : '')); }
async function login(page, name) {
  await page.goto(BASE + 'home.php'); await page.fill('#name', name); await page.fill('#password', cfg.PASSWORD);
  await page.click("input[value='Log in'], form[action*='login.php'] button[type='submit']");
  await page.waitForLoadState('load'); await page.goto(BASE + 'badnav.php'); await page.waitForLoadState('load');
}
const notice = (p, kind) => p.$eval(`.lotgd-notice--${kind}`, e => ({ hidden: e.hidden, text: e.innerText })).catch(() => null);
async function waitNotice(p, kind, ms, visible = true) {
  const until = Date.now() + ms;
  while (Date.now() < until) { const n = await notice(p, kind); if (visible ? (n && !n.hidden) : (!n || n.hidden)) return n; await p.waitForTimeout(500); }
  return notice(p, kind);
}
(async () => {
  const oldSkin = sql(`SELECT value FROM settings WHERE setting='defaultskin'`);
  sql(`UPDATE settings SET value='${SKIN}' WHERE setting='defaultskin';
       INSERT INTO settings (setting, value) VALUES ('notices_poll_seconds', '15') ON DUPLICATE KEY UPDATE value='15';
       UPDATE accounts SET prefs='a:2:{s:13:"partialrender";i:0;s:4:"ajax";i:0;}', alive=1, location='Degolburg', loggedin=0, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', lastmotd=NOW() WHERE acctid=385;
       UPDATE accounts SET prefs='a:2:{s:13:"partialrender";i:1;s:4:"ajax";i:1;}', alive=1, location='Degolburg', loggedin=0, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', lastmotd=NOW() WHERE acctid=1;
       UPDATE mail SET seen=1 WHERE msgto IN (1, 385);`);
  cfg.clearDataCache(cfg.GAME);
  const b = await chromium.launch(cfg.launch);
  try {
    // A classic player: no partial client, no AJAX.
    const o = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    o.on('pageerror', e => console.log('pageerror(o):', e.message));
    await login(o, 'offe2e');
    check('no notice for the mail the page shows', !((await notice(o, 'mail')) && !(await notice(o, 'mail')).hidden));
    const before = await o.innerText('[data-lotgd-slot="mail"]').catch(() => '');
    await o.evaluate(() => { window.__noReload = 1; });
    sql(`INSERT INTO mail (msgfrom, msgto, subject, body, sent, seen) VALUES (1, 385, 'Notice test', 'Hello', NOW(), 0)`);
    let n = await waitNotice(o, 'mail', 20000);
    check('new mail shows a notice without a reload', n && !n.hidden && /1 unread message/.test(n.text) && await o.evaluate(() => window.__noReload === 1), JSON.stringify(n));
    const after = await o.innerText('[data-lotgd-slot="mail"]').catch(() => '');
    check('the header mail link follows', /1 new/.test(after) && before !== after, before + ' -> ' + after);
    check('the tab title counts it', /^\(1\) /.test(await o.title()), await o.title());
    await o.screenshot({ path: `${OUT}/95-notices-${tag}-mail.png` });
    await o.click('.lotgd-notice--mail .lotgd-notice__button');
    await o.waitForTimeout(1200);
    const dialog = await o.$('.lotgd-overlay[open]');
    check('Read opens the mail', dialog || (await b.contexts()[0].pages()).length > 1, 'dialog=' + !!dialog);
    await o.keyboard.press('Escape'); await o.waitForTimeout(500);
    await o.goto(BASE + 'badnav.php'); await o.waitForTimeout(800);
    n = await notice(o, 'mail');
    check('once read it stays away on the next page', !n || n.hidden);
    // A read message deleted elsewhere: the header's "old" count follows.
    const oldBefore = await o.innerText('[data-lotgd-slot="mail"]');
    sql(`DELETE FROM mail WHERE msgto=385 AND seen=1 ORDER BY messageid LIMIT 1`);
    await o.waitForTimeout(17000);
    const oldAfter = await o.innerText('[data-lotgd-slot="mail"]');
    check('deleting a read message updates the header too', oldBefore !== oldAfter, oldBefore + ' -> ' + oldAfter);

    // A new Message of the Day.
    sql(`INSERT INTO motd (motdtitle, motdbody, motddate, motdtype, motdauthor) VALUES ('Notice MoTD', 'News', NOW() + INTERVAL 1 SECOND, 0, 1)`);
    n = await waitNotice(o, 'motd', 22000);
    check('a new MoTD shows a notice', n && !n.hidden && /Message of the Day/.test(n.text), JSON.stringify(n));
    await (await o.$('.lotgd-notices')).screenshot({ path: `${OUT}/96-notices-${tag}-motd.png` });
    await o.click('.lotgd-notice--motd .lotgd-notice__dismiss');
    check('Dismiss hides it', ((await notice(o, 'motd')) || {}).hidden === true);
    await o.goto(BASE + 'badnav.php'); await o.waitForTimeout(800);
    n = await notice(o, 'motd');
    check('a dismissed MoTD stays away', !n || n.hidden);

    // An AJAX + partial player gets it with the poll.
    const a = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    a.on('pageerror', e => console.log('pageerror(a):', e.message));
    let statusCalls = 0;
    a.on('request', r => { if (r.url().includes('/async/process.php') && /Notices/.test(r.postData() || '')) statusCalls++; });
    await login(a, 'Admin');
    await a.evaluate(() => { window.__noReload = 1; });
    sql(`INSERT INTO mail (msgfrom, msgto, subject, body, sent, seen) VALUES (385, 1, 'Notice test', 'Hello', NOW(), 0)`);
    n = await waitNotice(a, 'mail', 20000);
    check('an AJAX player gets the notice with the poll', n && !n.hidden && await a.evaluate(() => window.__noReload === 1), JSON.stringify(n));
    check('without asking for it separately', statusCalls === 0, 'Notices.status calls: ' + statusCalls);
    // A MoTD notice from before still comes for this player (its own lastmotd).
    const motdA = await waitNotice(a, 'motd', 2000);
    check('the MoTD notice for this player too', motdA && !motdA.hidden);
    await a.screenshot({ path: `${OUT}/97-notices-${tag}-stack.png` });
  } finally {
    await b.close();
    sql(`DELETE FROM mail WHERE subject='Notice test'; DELETE FROM motd WHERE motdtitle='Notice MoTD'; UPDATE settings SET value='${oldSkin}' WHERE setting='defaultskin'; DELETE FROM settings WHERE setting='notices_poll_seconds'`);
    cfg.clearDataCache(cfg.GAME);
  }
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
})().catch(e => { console.error(e); process.exit(2); });
