// The dwellings editor and the pages that read dwid themselves: coffers withdraw, rename save, editor edit/save/create.
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
         gold=1000, gems=50, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', badguy='', specialinc='' WHERE acctid=1;
       DELETE FROM accounts_output WHERE acctid=1; DELETE FROM dwellings; DELETE FROM dwellingkeys; DELETE FROM module_userprefs WHERE modulename='dwellings' AND userid=1;
       INSERT INTO dwellings (ownerid,name,description,location,windowpeer,status,type,storedinfo,gold) VALUES (1,'Edit Shack','A shack','Degolburg','',1,'dwshacks','',100);`);
  const dwid = sql(`SELECT dwid FROM dwellings WHERE ownerid=1`);
  const b = await chromium.launch(cfg.launch);
  const p = await (await b.newContext()).newPage();
  const errors = []; p.on('pageerror', e => errors.push(e.message));
  const go = async part => { const l = await p.$(`a[href*="${part}"]`); if (!l) { const seen = await p.$$eval('a', as => [...new Set(as.map(a => a.getAttribute('href')))].filter(h => h && /module=dwell/.test(h)).join(' | ')); throw new Error('no link ' + part + ' among ' + seen); } await l.click(); await p.waitForLoadState('load'); };
  const bad = async () => /SQL|Exception|Uncaught|Warning: |Fatal/i.test(await p.innerText('body'));
  try {
    await p.goto(BASE + 'home.php'); await p.fill('#name', 'Admin'); await p.fill('#password', cfg.PASSWORD);
    await p.click("input[value='Log in']"); await p.waitForLoadState('load'); await p.goto(BASE + 'badnav.php');
    await go('module=dwellings'); await go(`op=enter&dwid=${dwid}`); await go(`op=coffers&dwid=${dwid}`);
    const withForm = await p.$('form[action*="subop=with&g=gold"]');
    if (withForm) { await withForm.$eval('input[name="amount"]', i => { i.value = '30'; }); await withForm.$eval('input[type="submit"]', s => s.click()); await p.waitForLoadState('load'); }
    check('withdrawing from the coffers works', withForm && sql(`SELECT gold FROM dwellings WHERE dwid=${dwid}`) === '70' && !(await bad()), sql(`SELECT gold FROM dwellings WHERE dwid=${dwid}`));
    await go(`op=enter&dwid=${dwid}`); await go(`op=manage&dwid=${dwid}`); await go(`op=manage-names&dwid=${dwid}`);
    await p.fill('#inputnewname', 'Renamed Shack'); await p.click("form[action*='change=name'] input[type=submit]"); await p.waitForLoadState('load');
    check('renaming saves', sql(`SELECT name FROM dwellings WHERE dwid=${dwid}`) === 'Renamed Shack' && !(await bad()), sql(`SELECT name FROM dwellings WHERE dwid=${dwid}`));
    await go('module=dwellings&c='); await go('village.php'); await go('superuser.php');
    await go('module=dwellingseditor');
    const editLink = await p.$(`a[href*="module=dwellingseditor"][href*="dwid=${dwid}"]`);
    check('the editor lists the dwelling', !!editLink);
    await go(`dwid=${dwid}`);
    const editHref = await p.$(`a[href*="op=edit"][href*="dwid=${dwid}"]`);
    if (editHref) { await editHref.click(); await p.waitForLoadState('load'); }
    const form = await p.$(`form[action*="op=dwsave&dwid=${dwid}"]`);
    check('editing opens the form for that dwelling', !!form && !(await bad()), p.url());
    if (form) { await form.$eval('input[type=submit]', s => s.click()); await p.waitForLoadState('load'); }
    check('saving it works', !(await bad()) && sql(`SELECT COUNT(*) FROM dwellings WHERE dwid=${dwid}`) === '1', p.url());
    await go('superuser.php'); await go('module=dwellingseditor');
    const create = await p.$('a[href*="module=dwellingseditor"][href*="op=new"]');
    if (create) { await create.click(); await p.waitForLoadState('load'); }
    const button = await p.$eval('form[action*="op=dwsave"] input[type=submit]', s => s.value).catch(() => null);
    check('a new dwelling gets the Create button', button === 'Create', String(button) + ' ' + p.url());
    check('no page errors', errors.length === 0, errors.join(' | '));
  } catch (e) { check('flow', false, e.message.slice(0, 400)); } finally {
    await b.close();
    sql(`UPDATE modules SET active=0 WHERE modulename IN ('dwellings','dwshacks','cityprefs','dwellingseditor','cities')`);
  }
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
})().catch(e => { console.error(e); process.exit(2); });
