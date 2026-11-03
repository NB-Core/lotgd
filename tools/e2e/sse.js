const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const results = [];
function check(name, ok, info = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (info ? ' -- ' + info : '')); }
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const settle = (p, ms = 700) => p.waitForTimeout(ms);
const text = async p => (await p.textContent('body')).replace(/\s+/g, ' ');

async function login(page, name) {
  await page.goto(BASE + 'home.php');
  await page.fill('#name', name);
  await page.fill('#password', cfg.PASSWORD);
  await page.click("input[value='Log in']");
  await page.waitForLoadState('load'); await settle(page);
  await page.goto(BASE + 'badnav.php'); await settle(page);
  await reachVillage(page);
}
async function reachVillage(page) {
  for (let i = 0; i < 25 && !(await page.title()).includes('Square'); i++) {
    const href = await page.evaluate(() => {
      const links = [...document.querySelectorAll('a')].filter(a => !a.hasAttribute('onclick'));
      const pick = links.find(a => /^village\.php(\?c=|$)/.test(a.getAttribute('href') || '')) || links.find(a => /^newday\.php/.test(a.getAttribute('href') || ''));
      return pick ? pick.getAttribute('href') : null;
    });
    if (!href) break;
    await page.click(`a[href="${href}"] >> nth=0`);
    await page.waitForLoadState('load'); await settle(page);
  }
}
async function nav(page, prefix) {
  const href = await page.$eval(`a[href^="${prefix}"]`, a => a.getAttribute('href'));
  await page.click(`a[href="${href}"] >> nth=0`);
  await page.waitForLoadState('load'); await settle(page);
}
async function waitFor(fn, ms) {
  const until = Date.now() + ms;
  while (Date.now() < until) { if (await fn()) return true; await new Promise(r => setTimeout(r, 300)); }
  return false;
}


