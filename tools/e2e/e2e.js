const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const results = [];
function check(name, ok, info = '') { results.push({ name, ok: !!ok }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (info ? ' -- ' + info : '')); }
function revision() { return Number(execSync(cfg.DB_CMD, { input: 'SELECT page_revision FROM accounts WHERE acctid=1' }).toString().trim()); }
const settle = (page, ms = 1200) => page.waitForTimeout(ms);

function setPrefs(extra) {
  const current = execSync(cfg.DB_CMD, { input: 'SELECT prefs FROM accounts WHERE acctid=1' }).toString().trim();
  const merged = execSync(`php -r '$p = @unserialize(stream_get_contents(STDIN)); if (!is_array($p)) { $p = []; } echo serialize(array_merge($p, json_decode($argv[1], true)));' '${JSON.stringify(extra)}'`, { input: current }).toString();
  execSync(cfg.DB_CMD, { input: `UPDATE accounts SET prefs='${merged.replace(/'/g, "''")}' WHERE acctid=1` });
}

async function login(page) {
  await page.goto(BASE + 'home.php');
  await page.fill('#name', 'Admin');
  await page.fill('#password', cfg.PASSWORD);
  // Enter rather than a click: without JavaScript, Playwright never finds the
  // button below the fold stable enough to click.
  await Promise.all([page.waitForNavigation(), page.press('#password', 'Enter')]);
  await page.waitForLoadState('load');
  await settle(page);
}

async function clickHref(page, prefix) {
  const href = await page.$eval(`[data-lotgd-region="nav"] a[href^="${prefix}"], a[href^="${prefix}"]`, a => a.getAttribute('href')).catch(() => null);
  if (!href) return false;
  await page.click(`a[href="${href}"] >> nth=0`);
  await settle(page);
  await page.waitForLoadState('load');
  return true;
}

