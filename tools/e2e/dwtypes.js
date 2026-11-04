// dwellings: type (buying), cityid (the city lookup), typeid (type editor), cityprefs rename.
const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const results = [];
function check(n, ok, i = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + n + (i ? ' -- ' + i : '')); }
(async () => {
  sql(`UPDATE modules SET active=1 WHERE modulename IN ('cities','dwellings','dwshacks','cityprefs','dwellingseditor');
       UPDATE accounts SET prefs='a:1:{s:13:"partialrender";i:0;}', alive=1, location='Degolburg', loggedin=0, lasthit=NOW(), experience=0, seenmaster=0,
         gold=100000, gems=500, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', badguy='', specialinc='' WHERE acctid=1;
       DELETE FROM accounts_output WHERE acctid=1; DELETE FROM dwellings; DELETE FROM dwellingkeys;`);
  const b = await chromium.launch(cfg.launch);
  const p = await (await b.newContext()).newPage();
  const errors = []; p.on('pageerror', e => errors.push(e.message));
  const go = async part => { const l = await p.$(`a[href*="${part}"]`); if (!l) { const seen = await p.$$eval('a', as => [...new Set(as.map(a => a.getAttribute('href')))].filter(h => h && /module=/.test(h)).join(' | ')); throw new Error('no link ' + part + ' among ' + seen); } await l.click(); await p.waitForLoadState('load'); };
  const bad = async () => /SQL|Exception|Uncaught|Warning: |Fatal|error in your/i.test(await p.innerText('body'));
  try {
    await p.goto(BASE + 'home.php'); await p.fill('#name', 'Admin'); await p.fill('#password', cfg.PASSWORD);
    await p.click("input[value='Log in']"); await p.waitForLoadState('load'); await p.goto(BASE + 'badnav.php');
    await go('module=dwellings&c=');
    check('the hamlet finds its city (cityid lookup)', !(await bad()) && /op=buy&type=dwshacks/.test(await p.content()), p.url());
    await go('op=buy&type=dwshacks');
    const body = await p.innerText('body');
    check('buying a shack (type in the queries) works', !(await bad()), body.slice(0, 200).replace(/\s+/g, ' '));
    const form = await p.$("form[action*='op=buy'][action*='type=dwshacks']");
    if (form) {
      const need = body.replace(/\s+/g, ' ');
      const gems = (need.match(/(\d+) Gems?/i) || [])[1] || '0';
      const gold = (need.match(/(\d+) Gold/i) || [])[1] || '0';
      console.log('  paying', gems, 'gems and', gold, 'gold');
      if (await form.$("input[name='gems']")) { await form.$eval("input[name='gems']", (i, v) => { i.value = v; }, gems); }
      if (await form.$("input[name='gold']")) { await form.$eval("input[name='gold']", (i, v) => { i.value = v; }, gold); }
      await form.$eval('input[type=submit]', s => s.click()); await p.waitForLoadState('load');
      console.log('  after buying:', (await p.innerText('[data-lotgd-region="content"], body')).replace(/\s+/g, ' ').slice(0, 300));
    } else { console.log('  no buy form'); }
    check('the purchase is written with its type', !(await bad()) && sql(`SELECT COUNT(*) FROM dwellings WHERE ownerid=1 AND type='dwshacks'`) === '1', sql(`SELECT COUNT(*) FROM dwellings WHERE ownerid=1`));
    await go('module=dwellings&c='); await go('village.php'); await go('superuser.php');
    await go('module=dwellingseditor'); await go('op=typesu'); await go('op=typeeditmodule&typeid=1');
    check('the type editor opens (typeid in its query)', !(await bad()), p.url());
    await go('superuser.php'); await go('module=cityprefs'); await go('cityid=5');
    await go('op=editcity&cityid=5');
    await p.fill('#cityname', "Romar's Rest").catch(async () => p.fill("input[name='cityname']", "Romar's Rest"));
    await p.click("form[action*='editcity2'] input[type=submit]"); await p.waitForLoadState('load');
    check("renaming a city with ' in the name is stored as typed", !(await bad()) && sql(`SELECT cityname FROM cityprefs WHERE cityid=5`) === "Romar's Rest", sql(`SELECT cityname FROM cityprefs WHERE cityid=5`));
    check('no page errors', errors.length === 0, errors.join(' | '));
  } catch (e) { check('flow', false, e.message.slice(0, 400)); } finally {
    await b.close();
    sql(`UPDATE cityprefs SET cityname='Romar' WHERE cityid=5; UPDATE modules SET active=0 WHERE modulename IN ('dwellings','dwshacks','cityprefs','dwellingseditor','cities')`);
  }
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
})().catch(e => { console.error(e); process.exit(2); });
