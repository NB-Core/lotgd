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
  sql(`INSERT INTO settings (setting,value) VALUES ('realtime_enabled','1'),('partial_render','1'),('guarded_actions','1'),('realtime_poll_seconds','5') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`DELETE FROM accounts WHERE login='bobe2e'`);
  const cols = sql(`SELECT GROUP_CONCAT(CONCAT('\`',column_name,'\`')) FROM information_schema.columns WHERE table_schema='lotgd' AND table_name='accounts' AND column_name NOT IN ('acctid','login','name','emailaddress','goldinbank','version','transferredtoday','loggedin','allowednavs','restorepage')`);
  sql(`INSERT INTO accounts (login,name,emailaddress,goldinbank,version,transferredtoday,loggedin,allowednavs,restorepage,${cols}) SELECT 'bobe2e','Bob','bob@example.invalid',0,0,0,0,'a:1:{s:11:"village.php";b:1;}','village.php',${cols} FROM accounts WHERE acctid=1`);
  const bob = sql(`SELECT acctid FROM accounts WHERE login='bobe2e'`);
  sql(`UPDATE accounts SET prefs='a:1:{s:13:"partialrender";i:1;}' WHERE acctid=${bob}; DELETE FROM mail WHERE msgto=${bob}`);
  sql(`UPDATE accounts SET gold=100, goldinbank=50, level=5, amountouttoday=0, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid=1`);

  const browser = await chromium.launch(cfg.launch);
  const b = await (await browser.newContext()).newPage();
  const polls = [];
  b.on('request', r => { if (r.url().includes('async/process.php')) { try { const c = JSON.parse(new URLSearchParams(r.postData() || '').get('call')); polls.push(c.class + '.' + c.method + '(' + JSON.stringify(c.args) + ')'); } catch (e) {} } });
  b.on('pageerror', e => console.log('B pageerror:', e.message));
  await login(b, 'bobe2e');
  console.log('B META', b.url(), await b.evaluate(() => [...document.querySelectorAll('meta[name^="lotgd-"]')].map(m => m.name + '=' + m.content).join(' | ')));
  const bankBefore = (await b.textContent('[data-lotgd-region="stats"]')).replace(/\s+/g, ' ');
  check('B sees the realtime meta', await b.$('meta[name="lotgd-realtime"]') !== null);
  check('B starts with no new mail', /0 new/.test(await b.textContent('[data-lotgd-slot="mail"]')), await b.textContent('[data-lotgd-slot="mail"]'));
  await b.evaluate(() => { window.__noReload = 1; });

  const a = await (await browser.newContext()).newPage();
  await login(a, 'Admin');
  await nav(a, 'bank.php');
  await nav(a, 'bank.php?op=transfer');
  await a.fill('form input[name="amount"]', '10');
  await a.fill('form input[name="to"]', 'bobe2e');
  await a.click('form input[type="submit"]'); await a.waitForLoadState('load'); await settle(a);
  await a.click('form[action*="transfer3"] input[type="submit"]'); await a.waitForLoadState('load'); await settle(a);
  check('A completed the transfer', /The transfer has been completed/.test(await text(a)));
  const seqs = sql(`SELECT GROUP_CONCAT(CONCAT(seq,':',type) ORDER BY seq) FROM event_deliveries WHERE channel='user-${bob}'`);
  check('two announcements delivered to B', /mail/.test(seqs) && /bank/.test(seqs), seqs);

  const mailed = await waitFor(async () => /1 new/.test(await b.textContent('[data-lotgd-slot="mail"]')), 15000);
  check('B\'s mail link shows the new mail without a reload', mailed, await b.textContent('[data-lotgd-slot="mail"]'));
  const credited = await waitFor(async () => (await b.textContent('[data-lotgd-region="stats"]')).replace(/\s+/g, ' ') !== bankBefore, 15000);
  const statsNow = (await b.textContent('[data-lotgd-region="stats"]')).replace(/\s+/g, ' ');
  check('B\'s stats show the credit without a reload', credited && /Bankgold\s*10\b/.test(statsNow), statsNow.match(/Bankgold\s*\d+/));
  check('B never reloaded', (await b.evaluate(() => window.__noReload)) === 1);
  check('B polled', polls.some(u => /^(Realtime\.poll|Commentary\.pollUpdates)/.test(u)) && polls.some(u => u.includes('Realtime.region(["mail"])')) && polls.some(u => u.includes('Realtime.region(["stats"])')), JSON.stringify(polls.slice(0, 6)));

  // Setting off: no realtime meta, no polls.
  sql(`UPDATE settings SET value='0' WHERE setting='realtime_enabled'`);
  await b.goto(BASE + 'badnav.php'); await settle(b);
  polls.length = 0;
  await b.waitForTimeout(12000);
  check('setting off: no realtime meta, no polls', await b.$('meta[name="lotgd-realtime"]') === null && polls.length === 0, JSON.stringify(polls));

  sql(`UPDATE settings SET value='1' WHERE setting='realtime_enabled'`);
  sql(`DELETE FROM accounts WHERE login='bobe2e'`);
  await browser.close();
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
  process.exit(results.every(Boolean) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