async function reachVillage(page) {
  for (let i = 0; i < 12 && !(await page.title()).includes('Square'); i++) {
    const href = await page.evaluate(() => {
      const links = [...document.querySelectorAll('a')].filter(a => !a.hasAttribute('onclick'));
      const pick = links.find(a => /^village\.php(\?c=|$)/.test(a.getAttribute('href') || ''))
        || links.find(a => /^newday\.php/.test(a.getAttribute('href') || ''));
      return pick ? pick.getAttribute('href') : null;
    });
    if (!href) break;
    await page.click(`a[href="${href}"] >> nth=0`);
    await settle(page);
    await page.waitForLoadState('load');
  }
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const partial = [];
  page.on('request', r => { if (r.headers()['hx-request'] === 'true') partial.push(r.url()); });
  page.on('pageerror', e => console.log('pageerror:', e.message));

  setPrefs({ partialrender: 1, ajax: 0 });
  await login(page);
  await reachVillage(page);
  check('reached the village', (await page.title()).includes('Square'), page.url() + ' ' + await page.title());
  check('client loaded', await page.evaluate(() => typeof window.htmx === 'object' && !!(window.lotgd && window.lotgd.regions)));
  check('inline quick-key script replaced', !(await page.content()).includes('const quickLinks'));

  // The stored page may come from a run with other preferences, whose head
  // differs once; one round trip brings a page with the current head.
  await clickHref(page, 'forest.php');
  await clickHref(page, 'village.php?c=');

  // 1. Village -> forest: swapped in.
  await page.evaluate(() => { window.__noReload = 1; });
  let r = revision();
  partial.length = 0;
  await clickHref(page, 'forest.php');
  check('forest is swapped in, document kept', await page.evaluate(() => window.__noReload === 1) && (await page.title()).includes('Forest'), await page.title());
  check('exactly one partial request', partial.length === 1, JSON.stringify(partial));
  check('url pushed', /forest\.php/.test(page.url()), page.url());
  const copies = await page.$$eval('[data-lotgd-region="nav"]', els => els.map(e => /healer\.php/.test(e.innerHTML)));
  check('both navigation copies updated', copies.length === 2 && copies.every(Boolean), JSON.stringify(copies));
  check('revision claimed once', revision() === r + 1, `${r} -> ${revision()}`);

  // 2. Forest -> village: off the allow-list, a page change before it runs.
  partial.length = 0;
  r = revision();
  await clickHref(page, 'village.php?c=');
  check('village is swapped in too', (await page.title()).includes('Square') && (await page.evaluate(() => window.__noReload)) === 1 && /village\.php/.test(page.url()), page.url());
  check('the village claimed one revision', revision() === r + 1, `${r} -> ${revision()}`);
  r = revision();
  await clickHref(page, 'bank.php');
  // Since #1597/#1618 the bank is on the allow-list and changes in place.
  check('the bank changes in place too', (await page.title()).includes('Bank') && (await page.evaluate(() => window.__noReload)) === 1, page.url());
  check('the early fallback did not run the bank twice', revision() === r + 1, `${r} -> ${revision()}`);
  await clickHref(page, 'village.php?c=');

  // 3. Quick key for the forest from the village.
  const key = await page.$eval('[data-lotgd-region="nav"] a[href^="forest.php"][accesskey]', a => a.getAttribute('accesskey').replace(/["']/g, '')).catch(() => null);
  await page.evaluate(() => { window.__noReload = 1; });
  partial.length = 0;
  if (key) {
    await page.keyboard.press(key.toLowerCase());
    await settle(page);
  }
  check('quick key goes through the client', !!key && partial.length === 1 && (await page.title()).includes('Forest') && (await page.evaluate(() => window.__noReload)) === 1, `key ${key} ${JSON.stringify(partial)} ${await page.title()}`);

  // 4. Back button: never re-runs an old URL.
  await page.goBack();
  await settle(page);
  await page.waitForLoadState('load');
  check('back goes to the stored page', /badnav\.php/.test(page.url()), page.url() + ' ' + await page.title());

  // 5. Double click from the village on the forest link.
  await reachVillage(page);
  r = revision();
  partial.length = 0;
  const fh = await page.$eval('[data-lotgd-region="nav"] a[href^="forest.php"]', a => a.getAttribute('href'));
  await page.dblclick(`[data-lotgd-region="nav"] a[href="${fh}"] >> nth=0`);
  await settle(page, 2000);
  check('double click sends one request', partial.length === 1, JSON.stringify(partial));
  check('double click claims one revision', revision() === r + 1, `${r} -> ${revision()}`);

  // 6. A second tab with the same page; the first tab moves on.
  const tab2 = await ctx.newPage();
  await tab2.goto(BASE + 'badnav.php');
  const stale = await tab2.$eval('[data-lotgd-region="nav"] a[href^="healer.php"]', a => a.getAttribute('href')).catch(() => null);
  await clickHref(page, 'village.php?c=');
  r = revision();
  if (stale) {
    await tab2.click(`a[href="${stale}"] >> nth=0`);
    await settle(tab2, 2000);
    await tab2.waitForLoadState('load');
  }
  check('stale tab ends on the stored page', !!stale && /badnav\.php/.test(tab2.url()), tab2.url() + ' ' + await tab2.title());
  check('stale tab did not run its link', revision() === r, `${r} -> ${revision()}`);
  await tab2.close();

  // 7. Without JavaScript the game is the classic one.
  const noJs = await browser.newContext({ javaScriptEnabled: false });
  const p3 = await noJs.newPage();
  await login(p3);
  await reachVillage(p3);
  const ok3 = await clickHref(p3, 'forest.php');
  check('without JavaScript links are full pages', ok3 && /forest\.php/.test(p3.url()) && (await p3.title()).includes('Forest'), p3.url());

  await browser.close();
  const failed = results.filter(x => !x.ok);
  console.log(`\n${results.length - failed.length}/${results.length} passed`);
  process.exit(failed.length ? 1 : 0);
})();
