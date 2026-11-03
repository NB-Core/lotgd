const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const results = [];
function check(name, ok, info = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (info ? ' -- ' + info : '')); }
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const settle = (p, ms = 700) => p.waitForTimeout(ms);
async function login(page, name) {
  await page.goto(BASE + 'home.php');
  await page.fill('#name', name);
  await page.fill('#password', cfg.PASSWORD);
  await page.click("input[value='Log in']");
  await page.waitForLoadState('load'); await settle(page);
  await page.goto(BASE + 'badnav.php'); await settle(page);
  for (let i = 0; i < 25 && !(await page.title()).includes('Square'); i++) {
    const href = await page.evaluate(() => {
      const links = [...document.querySelectorAll('a')].filter(a => !a.hasAttribute('onclick'));
      const pick = links.find(a => /^village\.php(\?c=|$)/.test(a.getAttribute('href') || '')) || links.find(a => /^newday\.php/.test(a.getAttribute('href') || ''));
      return pick ? pick.getAttribute('href') : null;
    });
    if (!href) break;
    await page.click(`a[href="${href}"] >> nth=0`); await page.waitForLoadState('load'); await settle(page);
  }
}
async function waitFor(fn, ms) { const until = Date.now() + ms; while (Date.now() < until) { if (await fn()) return true; await new Promise(r => setTimeout(r, 300)); } return false; }

async function scenario(browser, label, partial) {
  console.log('== ' + label);
  sql(`INSERT INTO settings (setting,value) VALUES ('ajax','1'),('partial_render','${partial ? 1 : 0}'),('realtime_enabled','${partial ? 1 : 0}'),('guarded_actions','1'),('realtime_poll_seconds','5') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`UPDATE accounts SET prefs='a:2:{s:4:"ajax";i:1;s:13:"partialrender";i:${partial ? 1 : 0};}', restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0, clanid=0, clanrank=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid=1; DELETE FROM mail WHERE msgto=1; DELETE FROM action_log WHERE acctid=1`);
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const errors = []; page.on('pageerror', e => errors.push(e.message)); page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  const calls = [];
  page.on('request', r => {
    const u = r.url();
    if (u.includes('/async/')) { let c = ''; try { c = JSON.parse(new URLSearchParams(r.postData() || '').get('call') || '{}'); } catch (e) {} calls.push({ url: u.replace(BASE, ''), call: c.class + '.' + c.method, t: Date.now() }); }
  });
  await login(page, 'Admin');
  const meta = await page.evaluate(() => { const m = document.querySelector('meta[name="lotgd-async"]'); return m ? JSON.parse(m.content) : null; });
  check('page carries the async meta', meta && meta.updates === true && meta.url === '/async/process.php' && typeof meta.csrf === 'string' && meta.csrf.length > 10, JSON.stringify(meta && { updates: meta.updates, section: meta.section }));
  check('no inline Jaxon left', await page.evaluate(() => typeof window.jaxon === 'object' && !document.documentElement.innerHTML.includes('lotgd_poll_interval_ms')));
  check('legacy names resolve', await page.evaluate(() => typeof window.getJaxonHandlers().TwoFactorAuthPasskey.beginAuthentication === 'function' && window.jaxon.config.requestURI === '/async/process.php'));

  const before = calls.length;
  const maxId = sql(`SELECT COALESCE(MAX(commentid),0) FROM commentary`);
  // Through the core's write path, which announces it (live commentary).
  execSync(`php ${__dirname}/inject_comment.php '${cfg.GAME}' village 1 'async e2e ${label} hello'`);
  const appeared = await waitFor(async () => (await page.textContent('body')).includes(`async e2e ${label} hello`), 25000);
  check('a comment posted elsewhere appears', appeared);
  sql(`INSERT INTO mail (msgfrom, msgto, subject, body, sent, seen) VALUES (0, 1, 'e2e', 'hello', NOW(), 0)`);
  if (partial) {
    // What Mail::systemMail() does for a realtime player: announce the region.
    const v = sql(`SELECT MAX(messageid) FROM mail WHERE msgto=1`);
    execSync(`php ${cfg.REPO}/tests/Integration/realtime_worker.php '${JSON.stringify({ root: cfg.GAME, mode: 'notify', channel: 'user-1', version: Number(v) })}'`);
  }
  const mailed = await waitFor(async () => /1 new/.test(await page.textContent(partial ? '[data-lotgd-slot="mail"]' : 'body')), 25000);
  check('the mail link shows the new mail', mailed);
  await settle(page, 12000);
  const polls = calls.slice(before);
  const kinds = [...new Set(polls.map(c => c.call))];
  check('only process.php is called', polls.every(c => c.url.startsWith('async/process.php')), JSON.stringify(kinds));
  check('one poll call per interval, no second loop', !kinds.includes('Realtime.poll') && kinds.includes('Commentary.pollUpdates'), JSON.stringify(kinds));
  if (partial) {
    check('the realtime region fetch rides the same endpoint', kinds.includes('Realtime.region'), JSON.stringify(kinds));
  }
  const errorsBefore = errors.length;
  const bans = await page.evaluate(() => window.lotgd.async.call('Bans', 'affectedUsers', ['127.0.0.1', '', 'notify']).then(() => 'ok', e => 'err ' + e.status));
  check('a superuser call goes through', bans === 'ok', bans);
  const bad = await page.evaluate(() => fetch('/async/process.php', { method: 'POST', body: new URLSearchParams({ call: JSON.stringify({ class: 'Mail', method: 'mailStatus', args: [true] }) }) }).then(r => r.status));
  check('a request without the async header is refused', bad === 400, String(bad));
  check('no page errors', errorsBefore === 0, errors.slice(0, errorsBefore).join(' | '));
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch(cfg.launch);
  await scenario(browser, 'classic', false);
  await scenario(browser, 'partial', true);
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
  await browser.close();
  process.exit(results.every(Boolean) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
