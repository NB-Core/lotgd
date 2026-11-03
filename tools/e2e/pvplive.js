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




const encText = async p => ((await p.$('[data-lotgd-region="encounter"]')) ? (await p.textContent('[data-lotgd-region="encounter"]')).replace(/\s+/g, ' ').trim() : '');
const OUT = cfg.OUT;
const shot = async (p, name) => { await settle(p, 400); await p.screenshot({ path: `${OUT}/${name}.png`, fullPage: true }); console.log('shot', name); };
(async () => {
  sql(`INSERT INTO settings (setting,value) VALUES ('realtime_enabled','1'),('partial_render','1'),('guarded_actions','1'),('grants_enabled','1'),('live_encounters','1'),('realtime_poll_seconds','5'),('realtime_transport','sse'),('encounter_challenge_seconds','120'),('duel_round_seconds','20'),('duel_max_rounds','30'),('pvp','1') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`DELETE FROM accounts WHERE login IN ('duele2e'); DELETE FROM granted_navs; DELETE FROM encounter_slots; DELETE FROM encounter_rounds; DELETE FROM encounters; DELETE FROM presence`);
  const cols = sql(`SELECT GROUP_CONCAT(CONCAT('\`',column_name,'\`')) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='accounts' AND column_name NOT IN ('acctid','login','name','emailaddress','version','loggedin','allowednavs','restorepage','prefs','alive','hitpoints')`);
  const prefs = 'a:1:{s:13:"partialrender";i:1;}';
  sql(`INSERT INTO accounts (login,name,emailaddress,version,loggedin,allowednavs,restorepage,prefs,alive,hitpoints,${cols}) SELECT 'duele2e','\`%Dora the Bold\`0','duel@example.invalid',0,0,'a:1:{s:11:"village.php";b:1;}','village.php','${prefs}',1,60,${cols} FROM accounts WHERE acctid=1`);
  const dora = sql(`SELECT acctid FROM accounts WHERE login='duele2e'`);
  const stats = `level=5, age=30, dragonkills=1, alive=1, hitpoints=60, maxhitpoints=60, gold=250, experience=1500, playerfights=3, attack=16, defense=14, strength=12, dexterity=12`;
  sql(`UPDATE accounts SET ${stats} WHERE acctid=${dora}; UPDATE accounts SET prefs='${prefs}', ${stats}, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid IN (1,${dora}); DELETE FROM mail WHERE msgto IN (1,${dora})`);

  const browser = await chromium.launch(cfg.launch);
  const page = async who => { const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } }); const p = await ctx.newPage(); p.on('pageerror', e => console.log(who, 'pageerror:', e.message)); p.on('dialog', d => d.accept()); return p; };
  const a = await page('A'); const d = await page('D');
  await login(a, 'Admin'); await login(d, 'duele2e');

  await nav(a, 'pvp.php');
  const t = await text(a);
  check('section shown', t.includes('Warriors about now'), t.slice(0, 300));
  const serious = `form[action="encounter.php?op=challenge"]:has(input[name="target"][value="${dora}"]):has(input[name="stakes"][value="serious"]) button`;
  check('serious offered', (await a.$(serious)) !== null);
  await shot(a, '23-pvp-liste-live-herausfordern');
  await a.click(serious); await a.waitForLoadState('load'); await settle(a);
  check('challenger on the encounter page', a.url().includes('encounter.php'), a.url());
  check('serious warning', (await text(a)).includes('serious duel'));
  check('dora sees the challenge live', await waitFor(async () => /challenges you to a serious duel/.test(await slot(d)), 8000), await slot(d));
  await shot(d, '24-gegner-sieht-live-herausforderung-aus-pvp');
  await browser.close();
  const passed = results.filter(Boolean).length;
  console.log(`${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
