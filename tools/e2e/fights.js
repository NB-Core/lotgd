// Fights swap in place: forest (search, round, auto, skill, run, keys, double click, defeat),
// graveyard, master, PvP, dragon; the back button still goes to badnav.
const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const results = [];
function check(name, ok, info = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (info ? ' -- ' + info : '')); }
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const settle = (p, ms = 700) => p.waitForTimeout(ms);
const text = async p => ((await p.textContent('[data-lotgd-region="content"]')) || '').replace(/\s+/g, ' ');
const OUT = process.env.OUT || '.';
const shot = async (p, name) => { await settle(p, 300); await p.screenshot({ path: `${OUT}/${name}.png`, fullPage: true }); console.log('shot', name); };
const noReload = p => p.evaluate(() => window.__noReload === 1);
const allow = (acct, url) => sql(`UPDATE accounts SET allowednavs='a:1:{s:${url.length}:"${url}";b:1;}', restorepage='${url}' WHERE acctid=${acct}`);

async function login(page, name) {
  await page.goto(BASE + 'home.php');
  await page.fill('#name', name);
  await page.fill('#password', cfg.PASSWORD);
  await page.click("input[value='Log in'], form[action*='login.php'] button[type='submit']");
  await page.waitForLoadState('load'); await settle(page);
}
async function hrefOf(page, re) {
  return page.evaluate(src => {
    const re = new RegExp(src);
    const a = [...document.querySelectorAll('[data-lotgd-region="nav"] a')].find(x => re.test(x.getAttribute('href') || ''));
    return a ? a.getAttribute('href') : null;
  }, re.source);
}
async function click(page, re) {
  const href = await hrefOf(page, re);
  if (!href) return null;
  await page.click(`[data-lotgd-region="nav"] a[href="${href}"] >> nth=0`);
  await page.waitForLoadState('load'); await settle(page);
  return href;
}
async function fresh(page, url) {
  allow(1, url);
  await page.goto(BASE + url); await page.waitForLoadState('load'); await settle(page);
  await page.evaluate(() => { window.__noReload = 1; });
}
// Play rounds until the fight's own links are gone.
async function finish(page, script) {
  for (let i = 0; i < 40; i++) {
    const href = await hrefOf(page, new RegExp(`^${script.replace('.', '\\.')}\\?.*op=fight`));
    if (!href) return true;
    const full = await hrefOf(page, new RegExp(`^${script.replace('.', '\\.')}\\?.*auto=full`));
    await click(page, new RegExp('^' + (full || href).replace(/[.?*+^$()[\]{}|\\]/g, '\\$&') + '$'));
  }
  return false;
}

