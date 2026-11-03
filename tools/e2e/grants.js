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

(async () => {
  sql(`INSERT INTO settings (setting,value) VALUES ('realtime_enabled','1'),('partial_render','1'),('guarded_actions','1'),('grants_enabled','1'),('realtime_poll_seconds','5'),('allowclans','1') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`DELETE FROM accounts WHERE login IN ('offe2e','appe2e'); DELETE FROM clans WHERE clanshort='E2E'; DELETE FROM granted_navs`);
  sql(`INSERT INTO clans (clanname, clanshort, clanmotd, clandesc, customsay) VALUES ('Grant Hall','E2E','','','')`);
  const clan = sql(`SELECT clanid FROM clans WHERE clanshort='E2E'`);
  const cols = sql(`SELECT GROUP_CONCAT(CONCAT('\`',column_name,'\`')) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='accounts' AND column_name NOT IN ('acctid','login','name','emailaddress','version','loggedin','allowednavs','restorepage','clanid','clanrank','prefs')`);
  const prefs = 'a:1:{s:13:"partialrender";i:1;}';
  for (const [id, login, name, cid, rank] of [[385, 'offe2e', 'Otto', clan, 20], [386, 'appe2e', 'Nina', 0, 0]]) {
    // Fixed ids: other suites address these accounts by id.
    sql(`INSERT INTO accounts (acctid,login,name,emailaddress,version,loggedin,allowednavs,restorepage,clanid,clanrank,prefs,${cols}) SELECT ${id},'${login}','${name}','${login}@example.invalid',0,0,'a:1:{s:11:"village.php";b:1;}','village.php',${cid},${rank},'${prefs}',${cols} FROM accounts WHERE acctid=1`);
  }
  const otto = sql(`SELECT acctid FROM accounts WHERE login='offe2e'`);
  const nina = sql(`SELECT acctid FROM accounts WHERE login='appe2e'`);
  sql(`UPDATE accounts SET clanid=${clan}, clanrank=30, prefs='${prefs}', restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid IN (1,${otto},${nina}); DELETE FROM mail WHERE msgto IN (1,${otto},${nina})`);

  const browser = await chromium.launch(cfg.launch);
  const page = async who => { const p = await (await browser.newContext()).newPage(); p.on('pageerror', e => console.log(who, 'pageerror:', e.message)); return p; };
  const a = await page('A'); const o = await page('O'); const n = await page('N');
  await login(a, 'Admin'); await login(o, 'offe2e');
  check('officers start with an empty grants slot', (await slot(a)) === '' && (await slot(o)) === '', JSON.stringify([await slot(a), await slot(o)]));
  await a.evaluate(() => { window.__noReload = 1; }); await o.evaluate(() => { window.__noReload = 1; });

  await login(n, 'appe2e');
  await nav(n, 'clan.php');
  await nav(n, 'clan.php?op=apply');
  await n.click(`a[href^="clan.php?op=apply&to=${clan}"] >> nth=0`); await n.waitForLoadState('load'); await settle(n);
  check('applicant applied', /accepts your application/.test(await text(n)), (await text(n)).slice(0, 300));
  const grants = sql(`SELECT GROUP_CONCAT(acctid ORDER BY acctid) FROM granted_navs WHERE target_key='clan-applicant-${nina}' AND used_at IS NULL`);
  check('both officers hold a grant', grants === `1,${otto}`, grants);

  check('A sees the offer appear without a reload', await waitFor(async () => /Accept Nina/.test(await slot(a)), 15000), await slot(a));
  check('O sees the offer appear without a reload', await waitFor(async () => /Accept Nina/.test(await slot(o)), 15000), await slot(o));
  check('neither reloaded', (await a.evaluate(() => window.__noReload)) === 1 && (await o.evaluate(() => window.__noReload)) === 1);

  // O moves on to another page: the offer is now also in O's navigation.
  await nav(o, 'clan.php');
  check('O\'s navigation lists the offer', /Waiting for you/.test(await o.textContent('[data-lotgd-region="nav"]')), (await o.textContent('[data-lotgd-region="nav"]')).replace(/\s+/g, ' ').slice(0, 300));
  const ottoLink = await o.$eval('[data-lotgd-region="nav"] a[href*="op=grant"]', el => el.getAttribute('href'));

  // A opens the slot link -- not in A's allowednavs, offered by the grant.
  await a.click('[data-lotgd-slot="grants"] a >> nth=0'); await a.waitForLoadState('load'); await settle(a);
  check('A sees the application', /It reads: Nina/.test(await text(a)), (await text(a)).slice(0, 400));
  const viewUrl = a.url();
  await a.click('form[action*="op=grant"] input[type="submit"]'); await a.waitForLoadState('load'); await settle(a);
  check('A accepted Nina', /Nina is now a member of your clan/.test(await text(a)), (await text(a)).slice(0, 400));
  check('Nina is a member', sql(`SELECT clanrank FROM accounts WHERE acctid=${nina}`) === '10');
  check('Nina got the mail', sql(`SELECT COUNT(*) FROM mail WHERE msgto=${nina} AND subject LIKE '%accepted%'`) === '1');
  check('the whole group is closed', sql(`SELECT COUNT(*) FROM granted_navs WHERE target_key='clan-applicant-${nina}' AND used_at IS NULL`) === '0');

  check('O\'s slot empties without a reload', await waitFor(async () => (await slot(o)) === '', 15000), await slot(o));
  await o.click(`a[href="${ottoLink}"] >> nth=0`); await o.waitForLoadState('load'); await settle(o);
  check('O\'s stale navigation link says it is decided', /already been decided/.test(await text(o)), (await text(o)).slice(0, 300));

  await a.goto(viewUrl); await settle(a);
  check('the used view link is refused', /badnav|not allowed/i.test(a.url() + await a.title()) || !/It reads/.test(await text(a)), a.url());

  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
  await browser.close();
  process.exit(results.every(Boolean) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
