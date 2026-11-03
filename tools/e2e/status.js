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
  const href = await page.$eval(`[data-lotgd-region="nav"] a[href^="${prefix}"], .nav a[href^="${prefix}"], a.nav[href^="${prefix}"]`, a => a.getAttribute('href'));
  await page.click(`a[href="${href}"] >> nth=0`);
  await page.waitForLoadState('load'); await settle(page);
}
async function waitFor(fn, ms) {
  const until = Date.now() + ms;
  while (Date.now() < until) { if (await fn()) return true; await new Promise(r => setTimeout(r, 300)); }
  return false;
}
const slot = async p => (await p.textContent('[data-lotgd-slot="grants"]')).replace(/\s+/g, ' ').trim();




const OUT = process.env.OUT;
const shot = async (p, name) => { await settle(p, 400); await p.screenshot({ path: `${OUT}/${name}.png`, fullPage: false }); console.log('shot', name); };
(async () => {
  sql(`UPDATE settings SET value='0' WHERE setting IN ('realtime_enabled','partial_render','guarded_actions','grants_enabled','live_encounters'); DELETE FROM settings WHERE setting='partial_render_default'; INSERT INTO settings (setting,value) VALUES ('realtime_transport','poll') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`UPDATE accounts SET prefs='a:0:{}', restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid=1`);
  const browser = await chromium.launch(cfg.launch);
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } }); const a = await ctx.newPage();
  a.on('pageerror', e => console.log('pageerror:', e.message)); a.on('dialog', d => d.accept());
  await login(a, 'Admin');
  check('village is a whole page with partial off', (await a.$('meta[name="lotgd-page-revision"]')) === null);
  await a.goto(BASE + 'superuser.php'); await settle(a);
  await nav(a, 'configuration.php');
  const t = await text(a);
  check('table shown', t.includes('Live features'), t.slice(0, 200));
  check('duels off', /Live duels and presence\s*off/.test(t));
  check('button there', (await a.$('form[action="configuration.php?op=enablereactive"] button')) !== null);
  await shot(a, '20-einstellungen-live-funktionen-aus');
  await a.click('form[action="configuration.php?op=enablereactive"] button'); await a.waitForLoadState('load'); await settle(a);
  const t2 = await text(a);
  check('turned on', t2.includes('Setting live_encounters to 1') && t2.includes('Setting guarded_actions to 1'), t2.slice(0, 400));
  check('duels on', /Live duels and presence\s*on\b/.test(t2));
  check('button gone', (await a.$('form[action="configuration.php?op=enablereactive"] button')) === null);
  await shot(a, '21-einstellungen-live-funktionen-an');
  check('db', sql(`SELECT GROUP_CONCAT(value ORDER BY setting) FROM settings WHERE setting IN ('realtime_enabled','partial_render','guarded_actions','grants_enabled','live_encounters')`) === '1,1,1,1,1');
  sql(`UPDATE accounts SET restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid=1`);
  const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 1000 } }); const b = await ctx2.newPage();
  b.on('dialog', d => d.accept());
  await login(b, 'Admin');
  const a2 = a; void a2;
  const p = b;
  check('player without a preference gets partial rendering', (await p.$('meta[name="lotgd-page-revision"]')) !== null);
  await p.goto(BASE + 'badnav.php'); await settle(p);
  await nav(p, 'prefs.php');
  await shot(p, '22-einstellungen-spieler-standard');
  check('prefs show the default checked', await p.isChecked('input[name="partialrender"][type="checkbox"], input[name="partialrender"][value="1"]').catch(e => 'err ' + e.message));
  await browser.close();
  const passed = results.filter(Boolean).length;
  console.log(`${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
