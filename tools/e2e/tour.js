const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const results = [];
function check(name, ok, info = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (info ? ' -- ' + info : '')); }
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const settle = (p, ms = 900) => p.waitForTimeout(ms);
(async () => {
  sql(`INSERT INTO settings (setting,value) VALUES ('partial_render','1'),('ajax','0'),('realtime_enabled','0'),('forcedmotdpopup','0') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`UPDATE accounts SET prefs='a:1:{s:13:"partialrender";i:1;}', alive=1, hitpoints=maxhitpoints, lastmotd=NOW(), restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0, clanid=0, clanrank=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid=1`);
  const browser = await chromium.launch(cfg.launch);
  const page = await (await browser.newContext()).newPage();
  const errors = []; page.on('pageerror', e => errors.push(e.message));
  let docs = 0; page.on('request', r => { if (r.resourceType() === 'document') docs++; });
  const swaps = []; page.on('response', r => { if (r.headers()['x-lotgd-partial'] === '1') swaps.push(r.url().replace(BASE, '').split('&c=')[0].split('?c=')[0]); });
  await page.goto(BASE + 'home.php'); await page.fill('#name', 'Admin'); await page.fill('#password', cfg.PASSWORD);
  await page.click("input[value='Log in']"); await page.waitForLoadState('load'); await settle(page);
  for (let i = 0; i < 25 && !(await page.title()).includes('Square'); i++) {
    const href = await page.evaluate(() => { const l = [...document.querySelectorAll('a')].filter(a => !a.hasAttribute('onclick')); const p = l.find(a => /^village\.php(\?c=|$)/.test(a.getAttribute('href') || '')) || l.find(a => /^newday\.php/.test(a.getAttribute('href') || '')); return p ? p.getAttribute('href') : null; });
    if (!href) break; await page.click(`a[href="${href}"] >> nth=0`); await page.waitForLoadState('load'); await settle(page);
  }
  await page.evaluate(() => { window.__noReload = 1; });
  const docsBefore = docs;
  async function go(prefix, label, titleRe) {
    const href = await page.evaluate(p => { const a = [...document.querySelectorAll('[data-lotgd-region="nav"] a, [data-lotgd-region="content"] a')].find(x => (x.getAttribute('href') || '').replace(/^\//, '').startsWith(p)); return a ? a.getAttribute('href') : null; }, prefix);
    if (!href) { check(label + ' reachable', false, 'no link ' + prefix); return; }
    const n = swaps.length;
    await page.click(`a[href="${href}"] >> nth=0`); await settle(page);
    const title = await page.title();
    check(label + ' swapped in', swaps.length > n && (!titleRe || titleRe.test(title)), title + ' ' + (swaps[swaps.length - 1] || ''));
  }
  await go('gardens.php', 'gardens', /Garden/);
  await go('village.php', 'back to the village');
  await go('hof.php', 'hall of fame');
  await go('hof.php?op=gems', 'hall of fame: gems');
  await go('village.php', 'village');
  await go('list.php', 'list of warriors');
  await go('bio.php', 'a bio');
  await go('list.php', 'back to the list');
  await go('village.php', 'village');
  await go('news.php', 'news');
  await go('village.php', 'village');
  await go('stables.php', 'stables');
  await go('village.php', 'village');
  await go('weapons.php', 'weapons');
  await go('village.php', 'village');
  await go('bank.php', 'bank');
  await go('bank.php?op=deposit', 'deposit form');
  check('the amount field has focus after the swap', await page.evaluate(() => document.activeElement && document.activeElement.name === 'amount'));
  check('no full page load on the tour', docs === docsBefore && (await page.evaluate(() => window.__noReload)) === 1, `${docs - docsBefore} loads`);
  // A booking posts in place since #1618.
  await page.fill('input[name="amount"]', '1');
  const n = docs;
  await page.click('form input[type="submit"]'); await page.waitForLoadState('load'); await settle(page);
  check('a booking posts in place', docs === n && /deposit/i.test(await page.textContent('body')), `${docs - n} loads`);
  // Fights change in place since #1614: no early fallback for an attack.
  const atk = await page.evaluate(() => fetch('/pvp.php?act=attack&name=nobody', { headers: { 'HX-Request': 'true' } }).then(r => r.headers.get('HX-Redirect')));
  check('pvp attack is not sent back for a full page', atk === null || !atk.startsWith('pvp.php'), String(atk));
  check('no page errors', errors.length === 0, errors.join(' | '));
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
  await browser.close();
  process.exit(results.every(Boolean) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
