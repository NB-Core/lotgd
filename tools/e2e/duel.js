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
const transport = cfg.transport('sse');
(async () => {
  sql(`INSERT INTO settings (setting,value) VALUES ('realtime_enabled','1'),('partial_render','1'),('guarded_actions','1'),('grants_enabled','1'),('live_encounters','1'),('realtime_poll_seconds','5'),('realtime_transport','${transport}'),('encounter_challenge_seconds','120'),('duel_round_seconds','10'),('duel_max_rounds','30'),('pvp','1') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`DELETE FROM accounts WHERE login IN ('duele2e'); DELETE FROM granted_navs; DELETE FROM encounter_slots; DELETE FROM encounter_rounds; DELETE FROM encounters; DELETE FROM presence`);
  const cols = cfg.accountColumns(sql, ['acctid','login','name','emailaddress','version','loggedin','allowednavs','restorepage','prefs','alive','hitpoints']);
  const prefs = 'a:1:{s:13:"partialrender";i:1;}';
  sql(`INSERT INTO accounts (login,name,emailaddress,version,loggedin,allowednavs,restorepage,prefs,alive,hitpoints,${cols}) SELECT 'duele2e','Dora','duel@example.invalid',0,0,'a:1:{s:11:"village.php";b:1;}','village.php','${prefs}',1,50,${cols} FROM accounts WHERE acctid=1`);
  const dora = sql(`SELECT acctid FROM accounts WHERE login='duele2e'`);
  const stats = `level=5, age=30, dragonkills=1, alive=1, hitpoints=500, maxhitpoints=500, gold=100, experience=1000, playerfights=3`;
  sql(`UPDATE accounts SET ${stats} WHERE acctid=${dora}; UPDATE accounts SET prefs='${prefs}', ${stats}, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid IN (1,${dora}); DELETE FROM mail WHERE msgto IN (1,${dora})`);

  const browser = await chromium.launch(cfg.launch);
  const page = async who => { const p = await (await browser.newContext()).newPage(); p.on('pageerror', e => console.log(who, 'pageerror:', e.message)); return p; };
  const a = await page('A'); const d = await page('D');
  const dialogs = [];
  for (const p of [a, d]) p.on('dialog', async dialog => { dialogs.push(dialog.message()); await dialog.accept(); });
  await login(a, 'Admin'); await login(d, 'duele2e');
  const button = () => a.$(`button[name="target"][value="${dora}"]`);

  async function challengeAndAccept(stakes) {
    await nav(a, 'encounter.php');
    await waitFor(async () => (await button()) !== null, 8000);
    await a.check(`input[name="stakes"][value="${stakes}"]`);
    // By selector, not a handle: the live presence list can swap the button out meanwhile.
    await a.click(`button[name="target"][value="${dora}"]`); await a.waitForLoadState('load'); await settle(a);
    await waitFor(async () => /challenges you/.test(await slot(d)), 8000);
    const offer = await slot(d);
    await d.click('[data-lotgd-slot="grants"] a'); await d.waitForLoadState('load'); await settle(d);
    await d.click('input[value="Accept"]'); await d.waitForLoadState('load'); await settle(d);
    await a.evaluate(() => { window.__noReload = 1; });
    await d.evaluate(() => { window.__noReload = 1; });
    return offer;
  }
  const duelId = () => sql(`SELECT id FROM encounters WHERE challenger=1 AND opponent=${dora} ORDER BY id DESC LIMIT 1`);

  // A friendly duel.
  const offer = await challengeAndAccept('friendly');
  check('Dora was offered a friendly duel', /friendly duel/.test(offer), offer);
  check('A sees the duel without a reload', await waitFor(async () => (await a.$('[data-lotgd-duel-choice="attack"]')) !== null, 8000), await encText(a));
  check('Dora sees the duel', (await d.$('[data-lotgd-duel-choice="guard"]')) !== null, await encText(d));
  await a.click('[data-lotgd-duel-choice="attack"]');
  check('Dora sees that A has chosen, not what', await waitFor(async () => /has chosen/.test(await encText(d)), 8000) && !/You chose/.test(await encText(d)), await encText(d));
  check('A waits for Dora', await waitFor(async () => /Waiting for/.test(await encText(a)), 5000), await encText(a));
  await d.click('[data-lotgd-duel-choice="guard"]');
  check('both see round 1 resolved', await waitFor(async () => /Round 1:/.test(await encText(a)) && /Round 1:/.test(await encText(d)), 8000), await encText(a));
  check('round 2 is open', /Round 2/.test(await encText(a)), await encText(a));

  // Nobody chooses: the round resolves when its time runs out.
  check('round 2 resolves by itself after its time', await waitFor(async () => /Round 2:/.test(await encText(d)), 20000), await encText(d));
  const id = duelId();
  const auto = JSON.parse(sql(`SELECT result FROM encounter_rounds WHERE encounter_id=${id} AND round=2`));
  check('both attacked by default', auto.auto.length === 2, JSON.stringify(auto));

  await d.click('[data-lotgd-duel-choice="surrender"]');
  check('giving up asks first', dialogs.includes('Give up the duel?'), JSON.stringify(dialogs));
  check('A wins on Dora giving up, seen without a reload', await waitFor(async () => /gives up -- you win/.test(await encText(a)), 8000), await encText(a));
  check('Dora sees she gave up', await waitFor(async () => /You give up/.test(await encText(d)), 8000), await encText(d));
  check('nobody reloaded', (await a.evaluate(() => window.__noReload)) === 1 && (await d.evaluate(() => window.__noReload)) === 1);
  check('a friendly duel changes no account', sql(`SELECT GROUP_CONCAT(CONCAT(gold,'/',experience,'/',alive) ORDER BY acctid) FROM accounts WHERE acctid IN (1,${dora})`) === '100/1000/1,100/1000/1');
  check('it is in the news', /won a friendly duel/.test(sql(`SELECT newstext FROM news ORDER BY newsid DESC LIMIT 1`)));

  // A serious duel: A gives up, Dora wins as the defender.
  await nav(d, 'village.php');
  await nav(a, 'village.php');
  const serious = await challengeAndAccept('serious');
  check('Dora was offered a serious duel', /serious duel/.test(serious), serious);
  check('the serious duel spent one of A\'s fights', sql(`SELECT playerfights FROM accounts WHERE acctid=1`) === '2');
  await waitFor(async () => (await a.$('[data-lotgd-duel-choice="surrender"]')) !== null, 8000);
  await a.click('[data-lotgd-duel-choice="surrender"]');
  check('a serious surrender warns of death', dialogs.some(m => /You will die/.test(m)), JSON.stringify(dialogs));
  check('Dora wins', await waitFor(async () => /gives up -- you win/.test(await encText(d)), 8000), await encText(d));
  const [aGold, aExp, aAlive] = sql(`SELECT CONCAT(gold,' ',experience,' ',alive) FROM accounts WHERE acctid=1`).split(' ').map(Number);
  const [dGold, dExp] = sql(`SELECT CONCAT(gold,' ',experience) FROM accounts WHERE acctid=${dora}`).split(' ').map(Number);
  check('A is dead and lost the gold on hand', aAlive === 0 && aGold === 0, `${aGold} ${aExp} ${aAlive}`);
  check('A lost 15% of the experience', aExp === 850, String(aExp));
  check('Dora gained gold and experience', dGold === 100 + Math.round(10 * 5 * Math.log(100)) && dExp === 1100, `${dGold} ${dExp}`);
  check('both got a mail', sql(`SELECT COUNT(*) FROM mail WHERE msgto IN (1,${dora})`) === '2');

  sql(`UPDATE accounts SET alive=1, ${stats} WHERE acctid=1; DELETE FROM accounts WHERE login='duele2e'; DELETE FROM encounter_slots; DELETE FROM granted_navs`);
  await browser.close();
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
  process.exit(results.every(Boolean) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
