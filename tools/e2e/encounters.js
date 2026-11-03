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
const transport = process.env.TRANSPORT || 'sse';
(async () => {
  sql(`INSERT INTO settings (setting,value) VALUES ('realtime_enabled','1'),('partial_render','1'),('guarded_actions','1'),('grants_enabled','1'),('live_encounters','1'),('realtime_poll_seconds','5'),('realtime_transport','${transport}'),('encounter_challenge_seconds','120') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`DELETE FROM accounts WHERE login IN ('duele2e'); DELETE FROM granted_navs; DELETE FROM encounter_slots; DELETE FROM encounters; DELETE FROM presence`);
  const cols = sql(`SELECT GROUP_CONCAT(CONCAT('\`',column_name,'\`')) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='accounts' AND column_name NOT IN ('acctid','login','name','emailaddress','version','loggedin','allowednavs','restorepage','prefs','alive','hitpoints')`);
  const prefs = 'a:1:{s:13:"partialrender";i:1;}';
  sql(`INSERT INTO accounts (login,name,emailaddress,version,loggedin,allowednavs,restorepage,prefs,alive,hitpoints,${cols}) SELECT 'duele2e','Dora','duel@example.invalid',0,0,'a:1:{s:11:"village.php";b:1;}','village.php','${prefs}',1,50,${cols} FROM accounts WHERE acctid=1`);
  const dora = sql(`SELECT acctid FROM accounts WHERE login='duele2e'`);
  sql(`UPDATE accounts SET prefs='${prefs}', alive=1, hitpoints=50, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid IN (1,${dora})`);

  const browser = await chromium.launch(cfg.launch);
  const page = async who => { const p = await (await browser.newContext()).newPage(); p.on('pageerror', e => console.log(who, 'pageerror:', e.message)); return p; };
  const a = await page('A'); const d = await page('D');
  for (const p of [a, d]) p.on('dialog', dialog => dialog.accept());
  await login(a, 'Admin'); await login(d, 'duele2e');
  check('A sees the duelling ground in the village', await a.$('a[href^="encounter.php"]') !== null);
  await nav(a, 'encounter.php');
  check('A is on the duelling ground', /Duelling Ground/.test(await text(a)));
  await a.evaluate(() => { window.__noReload = 1; });
  await d.evaluate(() => { window.__noReload = 1; });
  const button = () => a.$(`button[name="target"][value="${dora}"]`);
  check('A can challenge Dora, who is in the village', await waitFor(async () => (await button()) !== null, 8000));

  // Dora goes into the forest: the button goes, without a reload of A's page.
  await nav(d, 'forest.php');
  check('Dora in the forest: no button for her', await waitFor(async () => (await button()) === null, 8000), await a.textContent('[data-lotgd-region="presence"]'));
  check('A\'s list says where she is', /in the forest/.test(await a.textContent('[data-lotgd-region="presence"]')));
  await nav(d, 'village.php');
  check('Dora back in the village: the button is back', await waitFor(async () => (await button()) !== null, 8000));

  // The challenge.
  await (await button()).click(); await a.waitForLoadState('load'); await settle(a);
  check('A waits for an answer', /Waiting for an answer/.test(await encText(a)), await encText(a));
  // The challenge was a form, a page change; from here on A's page stays.
  await a.evaluate(() => { window.__noReload = 1; });
  const offered = await waitFor(async () => /challenges you to a (friendly |serious )?duel/.test(await slot(d)), 8000);
  check('Dora sees the challenge in her header without a reload', offered, await slot(d));
  check('Dora never reloaded', (await d.evaluate(() => window.__noReload)) === 1);

  // Dora opens it and accepts.
  await d.click('[data-lotgd-slot="grants"] a'); await d.waitForLoadState('load'); await settle(d);
  check('Dora sees accept and decline', await d.$('input[value="Accept"]') !== null && await d.$('input[value="Decline"]') !== null, await text(d));
  await d.click('input[value="Accept"]'); await d.waitForLoadState('load'); await settle(d);
  check('Dora accepted', /You accepted the challenge/.test(await encText(d)), await encText(d));
  const accepted = await waitFor(async () => /accepted your challenge/.test(await encText(a)), 8000);
  check('A sees the acceptance without a reload', accepted, await encText(a));
  check('A never reloaded', (await a.evaluate(() => window.__noReload)) === 1);
  check('the grant is gone from Dora\'s header', !/challenges you/.test(await slot(d)));
  const id = sql(`SELECT id FROM encounters WHERE challenger=1 AND opponent=${dora} ORDER BY id DESC LIMIT 1`);
  check('both are in the encounter', sql(`SELECT COUNT(*) FROM encounter_slots WHERE encounter_id=${id}`) === '2');
  check('the encounter has its channel', sql(`SELECT COUNT(*) FROM event_deliveries WHERE channel='encounter-${id}'`) !== '0');

  // A follows the region's link, and leaves.
  await a.click('[data-lotgd-region="encounter"] a'); await a.waitForLoadState('load'); await settle(a);
  check('A faces Dora', /face each other/.test(await text(a)), await text(a));
  await a.click('button:has-text("Give up and leave")'); await a.waitForLoadState('load'); await settle(a);
  check('the encounter is over for A', /is over/.test(await encText(a)), await encText(a));
  check('Dora sees it end without a reload', await waitFor(async () => /is over/.test(await encText(d)), 8000), await encText(d));
  check('the slots are free', sql(`SELECT COUNT(*) FROM encounter_slots WHERE encounter_id=${id}`) === '0');

  // A challenge withdrawn: Dora's offer goes away by itself.
  await nav(d, 'village.php');
  await nav(a, 'encounter.php?c=');
  await waitFor(async () => (await button()) !== null, 8000);
  await (await button()).click(); await a.waitForLoadState('load'); await settle(a);
  check('Dora is offered the second challenge', await waitFor(async () => /challenges you/.test(await slot(d)), 8000), await slot(d));
  await a.click('input[value="Withdraw the challenge"]'); await a.waitForLoadState('load'); await settle(a);
  check('A withdrew', /withdrew your challenge/.test(await encText(a)), await encText(a));
  check('Dora\'s offer goes away without a reload', await waitFor(async () => !/challenges you/.test(await slot(d)), 8000), await slot(d));

  // Declined.
  await nav(a, 'encounter.php?c=');
  await waitFor(async () => (await button()) !== null, 8000);
  await (await button()).click(); await a.waitForLoadState('load'); await settle(a);
  await waitFor(async () => /challenges you/.test(await slot(d)), 8000);
  await d.click('[data-lotgd-slot="grants"] a'); await d.waitForLoadState('load'); await settle(d);
  await d.click('input[value="Decline"]'); await d.waitForLoadState('load'); await settle(d);
  check('A sees the refusal without a reload', await waitFor(async () => /declined your challenge/.test(await encText(a)), 8000), await encText(a));

  // A button shown while Dora was in the village, used after she left for the forest.
  await nav(a, 'encounter.php?c=');
  await waitFor(async () => (await button()) !== null, 8000);
  sql(`UPDATE presence SET place='forest' WHERE acctid=${dora}`);
  await (await button()).click(); await a.waitForLoadState('load'); await settle(a);
  check('a stale button for a player now in the forest is refused', /not around to be challenged/.test(await text(a)), await text(a));
  sql(`UPDATE presence SET place='village' WHERE acctid=${dora}`);

  // Logging out takes her off the list.
  await nav(a, 'encounter.php?c=');
  await nav(d, 'village.php');
  await d.click('a[href^="login.php?op=logout"] >> nth=0'); await d.waitForLoadState('load'); await settle(d);
  check('Dora gone from the list after her logout', await waitFor(async () => !/Dora/.test(await a.textContent('[data-lotgd-region="presence"]')), 8000), await a.textContent('[data-lotgd-region="presence"]'));

  sql(`DELETE FROM accounts WHERE login='duele2e'; DELETE FROM encounter_slots; DELETE FROM granted_navs`);
  await browser.close();
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
  process.exit(results.every(Boolean) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
