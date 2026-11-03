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
const OUT = process.env.OUT;
const shot = async (p, name) => { await settle(p, 400); await p.screenshot({ path: `${OUT}/${name}.png`, fullPage: true }); console.log('shot', name); };
(async () => {
  sql(`INSERT INTO settings (setting,value) VALUES ('realtime_enabled','1'),('partial_render','1'),('guarded_actions','1'),('grants_enabled','1'),('live_encounters','1'),('realtime_poll_seconds','5'),('realtime_transport','sse'),('encounter_challenge_seconds','120'),('duel_round_seconds','20'),('duel_max_rounds','30'),('pvp','1') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`DELETE FROM accounts WHERE login IN ('duele2e'); DELETE FROM granted_navs; DELETE FROM encounter_slots; DELETE FROM encounter_rounds; DELETE FROM encounters; DELETE FROM presence`);
  const cols = sql(`SELECT GROUP_CONCAT(CONCAT('\`',column_name,'\`')) FROM information_schema.columns WHERE table_schema='lotgd' AND table_name='accounts' AND column_name NOT IN ('acctid','login','name','emailaddress','version','loggedin','allowednavs','restorepage','prefs','alive','hitpoints')`);
  const prefs = 'a:1:{s:13:"partialrender";i:1;}';
  sql(`INSERT INTO accounts (login,name,emailaddress,version,loggedin,allowednavs,restorepage,prefs,alive,hitpoints,${cols}) SELECT 'duele2e','\`%Dora the Bold\`0','duel@example.invalid',0,0,'a:1:{s:11:"village.php";b:1;}','village.php','${prefs}',1,60,${cols} FROM accounts WHERE acctid=1`);
  const dora = sql(`SELECT acctid FROM accounts WHERE login='duele2e'`);
  const stats = `level=5, age=30, dragonkills=1, alive=1, hitpoints=60, maxhitpoints=60, gold=250, experience=1500, playerfights=3, attack=16, defense=14, strength=12, dexterity=12`;
  sql(`UPDATE accounts SET ${stats} WHERE acctid=${dora}; UPDATE accounts SET prefs='${prefs}', ${stats}, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid IN (1,${dora}); DELETE FROM mail WHERE msgto IN (1,${dora})`);
  sql(`DELETE FROM accounts WHERE login IN ('watche2e'); DELETE FROM commentary WHERE section='inn' AND comment LIKE '%fighting a duel%'`);
  sql(`INSERT INTO accounts (login,name,emailaddress,version,loggedin,allowednavs,restorepage,prefs,alive,hitpoints,${cols}) SELECT 'watche2e','\`#Ezra the Onlooker\`0','watch@example.invalid',0,0,'a:1:{s:11:"village.php";b:1;}','village.php','${prefs}',1,60,${cols} FROM accounts WHERE acctid=1`);
  const ezra = sql(`SELECT acctid FROM accounts WHERE login='watche2e'`);
  sql(`UPDATE accounts SET ${stats} WHERE acctid=${ezra}; UPDATE accounts SET superuser=0 WHERE acctid IN (${dora},${ezra})`);
  const transport = process.env.TRANSPORT || 'sse';
  sql(`UPDATE settings SET value='${transport}' WHERE setting='realtime_transport'`);

  const browser = await chromium.launch(cfg.launch);
  const page = async who => { const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } }); const p = await ctx.newPage(); p.on('pageerror', e => console.log(who, 'pageerror:', e.message)); p.on('dialog', d => d.accept()); return p; };
  const a = await page('A'); const d = await page('D'); const e = await page('E');
  await login(a, 'Admin'); await login(d, 'duele2e'); await login(e, 'watche2e');
  const button = () => a.$(`button[name="target"][value="${dora}"]`);
  const watchText = async p => ((await p.$('[data-lotgd-region="duelwatch"]')) ? (await p.textContent('[data-lotgd-region="duelwatch"]')).replace(/\s+/g, ' ').trim() : '');

  await nav(a, 'encounter.php');
  await waitFor(async () => (await button()) !== null, 8000);
  await (await button()).click(); await a.waitForLoadState('load');
  await waitFor(async () => /challenges you/.test(await slot(d)), 8000);
  await d.click('[data-lotgd-slot="grants"] a'); await d.waitForLoadState('load');
  await d.click('input[value="Accept"]'); await d.waitForLoadState('load');
  await waitFor(async () => (await a.$('[data-lotgd-duel-choice="attack"]')) !== null, 8000);
  check('rematch hidden while fought', !(await a.isVisible('input[value="Challenge again (rematch)"]')));
  check('inn hears of the duel', sql(`SELECT COUNT(*) FROM commentary WHERE section='inn' AND comment LIKE '%fighting a duel%'`) === '1');

  await nav(e, 'encounter.php');
  check('ground lists the duel', (await text(e)).includes('Duels being fought'));
  await e.click('a[href^="encounter.php?op=watch"]'); await e.waitForLoadState('load'); await settle(e);
  check('spectator sees the duel', /Round 1 is being fought/.test(await watchText(e)), await watchText(e));
  check('spectator has no buttons', (await e.$('[data-lotgd-region="duelwatch"] button')) === null);
  await shot(e, '25-zuschauer-sieht-duell');

  await a.click('[data-lotgd-duel-choice="attack"]');
  check('spectator sees a choice made, live', await waitFor(async () => /has chosen/.test(await watchText(e)), 10000), await watchText(e));
  await waitFor(async () => (await d.$('[data-lotgd-duel-choice="guard"]')) !== null, 8000);
  await d.click('[data-lotgd-duel-choice="guard"]');
  check('spectator sees the round, live', await waitFor(async () => /Round 1: .* attacks, .* guards/.test(await watchText(e)), 10000), await watchText(e));
  await shot(e, '26-zuschauer-sieht-runde');

  // Admin (hidden from the leaderboards) gives up: Dora wins.
  await waitFor(async () => (await a.$('[data-lotgd-duel-choice="surrender"]')) !== null, 8000);
  await a.click('[data-lotgd-duel-choice="surrender"]');
  await waitFor(async () => /give up/.test(await encText(a)), 8000);
  check('spectator sees who won', await waitFor(async () => /wins the duel against/.test(await watchText(e)), 10000), await watchText(e));
  check('rematch appears without reload', await waitFor(async () => a.isVisible('input[value="Challenge again (rematch)"]'), 5000));
  await shot(a, '27-revanche-knopf-nach-dem-duell');
  check('record stored', sql(`SELECT CONCAT(winner,',',loser) FROM encounters ORDER BY id DESC LIMIT 1`) === `${dora},1`);

  await a.click('input[value="Challenge again (rematch)"]'); await a.waitForLoadState('load');
  check('rematch challenges again', await waitFor(async () => /challenges you to a friendly duel/.test(await slot(d)), 8000), await slot(d));
  await d.click('[data-lotgd-slot="grants"] a'); await d.waitForLoadState('load');
  await d.click('input[value="Decline"]'); await d.waitForLoadState('load');

  await nav(e, 'village.php');
  await e.goto(BASE + 'badnav.php'); await settle(e);
  await nav(e, 'hof.php');
  await nav(e, 'hof.php?op=duels');
  const hof = await text(e);
  check('hall of fame lists duels', /most duels won/.test(hof) && hof.includes('Dora the Bold'), hof.slice(0, 200));
  await shot(e, '28-ruhmeshalle-duelle');
  await e.goto(BASE + 'badnav.php'); await settle(e);
  await nav(e, 'village.php');
  await nav(e, 'list.php');
  await e.click(`a[href="bio.php?char=${dora}"]`); await e.waitForLoadState('load'); await settle(e);
  await shot(e, '29-bio-duellbilanz');
  const bio = await text(e);
  check('bio shows the record', /Duels: 1 won \(0 serious\), 0 lost/.test(bio), bio.slice(0, 300));

  if (!process.env.KEEP) sql(`DELETE FROM accounts WHERE login IN ('duele2e','watche2e'); DELETE FROM encounter_slots; DELETE FROM granted_navs`);
  await browser.close();
  const passed = results.filter(Boolean).length;
  console.log(`${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
