// The session panel: countdown, Stay logged in, reset on a swap, timed out.
const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const fs = require('fs');
const BASE = cfg.BASE;
const GAME = cfg.GAME;
const OUT = process.env.OUT || '.';
const SKIN = cfg.skin('twig:aurora');
const tag = SKIN.replace(/[:.]/g, '-');
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const results = [];
function check(name, ok, info = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (info ? ' -- ' + info : '')); }
const TIMEOUT = 75, WARN = 60;

async function login(page, name) {
  await page.goto(BASE + 'home.php');
  await page.fill('#name', name); await page.fill('#password', cfg.PASSWORD);
  await page.click("input[value='Log in'], form[action*='login.php'] button[type='submit']");
  await page.waitForLoadState('load'); await page.goto(BASE + 'badnav.php'); await page.waitForLoadState('load');
}
const panel = page => page.$eval('.lotgd-timeout', el => {
  const r = el.getBoundingClientRect();
  return { hidden: el.hidden, cls: el.className, role: el.getAttribute('role'), text: el.innerText, top: r.top, right: r.right, width: r.width, vw: window.innerWidth };
}).catch(() => null);
const visible = async page => { const s = await panel(page); return s && !s.hidden ? s : null; };
async function waitFor(page, pred, ms) {
  const until = Date.now() + ms;
  while (Date.now() < until) { const s = await panel(page); if (pred(s)) return s; await page.waitForTimeout(500); }
  return panel(page);
}

(async () => {
  const restoreSettings = cfg.overrideAsyncSettings(GAME, { start_timeout_show_seconds: WARN });
  const oldSkin = sql(`SELECT value FROM settings WHERE setting='defaultskin'`);
  const oldTimeout = sql(`SELECT value FROM settings WHERE setting='LOGINTIMEOUT'`);
  sql(`${cfg.setting('defaultskin', SKIN)}; INSERT INTO settings (setting,value) VALUES ('partial_render','1') ON DUPLICATE KEY UPDATE value=VALUES(value);
       INSERT INTO settings (setting, value) VALUES ('LOGINTIMEOUT', '${TIMEOUT}') ON DUPLICATE KEY UPDATE value='${TIMEOUT}';
       UPDATE accounts SET prefs='a:1:{s:13:"partialrender";i:1;}', alive=1, hitpoints=100, maxhitpoints=100, badguy='', location='Degolburg', loggedin=0, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}' WHERE acctid=1;
       UPDATE accounts SET prefs='a:2:{s:13:"partialrender";i:0;s:4:"ajax";i:0;}', alive=1, hitpoints=100, maxhitpoints=100, badguy='', location='Degolburg', loggedin=0, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}' WHERE acctid=385;
       DELETE FROM accounts_output WHERE acctid IN (1, 385)`);
  cfg.clearDataCache(GAME);
  const b = await chromium.launch(cfg.launch);
  try {
    const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    p.on('pageerror', e => console.log('pageerror:', e.message));
    await login(p, 'Admin');
    const meta = await p.$eval('meta[name="lotgd-session"]', m => JSON.parse(m.content)).catch(() => null);
    check('the page carries the session meta', meta && meta.timeout === TIMEOUT && meta.warn === WARN && meta.csrf, JSON.stringify(meta && { t: meta.timeout, w: meta.warn }));
    check('no panel while there is time', !(await visible(p)));
    check('no old #notify corner', (await p.$('#notify')) === null);

    let s = await waitFor(p, x => x && !x.hidden, 25000);
    check('the panel appears in the upper right', s && !s.hidden && s.top < 60 && s.vw - s.right < 40 && s.width > 250, JSON.stringify(s));
    check('it counts down in mm:ss', s && /You will be logged out in \d+:[0-5]\d\./.test(s.text) && /Stay logged in/.test(s.text), s && s.text);
    const c1 = await p.$eval('.lotgd-timeout__clock', e => e.textContent); await p.waitForTimeout(2100);
    const c2 = await p.$eval('.lotgd-timeout__clock', e => e.textContent);
    check('the clock runs', c1 !== c2, c1 + ' -> ' + c2);
    await p.screenshot({ path: `${OUT}/90-timeout-${tag}-warn.png` });
    await (await p.$('.lotgd-timeout')).screenshot({ path: `${OUT}/91-timeout-${tag}-panel.png` });

    const before = sql(`SELECT UNIX_TIMESTAMP(laston) FROM accounts WHERE acctid=1`);
    await p.evaluate(() => { window.__noReload = 1; });
    await p.click('.lotgd-timeout__button');
    s = await waitFor(p, x => x && x.hidden, 5000);
    const after = sql(`SELECT UNIX_TIMESTAMP(laston) FROM accounts WHERE acctid=1`);
    check('Stay logged in hides it without leaving the page', s && s.hidden && await p.evaluate(() => window.__noReload === 1), JSON.stringify(s));
    check('and counts as activity on the server', Number(after) > Number(before), before + ' -> ' + after);

    s = await waitFor(p, x => x && !x.hidden, 25000);
    check('it comes back when the time runs low again', s && !s.hidden);
    const forest = await p.$eval('[data-lotgd-region="nav"] a[href^="forest.php"]', a => a.getAttribute('href'));
    await p.click(`a[href="${forest}"] >> nth=0`); await p.waitForTimeout(1500);
    s = await panel(p);
    check('a page swapped in place starts the count again', s && s.hidden && (await p.title()).includes('Forest') && await p.evaluate(() => window.__noReload === 1), JSON.stringify(s));

    // Let it run out.
    s = await waitFor(p, x => x && /expired/.test(x.cls), (TIMEOUT + 10) * 1000);
    check('at zero it says the session has timed out', s && /lotgd-timeout--expired/.test(s.cls) && s.role === 'alert' && /Your session has timed out/.test(s.text) && /Log in again/.test(s.text), JSON.stringify(s));
    await p.screenshot({ path: `${OUT}/92-timeout-${tag}-expired.png` });
    await (await p.$('.lotgd-timeout')).screenshot({ path: `${OUT}/93-timeout-${tag}-expired-panel.png` });
    await p.click('.lotgd-timeout__button'); await p.waitForLoadState('load');
    check('Log in again leads to the login, with the timeout message', (await p.$('#password')) !== null && /timed out/.test(await p.innerText('body')), p.url());

    // A classic player: no partial client, no AJAX.
    const o = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    o.on('pageerror', e => console.log('pageerror(o):', e.message));
    await login(o, 'offe2e');
    check('a classic player has no async client', !(await o.$('meta[name="lotgd-async"]')) && !(await o.$('meta[name="lotgd-page-revision"]')));
    s = await waitFor(o, x => x && !x.hidden, 25000);
    check('and gets the panel too', s && !s.hidden && /logged out in/.test(s.text), s && s.text);
    await o.click('.lotgd-timeout__button');
    s = await waitFor(o, x => x && x.hidden, 5000);
    check('and can stay logged in', s && s.hidden);
  } finally {
    await b.close();
    restoreSettings();
    sql(`${cfg.setting('defaultskin', oldSkin)}`);
    sql(oldTimeout === '' ? `DELETE FROM settings WHERE setting='LOGINTIMEOUT'` : `${cfg.setting('LOGINTIMEOUT', oldTimeout)}`);
    cfg.clearDataCache(GAME);
  }
  const passed = results.filter(Boolean).length;
  console.log(`${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
