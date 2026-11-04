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
  await page.click("input[value='Log in'], form[action*='login.php'] button[type='submit']");
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




const encText = async p => ((await p.$('[data-lotgd-region="encounter"]')) ? (await p.textContent('[data-lotgd-region="encounter"]')).replace(/\s+/g, ' ').trim() : '');
const OUT = cfg.OUT;
const shot = async (p, name) => { await settle(p, 400); await p.screenshot({ path: `${OUT}/${name}.png`, fullPage: true }); console.log('shot', name); };
(async () => {
  sql(`INSERT INTO settings (setting,value) VALUES ('realtime_enabled','1'),('partial_render','1'),('guarded_actions','1'),('grants_enabled','1'),('live_encounters','1'),('realtime_poll_seconds','5'),('realtime_transport','sse'),('encounter_challenge_seconds','120'),('duel_round_seconds','20'),('duel_max_rounds','30'),('pvp','1') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`DELETE FROM accounts WHERE login IN ('duele2e'); DELETE FROM granted_navs; DELETE FROM encounter_slots; DELETE FROM encounter_rounds; DELETE FROM encounters; DELETE FROM presence`);
  const cols = cfg.accountColumns(sql, ['acctid','login','name','emailaddress','version','loggedin','allowednavs','restorepage','prefs','alive','hitpoints']);
  const prefs = 'a:1:{s:13:"partialrender";i:1;}';
  sql(`INSERT INTO accounts (login,name,emailaddress,version,loggedin,allowednavs,restorepage,prefs,alive,hitpoints,${cols}) SELECT 'duele2e','\`%Dora the Bold\`0','duel@example.invalid',0,0,'a:1:{s:11:"village.php";b:1;}','village.php','${prefs}',1,60,${cols} FROM accounts WHERE acctid=1`);
  const dora = sql(`SELECT acctid FROM accounts WHERE login='duele2e'`);
  const stats = `level=5, age=30, dragonkills=1, alive=1, hitpoints=60, maxhitpoints=60, gold=250, experience=1500, playerfights=3, attack=16, defense=14, strength=12, dexterity=12`;
  sql(`UPDATE accounts SET ${stats} WHERE acctid=${dora}; UPDATE accounts SET prefs='${prefs}', ${stats}, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid IN (1,${dora}); DELETE FROM mail WHERE msgto IN (1,${dora})`);
  const skin = cfg.skin('jade.htm');
  const oldSkin = sql(`SELECT value FROM settings WHERE setting='defaultskin'`);
  sql(`${cfg.setting('defaultskin', skin)}; INSERT INTO settings (setting,value) VALUES ('realtime_transport','poll') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  if (process.env.TRANSPORT === 'sse') sql(`INSERT INTO settings (setting,value) VALUES ('realtime_transport','sse') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  try {
  const browser = await chromium.launch(cfg.launch);
  const page = async who => { const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } }); const p = await ctx.newPage(); p.on('pageerror', e => console.log(who, 'pageerror:', e.message)); p.on('dialog', d => d.accept()); return p; };
  const a = await page('A'); const d = await page('D');
  await login(a, 'Admin'); await login(d, 'duele2e');
  check('legacy skin in use', (await a.content()).includes(`templates/${skin.replace('.htm', '')}`), skin);
  check('partial client on the legacy page', (await a.$('meta[name="lotgd-head"]')) !== null);
  await a.evaluate(() => { window.__noReload = 1; });
  await nav(a, 'forest.php');
  check('village to forest without reload', (await a.evaluate(() => window.__noReload)) === 1 && (await a.title()).length > 0, await a.title());
  await shot(a, `30-legacy-${skin}-wald-ohne-neuladen`);
  await nav(a, 'village.php');
  check('back to the village without reload', (await a.evaluate(() => window.__noReload)) === 1);
  const mail = await a.$('[data-lotgd-slot="mail"] a[data-lotgd-overlay]');
  check('mail link opens in the page', mail !== null);
  if (mail) {
    await mail.click();
    check('dialog shown', await waitFor(async () => (await a.$('dialog[open]')) !== null, 5000));
    await shot(a, `31-legacy-${skin}-mail-im-fenster`);
    await a.keyboard.press('Escape');
  }
  const button = () => a.$(`button[name="target"][value="${dora}"]`);
  await nav(a, 'encounter.php');
  check('no hint on a legacy skin with regions', (await a.$('.lotgd-live-hint')) === null);
  await waitFor(async () => (await button()) !== null, 8000);
  await (await button()).click(); await a.waitForLoadState('load');
  check('dora sees the challenge live', await waitFor(async () => /challenges you/.test(await slot(d)), 10000), await slot(d));
  await d.click('[data-lotgd-slot="grants"] a'); await d.waitForLoadState('load');
  await d.click('input[value="Accept"]'); await d.waitForLoadState('load');
  check('the duel appears for the challenger live', await waitFor(async () => (await a.$('[data-lotgd-duel-choice="attack"]')) !== null, 10000));
  await a.click('[data-lotgd-duel-choice="attack"]');
  await waitFor(async () => (await d.$('[data-lotgd-duel-choice="guard"]')) !== null, 8000);
  await d.click('[data-lotgd-duel-choice="guard"]');
  check('a round resolves on both pages', await waitFor(async () => /Round 1:/.test(await encText(a)) && /Round 1:/.test(await encText(d)), 10000));
  await shot(a, `32-legacy-${skin}-duell`);
  await d.click('[data-lotgd-duel-choice="surrender"]').catch(() => {});
  await waitFor(async () => /you win/.test(await encText(a)), 8000);
  await browser.close();
  } finally {
    sql(`${cfg.setting('defaultskin', oldSkin)}; DELETE FROM accounts WHERE login='duele2e'; DELETE FROM encounter_slots; DELETE FROM granted_navs`);
  }
  const passed = results.filter(Boolean).length;
  console.log(`${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
