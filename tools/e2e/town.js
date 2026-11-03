// Shops and the inn in place: weapons, armour, healer, stables, mercenary camp, gypsy, inn; the room logs out.
const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const results = [];
function check(name, ok, info = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (info ? ' -- ' + info : '')); }
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const settle = (p, ms = 700) => p.waitForTimeout(ms);
const OUT = process.env.OUT || '.';
const shot = async (p, name) => { await settle(p, 300); await p.screenshot({ path: `${OUT}/${name}.png`, fullPage: true }); console.log('shot', name); };
const noReload = p => p.evaluate(() => window.__noReload === 1);
const text = async p => ((await p.textContent('[data-lotgd-region="content"]')) || '').replace(/\s+/g, ' ');
const stats = async p => ((await p.textContent('[data-lotgd-region="stats"]')) || '').replace(/\s+/g, ' ');
const acct = col => sql(`SELECT ${col} FROM accounts WHERE acctid=1`);

async function hrefIn(page, region, re) {
  return page.evaluate(([region, src]) => {
    const re = new RegExp(src);
    const a = [...document.querySelectorAll(`[data-lotgd-region="${region}"] a`)].find(x => re.test(x.getAttribute('href') || ''));
    return a ? a.getAttribute('href') : null;
  }, [region, re.source]);
}
async function go(page, re, region = 'nav') {
  const href = (await hrefIn(page, region, re)) || (region === 'nav' ? await hrefIn(page, 'content', re) : null);
  if (!href) return null;
  await page.click(`a[href="${href}"] >> nth=0`);
  await page.waitForLoadState('load'); await settle(page);
  return href;
}
async function login(page) {
  await page.goto(BASE + 'home.php');
  await page.fill('#name', 'Admin');
  await page.fill('#password', cfg.PASSWORD);
  await page.click("input[value='Log in'], form[action*='login.php'] button[type='submit']");
  await page.waitForLoadState('load'); await settle(page);
}

