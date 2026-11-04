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

async function post(page, words) {
  const form = page.locator('form:has(input[name="insertcommentary"])').first();
  await form.locator('input[name="insertcommentary"]').fill(words);
  await form.locator('input[type="submit"]').first().click();
  await page.waitForLoadState('load'); await settle(page);
}
function clone(login, prefs) {
  sql(`DELETE FROM accounts WHERE login='${login}'`);
  const cols = cfg.accountColumns(sql, ['acctid','login','name','emailaddress','clanid','clanrank','loggedin','allowednavs','restorepage','prefs']);
  sql(`INSERT INTO accounts (login,name,emailaddress,clanid,clanrank,loggedin,allowednavs,restorepage,prefs,${cols}) SELECT '${login}','${login}','${login}@example.invalid',0,0,0,'a:1:{s:11:"village.php";b:1;}','village.php','${prefs}',${cols} FROM accounts WHERE acctid=1`);
  return sql(`SELECT acctid FROM accounts WHERE login='${login}'`);
}

(async () => {
  sql(`INSERT INTO settings (setting,value) VALUES ('realtime_enabled','1'),('partial_render','1'),('realtime_poll_seconds','5'),('ajax','1') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  const bob = clone('bobe2e', 'a:1:{s:13:"partialrender";i:1;}');
  const carl = clone('carle2e', 'a:1:{s:4:"ajax";i:1;}');
  const clan = sql(`SELECT MIN(clanid) FROM clans`);
  sql(`UPDATE accounts SET clanid=${clan}, clanrank=10, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid IN (1,${bob},${carl})`);

  const browser = await chromium.launch(cfg.launch);
  const calls = [];
  const watch = (p, who) => {
    p.on('request', r => { if (r.url().includes('async/process.php')) { try { const c = JSON.parse(new URLSearchParams(r.postData() || '').get('call')); calls.push(who + ':' + c.class + '.' + c.method + JSON.stringify(c.args)); } catch (e) {} } });
    p.on('pageerror', e => console.log(who + ' pageerror:', e.message));
  };
  const b = await (await browser.newContext()).newPage(); watch(b, 'B');
  const c = await (await browser.newContext()).newPage(); watch(c, 'C');
  const a = await (await browser.newContext()).newPage(); watch(a, 'A');
  await login(b, 'bobe2e'); await login(c, 'carle2e'); await login(a, 'Admin');
  check('B and C are in the village', (await b.title()).includes('Square') && (await c.title()).includes('Square'));
  const meta = JSON.parse(await b.getAttribute('meta[name="lotgd-async"]', 'content'));
  check('B\'s page names its section', meta.section === 'village', meta.section);
  await b.evaluate(() => { window.__noReload = 1; }); await c.evaluate(() => { window.__noReload = 1; });

  const line = 'Live hello ' + Date.now();
  calls.length = 0;
  await post(a, line);
  check('the comment was announced on the section', /comment/.test(sql(`SELECT GROUP_CONCAT(type) FROM event_deliveries WHERE channel='section-village'`)));
  const seenB = await waitFor(async () => (await text(b)).includes(line), 15000);
  check('B (realtime) sees the comment without a reload', seenB && (await b.evaluate(() => window.__noReload)) === 1);
  const bCalls = calls.filter(x => x.startsWith('B:'));
  check('B fetched it once, after the announcement', bCalls.filter(x => x.includes('commentaryRefresh')).length === 1, JSON.stringify(bCalls));
  check('B\'s polls look for no comments themselves', bCalls.filter(x => /pollUpdates|Realtime\.poll/.test(x)).every(x => /Realtime\.poll\[\d+,"village"\]|pollUpdates\["village",\d+,\d+\]/.test(x)), JSON.stringify(bCalls.slice(0, 4)));
  const seenC = await waitFor(async () => (await text(c)).includes(line), 20000);
  check('C (AJAX only, no realtime) sees it by polling', seenC && (await c.evaluate(() => window.__noReload)) === 1);
  check('the line shows once on B', (await text(b)).split(line).length === 2);

  // Many at once: 60 comments, one announcement; the 50-line limit is drained.
  sql(`INSERT INTO commentary (postdate,section,author,comment) SELECT NOW(),'village',1,CONCAT('Bulk line ',seq) FROM seq_1_to_59`);
  const last = 'Bulk last ' + Date.now();
  calls.length = 0;
  await post(a, last);
  const drained = await waitFor(async () => (await text(b)).includes(last), 20000);
  const bulkB = (await text(b)).match(/Bulk line \d+/g) || [];
  check('60 comments at once: B gets them all', drained && new Set(bulkB).size === 59, 'lines=' + new Set(bulkB).size + ' ' + JSON.stringify(calls.filter(x => x.startsWith('B:') && x.includes('Refresh'))));

  // A failed fetch is tried again with the next poll.
  let blocked = 0;
  await b.route('**/async/process.php', async route => {
    const body = route.request().postData() || '';
    if (!blocked && body.includes('commentaryRefresh')) { blocked++; return route.abort(); }
    return route.continue();
  });
  const retried = 'Retry line ' + Date.now();
  await post(a, retried);
  const got = await waitFor(async () => (await text(b)).includes(retried), 25000);
  check('a failed fetch is retried with the next poll', blocked === 1 && got, 'blocked=' + blocked);
  await b.unroute('**/async/process.php');

  // Clan hall: A is a member of clan 14, B is not.
  await nav(a, 'clan.php');
  check('A is in the clan hall', new RegExp('clan-' + clan).test(await a.evaluate(() => window.lotgd.async.state().section)), await a.title());
  const secret = 'Clan secret ' + Date.now();
  await post(a, secret);
  const stolen = await b.evaluate(async (CLAN) => {
    const out = [];
    for (const args of [['clan-' + CLAN, 0]]) {
      const d = await window.lotgd.async.call('Commentary', 'commentaryRefresh', args).catch(e => ({ error: String(e) }));
      out.push(JSON.stringify(d));
    }
    const p = await window.lotgd.async.call('Commentary', 'pollUpdates', ['clan-' + CLAN, 0, null]).catch(e => ({ error: String(e) }));
    out.push(JSON.stringify(p));
    return out.join(' ') + document.body.innerHTML.length;
  }, clan);
  await b.waitForTimeout(6000);
  check('B cannot read the clan hall by naming it', !stolen.includes('secret') && !(await b.content()).includes(secret), stolen.slice(0, 200));

  // A page without commentary polls none.
  await nav(b, 'forest.php');
  const forest = JSON.parse(await b.getAttribute('meta[name="lotgd-async"]', 'content') || '{}');
  await b.waitForTimeout(500);
  calls.length = 0; await b.waitForTimeout(12000);
  const fCalls = calls.filter(x => x.startsWith('B:'));
  check('in the forest B asks for no section', fCalls.length > 0 && fCalls.every(x => !x.includes('village')), JSON.stringify(fCalls.slice(0, 3)) + ' meta=' + forest.section);

  sql(`UPDATE accounts SET clanid=0, clanrank=0 WHERE acctid=1; DELETE FROM accounts WHERE login IN ('bobe2e','carle2e')`);
  await browser.close();
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
  process.exit(results.every(Boolean) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