(async () => {
  const skin = process.env.SKIN || 'twig:aurora';
  const oldSkin = sql(`SELECT value FROM settings WHERE setting='defaultskin'`);
  sql(`INSERT INTO settings (setting,value) VALUES ('partial_render','1'),('autofight','1'),('autofightfull','1'),('pvp','1'),('pvpimmunity','0'),('pvpminexp','0'),('realtime_enabled','1'),('realtime_transport','${process.env.TRANSPORT || 'poll'}'),('forestchance','0') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`UPDATE settings SET value='${skin}' WHERE setting='defaultskin'`);
  const prefs = 'a:1:{s:13:"partialrender";i:1;}';
  const stats = `level=5, dragonkills=0, alive=1, hitpoints=500, maxhitpoints=500, turns=30, gravefights=10, soulpoints=100, gold=250, experience=100000, playerfights=5, attack=60, defense=60, specialty='DA', seendragon=0, badguy='', bufflist='a:0:{}'`;
  sql(`UPDATE accounts SET prefs='${prefs}', ${stats}, loggedin=0, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}' WHERE acctid=1; DELETE FROM accounts_output WHERE acctid=1`);
  sql(`DELETE FROM module_userprefs WHERE userid=1 AND modulename='specialtydarkarts'; INSERT INTO module_userprefs (modulename,setting,userid,value) VALUES ('specialtydarkarts','skill',1,'10'),('specialtydarkarts','uses',1,'5')`);
  // A sleeping warrior for PvP.
  sql(`DELETE FROM accounts WHERE login='sleepere2e'`);
  const cols = sql(`SELECT GROUP_CONCAT(CONCAT('\`',column_name,'\`')) FROM information_schema.columns WHERE table_schema='lotgd' AND table_name='accounts' AND column_name NOT IN ('acctid','login','name','emailaddress','version','loggedin','laston','pvpflag','allowednavs','restorepage','prefs','alive','hitpoints','location')`);
  sql(`INSERT INTO accounts (login,name,emailaddress,version,loggedin,laston,pvpflag,allowednavs,restorepage,prefs,alive,hitpoints,location,${cols}) SELECT 'sleepere2e','Sleepy Sam','sleep@example.invalid',0,0,'2020-01-01 00:00:00','2020-01-01 00:00:00','','village.php','a:0:{}',1,20,location,${cols} FROM accounts WHERE acctid=1`);
  sql(`UPDATE accounts SET level=5, hitpoints=20, maxhitpoints=20, attack=1, defense=1, dragonkills=0, superuser=0, age=30, experience=5000, slaydragon=0, locked=0 WHERE login='sleepere2e'`);
  try {
    const browser = await chromium.launch(cfg.launch);
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const a = await ctx.newPage();
    a.on('pageerror', e => console.log('pageerror:', e.message));
    a.on('dialog', d => d.accept());
    await login(a, 'Admin');

    // Forest: search -> round -> skill -> five rounds -> to the end.
    await fresh(a, 'forest.php');
    check('the partial client runs', (await a.$('meta[name="lotgd-head"]')) !== null);
    await click(a, /^forest\.php\?op=search(&|$)/);
    check('search swapped in', await noReload(a), await a.title());
    check('a fight started', (await hrefOf(a, /^forest\.php\?op=fight/)) !== null, (await text(a)).slice(0, 160));
    check('auto-fight links offered', (await hrefOf(a, /auto=five/)) !== null && (await hrefOf(a, /auto=full/)) !== null);
    const rev0 = Number(sql('SELECT page_revision FROM accounts WHERE acctid=1'));
    await click(a, /^forest\.php\?op=fight(&c=[^&]*)?$/);
    check('one round swapped in', await noReload(a));
    check('one round claims one revision', Number(sql('SELECT page_revision FROM accounts WHERE acctid=1')) === rev0 + 1);
    await shot(a, `40-fight-${skin.replace(/[:.]/g, '-')}-runde`);
    const skill = await click(a, /skill=DA/);
    check('a specialty skill swapped in', skill !== null && await noReload(a), skill || 'no skill link');
    const buffs = sql('SELECT bufflist FROM accounts WHERE acctid=1');
    check('the skill left a buff on the account', /"rounds";i:\d+/.test(buffs), buffs.slice(0, 120));
    const statsText = ((await a.textContent('[data-lotgd-region="stats"]')) || '').replace(/\s+/g, ' ');
    console.log('stats:', statsText.slice(0, 300));
    await shot(a, `41-fight-${skin.replace(/[:.]/g, '-')}-skill`);
    if (await hrefOf(a, /auto=five/)) {
      await click(a, /auto=five/);
      check('five rounds swapped in', await noReload(a));
    }
    check('the fight ends in place', await finish(a, 'forest.php') && await noReload(a), (await text(a)).slice(-200));
    check('back in the forest after the fight', (await hrefOf(a, /^forest\.php\?op=search/)) !== null || (await hrefOf(a, /^news\.php/)) !== null);

    // Keyboard: the attack's access key plays a round after a swap.
    sql(`UPDATE accounts SET hitpoints=500, alive=1 WHERE acctid=1`);
    await fresh(a, 'forest.php');
    await click(a, /^forest\.php\?op=search(&|$)/);
    const key = await a.evaluate(() => {
      const l = [...document.querySelectorAll('[data-lotgd-region="nav"] a[accesskey]')].find(x => /op=fight(&c=[^&]*)?$/.test(x.getAttribute('href')));
      return l ? l.getAttribute('accesskey').replace(/["']/g, '') : null;
    });
    if (key) {
      const before = Number(sql('SELECT page_revision FROM accounts WHERE acctid=1'));
      await a.keyboard.press(key.toLowerCase()); await a.waitForLoadState('load'); await settle(a, 1200);
      check('the attack key plays a round in place', Number(sql('SELECT page_revision FROM accounts WHERE acctid=1')) === before + 1 && await noReload(a), `key ${key}`);
      const again = Number(sql('SELECT page_revision FROM accounts WHERE acctid=1'));
      if (await hrefOf(a, /op=fight/)) {
        await a.keyboard.press(key.toLowerCase()); await settle(a, 1200);
        check('and again after the swap', Number(sql('SELECT page_revision FROM accounts WHERE acctid=1')) === again + 1);
      }
    } else {
      check('the attack link carries an access key', false);
    }

    // Double click on the attack: one round.
    const fightHref = await hrefOf(a, /^forest\.php\?op=fight(&c=[^&]*)?$/);
    if (fightHref) {
      const before = Number(sql('SELECT page_revision FROM accounts WHERE acctid=1'));
      await a.dblclick(`[data-lotgd-region="nav"] a[href="${fightHref}"] >> nth=0`); await settle(a, 1500);
      check('a double click plays one round', Number(sql('SELECT page_revision FROM accounts WHERE acctid=1')) === before + 1 && await noReload(a));
    }

    // Fleeing, from a fresh fight.
    sql(`UPDATE accounts SET hitpoints=500, alive=1, badguy='' WHERE acctid=1`);
    await fresh(a, 'forest.php');
    await click(a, /^forest\.php\?op=search(&|$)/);
    let fled = false;
    for (let i = 0; i < 15 && !fled; i++) {
      if (!(await click(a, /^forest\.php\?op=run/))) break;
      fled = (await hrefOf(a, /^forest\.php\?op=search/)) !== null;
    }
    check('fleeing in place', fled && await noReload(a));

    // The back button leaves the in-place page for badnav.
    await a.goBack(); await a.waitForLoadState('load'); await settle(a, 1200);
    check('back button goes to badnav', a.url().includes('badnav.php'), a.url());

    // Defeat: a hopeless fight ends in the news.
    sql(`UPDATE accounts SET hitpoints=1, attack=1, defense=1, alive=1 WHERE acctid=1`);
    await fresh(a, 'forest.php');
    await click(a, /^forest\.php\?op=search&type=thrill|^forest\.php\?op=search(&|$)/);
    await finish(a, 'forest.php');
    check('a defeat ends in place', await noReload(a) && (await hrefOf(a, /^news\.php/)) !== null, (await text(a)).slice(-160));
    await shot(a, `42-fight-${skin.replace(/[:.]/g, '-')}-niederlage`);

    // Graveyard: torment a soul.
    sql(`UPDATE accounts SET alive=0, hitpoints=0, gravefights=10, attack=60, defense=60, badguy='' WHERE acctid=1`);
    await fresh(a, 'graveyard.php');
    await click(a, /^graveyard\.php\?op=search/);
    check('graveyard search in place', await noReload(a) && (await hrefOf(a, /^graveyard\.php\?.*op=fight/)) !== null, (await text(a)).slice(0, 160));
    await click(a, /^graveyard\.php\?.*op=fight/);
    check('graveyard round in place', await noReload(a));

    // The master.
    sql(`UPDATE accounts SET alive=1, hitpoints=500, maxhitpoints=500, attack=60, defense=60, badguy='', seenmaster=0, level=5, experience=100000 WHERE acctid=1`);
    await fresh(a, 'train.php');
    await click(a, /^train\.php\?op=challenge/);
    check('challenging the master in place', await noReload(a) && (await hrefOf(a, /^train\.php\?.*op=fight/)) !== null, (await text(a)).slice(0, 160));
    await finish(a, 'train.php');
    check('the master fight ends in place', await noReload(a), (await text(a)).slice(-160));

    // PvP: attack the sleeping warrior.
    sql(`UPDATE accounts SET alive=1, hitpoints=500, level=5, playerfights=5, badguy='', pvpflag='2020-01-01 00:00:00' WHERE acctid=1`);
    await fresh(a, 'pvp.php');
    const sam = sql(`SELECT acctid FROM accounts WHERE login='sleepere2e'`);
    const attackHref = await a.evaluate(id => {
      const l = [...document.querySelectorAll('[data-lotgd-region="content"] a')].find(x => (x.getAttribute('href') || '').startsWith('pvp.php?act=attack&name=' + id));
      return l ? l.getAttribute('href') : null;
    }, sam);
    let attack = null;
    if (attackHref) {
      await a.click(`[data-lotgd-region="content"] a[href="${attackHref}"] >> nth=0`); await a.waitForLoadState('load'); await settle(a);
      // The swap can take longer than settle() on a slow page: wait for the fight's link.
      await a.waitForSelector('a[href*="pvp.php"][href*="op=fight"]', { timeout: 8000 }).catch(() => {});
      attack = attackHref;
    }
    check('a pvp attack in place', attack !== null && await noReload(a) && (await hrefOf(a, /^pvp\.php\?.*op=fight/)) !== null, attack || (await text(a)).slice(0, 200));
    await finish(a, 'pvp.php');
    check('the pvp fight ends in place', await noReload(a), (await text(a)).slice(-160));

    // The dragon: the fight swaps in; its kill is a page change.
    sql(`UPDATE accounts SET alive=1, hitpoints=1000, maxhitpoints=1000, level=15, attack=300, defense=300, badguy='' WHERE acctid=1`);
    await fresh(a, 'dragon.php');
    check('the dragon fight begins', (await hrefOf(a, /^dragon\.php\?.*op=fight/)) !== null, (await text(a)).slice(0, 160));
    await click(a, /^dragon\.php\?.*op=fight/);
    check('a dragon round in place', await noReload(a));
    await shot(a, `43-fight-${skin.replace(/[:.]/g, '-')}-drache`);

    await browser.close();
  } finally {
    sql(`UPDATE settings SET value='${oldSkin}' WHERE setting='defaultskin'; DELETE FROM accounts WHERE login='sleepere2e'; UPDATE accounts SET level=5, alive=1, hitpoints=60, maxhitpoints=60, badguy='', dragonkills=1 WHERE acctid=1`);
  }
  const passed = results.filter(Boolean).length;
  console.log(`${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