(async () => {
  const skin = cfg.skin('twig:aurora');
  const oldSkin = sql(`SELECT value FROM settings WHERE setting='defaultskin'`);
  sql(`INSERT INTO settings (setting,value) VALUES ('partial_render','1'),('realtime_enabled','1'),('realtime_transport','poll') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`${cfg.setting('defaultskin', skin)}`);
  sql(`UPDATE accounts SET prefs='a:1:{s:13:"partialrender";i:1;}', alive=1, level=5, dragonkills=0, gold=20000, gems=100, hitpoints=10, maxhitpoints=100, weapon='Fists', weaponvalue=0, weapondmg=0, armor='T-Shirt', armorvalue=0, armordef=0, hashorse=0, location='Degolburg', badguy='', boughtroomtoday=0, loggedin=0, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}' WHERE acctid=1; DELETE FROM accounts_output WHERE acctid=1`);
  try {
    const browser = await chromium.launch(cfg.launch);
    const a = await (await browser.newContext({ viewport: { width: 1280, height: 1000 } })).newPage();
    a.on('pageerror', e => console.log('pageerror:', e.message));
    a.on('dialog', d => d.accept());
    await login(a);
    await a.goto(BASE + 'badnav.php'); await settle(a);
    for (let i = 0; i < 10 && !(await a.title()).includes('Square'); i++) {
      if (!(await go(a, /^(village\.php|newday\.php)/, 'content')) && !(await go(a, /^(village\.php|newday\.php)/))) break;
    }
    await a.evaluate(() => { window.__noReload = 1; });

    // Weapons: buy the best one affordable.
    await go(a, /^weapons\.php/);
    const gold0 = Number(acct('gold'));
    const buy = await go(a, /^weapons\.php\?op=buy/, 'content');
    check('a weapon bought in place', buy !== null && await noReload(a) && acct('weapon') !== 'Fists', acct('weapon'));
    check('the gold went down', Number(acct('gold')) < gold0, `${gold0} -> ${acct('gold')}`);
    check('the stats show the new weapon', (await stats(a)).includes(acct('weapon').replace(/`./g, '')), (await stats(a)).slice(0, 200));
    await shot(a, `60-town-${skin.replace(/[:.]/g, '-')}-waffe`);

    // A double click on a buy link: one purchase.
    await go(a, /^village\.php/);
    await go(a, /^armor\.php/);
    const armorHref = await hrefIn(a, 'content', /^armor\.php\?op=buy/);
    const goldA = Number(acct('gold'));
    const rev = Number(acct('page_revision'));
    if (armorHref) {
      await a.dblclick(`a[href="${armorHref}"] >> nth=0`); await settle(a, 1500);
    }
    check('armour bought in place', armorHref !== null && await noReload(a) && acct('armor') !== 'T-Shirt', acct('armor'));
    check('a double click buys once', Number(acct('page_revision')) === rev + 1 && Number(acct('gold')) < goldA, `rev ${rev} -> ${acct('page_revision')}`);

    // Healer.
    // The healer's hut is in the forest.
    await go(a, /^village\.php/);
    await go(a, /^forest\.php/);
    const hut = await go(a, /^healer\.php/);
    const heal = await go(a, /^healer\.php\?op=buy/, 'content') || await go(a, /^healer\.php\?op=buy/);
    check('healed in place', hut !== null && heal !== null && await noReload(a) && Number(acct('hitpoints')) > 10, `${hut} ${heal} hp=${acct('hitpoints')}`);

    // Stables: examine, buy, sell.
    await go(a, /^village\.php/);
    await go(a, /^stables\.php/);
    await go(a, /^stables\.php\?op=examine/);
    check('a mount examined in place', await noReload(a) && (await hrefIn(a, 'nav', /op=buymount/)) !== null);
    await go(a, /op=buymount/);
    await go(a, /op=confirmbuy/);
    check('a mount bought in place', await noReload(a) && Number(acct('hashorse')) > 0, acct('hashorse'));
    await go(a, /op=sellmount/);
    await go(a, /op=confirmsell/);
    check('and sold in place', await noReload(a) && Number(acct('hashorse')) === 0, acct('hashorse'));

    // Mercenary camp.
    await go(a, /^village\.php/);
    const camp = await go(a, /^mercenarycamp\.php/);
    check('the mercenary camp in place', camp !== null && await noReload(a), camp || 'no link');
    const hire = await go(a, /^mercenarycamp\.php\?op=buy/, 'content') || await go(a, /^mercenarycamp\.php\?op=buy/);
    check('a mercenary hired in place', hire !== null && await noReload(a) && acct('companions').length > 10, hire || 'no hire link');

    // The gypsy: pay, then talk (a page change, as a redirect).
    await go(a, /^village\.php/);
    await go(a, /^gypsy\.php/);
    check('the gypsy in place', await noReload(a), await a.title());
    await go(a, /^gypsy\.php\?op=pay/);
    check('paying the gypsy leads to her talk', (await a.title()).length > 0 && a.url().includes('gypsy.php'), a.url());
    await a.evaluate(() => { window.__noReload = 1; });

    // The inn: the chat, the bartender, colours with focus.
    await go(a, /^village\.php/);
    await go(a, /^inn\.php/);
    await go(a, /^inn\.php\?op=converse/);
    check('the inn chat in place', await noReload(a) && (await a.$('[name="insertcommentary"]')) !== null);
    await go(a, /^inn\.php(\?c=[^&]*)?$/);
    await go(a, /^inn\.php\?op=bartender/);
    check('the bartender in place', await noReload(a), (await text(a)).slice(0, 120));
    // The bartender talks after a bribe, which can fail.
    for (let i = 0; i < 8 && !(await hrefIn(a, 'nav', /act=colors/)); i++) {
      if (!(await hrefIn(a, 'nav', /act=bribe&type=gem&amt=3/))) await go(a, /act=bribe$|act=bribe&c=/);
      await go(a, /act=bribe&type=gem&amt=3/);
      if (!(await hrefIn(a, 'nav', /act=colors/))) await go(a, /^inn\.php\?op=bartender(&c=[^&]*)?$/);
    }
    const colors = await go(a, /act=colors/);
    check('colours in place, the field focused', await noReload(a) && await a.evaluate(() => document.activeElement && document.activeElement.name === 'testtext'),
      `${colors} field=${(await a.$('[name="testtext"]')) !== null} active=${await a.evaluate(() => document.activeElement && (document.activeElement.tagName + ':' + document.activeElement.name))} autofocus=${await a.evaluate(() => !!document.querySelector('[autofocus]'))}`);
    await shot(a, `61-town-${skin.replace(/[:.]/g, '-')}-barkeeper`);

    // The room logs out: a real page change.
    await go(a, /^inn\.php$|^inn\.php\?c=|^inn\.php\?op=strolldown/) || await go(a, /^inn\.php/);
    await go(a, /^inn\.php\?op=room/);
    const stay = await hrefIn(a, 'content', /^inn\.php\?op=room&pay=/) || await hrefIn(a, 'nav', /^inn\.php\?op=room&pay=/);
    check('the room offered in place', await noReload(a) && stay !== null, stay || (await text(a)).slice(0, 160));
    if (stay) {
      await a.click(`a[href="${stay}"] >> nth=0`); await a.waitForLoadState('load'); await settle(a, 1200);
      check('taking the room logs out with a page change', acct('loggedin') === '0' && !(await a.evaluate(() => window.__noReload === 1)), a.url());
    }
    await browser.close();
  } finally {
    sql(`${cfg.setting('defaultskin', oldSkin)}; UPDATE accounts SET hitpoints=60, maxhitpoints=60, gold=250, hashorse=0, companions='a:0:{}' WHERE acctid=1`);
  }
  const passed = results.filter(Boolean).length;
  console.log(`${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