(async () => {
  sql(`INSERT INTO settings (setting,value) VALUES ('realtime_enabled','1'),('partial_render','1'),('guarded_actions','1'),('realtime_poll_seconds','5'),('realtime_transport','sse'),('realtime_stream_seconds','20') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`DELETE FROM accounts WHERE login='bobe2e'`);
  const cols = sql(`SELECT GROUP_CONCAT(CONCAT('\`',column_name,'\`')) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='accounts' AND column_name NOT IN ('acctid','login','name','emailaddress','goldinbank','version','transferredtoday','loggedin','allowednavs','restorepage')`);
  sql(`INSERT INTO accounts (login,name,emailaddress,goldinbank,version,transferredtoday,loggedin,allowednavs,restorepage,${cols}) SELECT 'bobe2e','Bob','bob@example.invalid',0,0,0,0,'a:1:{s:11:"village.php";b:1;}','village.php',${cols} FROM accounts WHERE acctid=1`);
  const bob = sql(`SELECT acctid FROM accounts WHERE login='bobe2e'`);
  sql(`UPDATE accounts SET prefs='a:1:{s:13:"partialrender";i:1;}' WHERE acctid=${bob}; DELETE FROM mail WHERE msgto=${bob}`);
  sql(`UPDATE accounts SET gold=100, goldinbank=50, level=5, amountouttoday=0, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid=1`);

  const browser = await chromium.launch(cfg.launch);
  const bctx = await browser.newContext();
  const b = await bctx.newPage();
  const polls = []; const streams = [];
  const watch = (p, tag) => {
    p.on('request', r => {
      if (r.url().includes('async/process.php')) { try { const c = JSON.parse(new URLSearchParams(r.postData() || '').get('call')); polls.push(tag + ':' + c.class + '.' + c.method); } catch (e) {} }
      if (r.url().includes('async/stream.php')) streams.push(tag + ':' + r.url());
    });
    p.on('pageerror', e => console.log(tag, 'pageerror:', e.message));
  };
  watch(b, 'B1');
  await login(b, 'bobe2e');
  check('migration ran in game: realtime_streams exists', sql(`SHOW TABLES LIKE 'realtime_streams'`) === 'realtime_streams');
  check('B sees the stream meta', await b.$('meta[name="lotgd-realtime-stream"]') !== null);
  await b.evaluate(() => { window.__noReload = 1; });
  check('B streams', await waitFor(() => b.evaluate(() => window.lotgd.async.state().streaming), 5000), JSON.stringify(streams));
  polls.length = 0;

  const a = await (await browser.newContext()).newPage();
  watch(a, 'A');
  await login(a, 'Admin');
  await nav(a, 'bank.php');
  await nav(a, 'bank.php?op=transfer');
  await a.fill('form input[name="amount"]', '10');
  await a.fill('form input[name="to"]', 'bobe2e');
  await a.click('form input[type="submit"]'); await a.waitForLoadState('load'); await settle(a, 200);
  const sent = Date.now();
  await a.click('form[action*="transfer3"] input[type="submit"]');
  const mailed = await waitFor(async () => /1 new/.test(await b.textContent('[data-lotgd-slot="mail"]')), 10000);
  const took = Date.now() - sent;
  check('B sees the mail through the stream', mailed, await b.textContent('[data-lotgd-slot="mail"]'));
  check('within 3 s of the transfer', took < 3000, took + ' ms');
  check('B did not poll the realtime part', !polls.some(p => p.startsWith('B1:Realtime.poll')), JSON.stringify(polls));
  check('B never reloaded', (await b.evaluate(() => window.__noReload)) === 1);

  // A second tab of B takes the stream; the first falls back to polling.
  const b2 = await bctx.newPage();
  watch(b2, 'B2');
  await b2.goto(BASE + 'badnav.php'); await settle(b2);
  check('the second tab streams', await waitFor(() => b2.evaluate(() => window.lotgd.async.state().streaming), 5000));
  check('the first tab stopped streaming', await waitFor(() => b.evaluate(() => !window.lotgd.async.state().streaming), 4000));
  check('the first tab polls again', await waitFor(async () => polls.some(p => p.startsWith('B1:Realtime.poll')), 12000), JSON.stringify(polls.slice(-5)));
  await b2.close();

  // A stream that cannot connect: the page polls.
  const c = await (await browser.newContext()).newPage();
  watch(c, 'C');
  await c.route('**/async/stream.php*', r => r.abort());
  sql(`UPDATE accounts SET loggedin=0 WHERE acctid=${bob}`);
  await login(c, 'bobe2e');
  check('a blocked stream falls back to polling', await waitFor(async () => polls.some(p => p.startsWith('C:Realtime.poll')), 15000), JSON.stringify(streams.filter(s => s.startsWith('C'))));

  // The lifetime ends a stream, the browser reconnects with Last-Event-ID.
  const d = await (await browser.newContext()).newPage();
  watch(d, 'D');
  const t0 = Date.now();
  d.on('response', async r => { if (r.url().includes('stream.php') && r.status() !== 200) console.log('D', ((Date.now()-t0)/1000).toFixed(1), r.status(), await r.text().catch(() => '')); if (r.url().includes('process.php')) console.log('D poll', r.status()); });
  d.on('console', m => console.log('D console', m.text()));
  await d.addInitScript(() => { const ES = window.EventSource; window.EventSource = function (u) { const e = new ES(u); ['replaced','end'].forEach(n => e.addEventListener(n, () => console.log('ES', n))); e.addEventListener('error', () => console.log('ES error', e.readyState)); return e; }; });
  sql(`UPDATE accounts SET loggedin=0 WHERE acctid=${bob}`);
  await login(d, 'bobe2e');
  await d.waitForTimeout(32000);
  check('after its lifetime the stream reconnected', streams.filter(s => s.startsWith('D')).length >= 2 && await d.evaluate(() => window.lotgd.async.state().streaming), JSON.stringify(streams.filter(s => s.startsWith('D'))));

  // A swap to a page without commentary reopens the stream without the section.
  const before = streams.length;
  await nav(d, 'forest.php');
  check('the forest reopens the stream without a section', await waitFor(async () => streams.slice(before).some(u => /section=$/.test(u)), 5000) && (await d.evaluate(() => window.__noReload === undefined || true)), JSON.stringify(streams.slice(before)));
  check('and it streams', await waitFor(() => d.evaluate(() => window.lotgd.async.state().streaming), 5000));

  // Polling setting: no stream.
  sql(`UPDATE settings SET value='poll' WHERE setting='realtime_transport'`);
  await d.goto(BASE + 'badnav.php'); await settle(d);
  check('with polling, no stream meta', await d.$('meta[name="lotgd-realtime-stream"]') === null);

  sql(`DELETE FROM accounts WHERE login='bobe2e'`);
  await browser.close();
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
  process.exit(results.every(Boolean) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
