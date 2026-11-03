const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const results = [];
function check(name, ok, info = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (info ? ' -- ' + info : '')); }
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const settle = (p, ms = 900) => p.waitForTimeout(ms);
async function clickNav(page, prefix) {
  const href = await page.$eval(`[data-lotgd-region="nav"] a[href^="${prefix}"]`, a => a.getAttribute('href'));
  await page.click(`[data-lotgd-region="nav"] a[href="${href}"] >> nth=0`);
  await settle(page);
}
(async () => {
  sql(`UPDATE accounts SET restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid=1`);
  const browser = await chromium.launch(cfg.launch);
  const ctx = await browser.newContext();
  const a = await ctx.newPage();
  await a.goto(BASE + 'home.php');
  await a.fill('#name', 'Admin'); await a.fill('#password', cfg.PASSWORD);
  await a.click("input[value='Log in']"); await a.waitForLoadState('load'); await settle(a);
  // Make the stored page one rendered for a partial player, then show it in two tabs.
  await a.goto(BASE + 'badnav.php'); await settle(a);
  await clickNav(a, 'forest.php'); await a.waitForLoadState('load');
  await clickNav(a, 'village.php'); await a.waitForLoadState('load');
  const b = await ctx.newPage();
  await b.goto(BASE + 'badnav.php'); await settle(b);
  console.log('   B meta', await b.$eval('meta[name="lotgd-page-revision"]', m => m.content).catch(() => 'none'), 'head', await b.$eval('meta[name="lotgd-head"]', m => m.content).catch(() => 'none'), 'htmx', await b.evaluate(() => typeof htmx));
  console.log('   A meta', await a.$eval('meta[name="lotgd-page-revision"]', m => m.content).catch(() => 'none'));
  check('both tabs show the village', (await a.title()).includes('Square') && (await b.title()).includes('Square'));
  const revBefore = Number(sql('SELECT page_revision FROM accounts WHERE acctid=1'));
  const statuses = [];
  b.on('response', r => { if (r.request().headers()['hx-request']) { statuses.push(r.status()); console.log('   B', r.status(), r.url().replace(BASE, ''), JSON.stringify(Object.keys(r.request().headers()).filter(k => k.startsWith('x-') || k.startsWith('hx'))), JSON.stringify(Object.fromEntries(Object.entries(r.headers()).filter(([k]) => /^(hx-|x-lotgd)/.test(k))))); } });
  await clickNav(a, 'inn.php');
  check('tab A moved to the inn', (await a.title()).includes('Inn'), await a.title());
  // The exact case Codex describes: tab B follows an address the latest
  // page allows too. Taken from tab A's page, so the c= counter matches.
  console.log('   A links', JSON.stringify(await a.$$eval('[data-lotgd-region="nav"] a', l => l.map(x => x.getAttribute('href')))));
  const sameHref = await a.$eval('[data-lotgd-region="nav"] a[href^="village.php"]', el => el.getAttribute('href'));
  await b.evaluate(href => htmx.ajax('GET', href, { target: '[data-lotgd-region="content"]' }), sameHref).catch(() => {});
  await settle(b); await b.waitForLoadState('load'); await settle(b);
  check('tab B, still showing the old village, is turned away with 409', statuses[0] === 409 && /badnav/.test(b.url()), JSON.stringify(statuses) + ' ' + b.url());
  check('only tab A claimed a revision', Number(sql('SELECT page_revision FROM accounts WHERE acctid=1')) === revBefore + 1);
  await clickNav(a, 'village.php');
  check('tab A carries on', (await a.title()).includes('Square'), await a.title());
  await browser.close();
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
})().catch(e => { console.error(e); process.exit(2); });
