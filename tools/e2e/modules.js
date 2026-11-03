// Module pages in place: cities (travel, a fight on the road), lovers, dwellings.
const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const OUT = process.env.OUT || '.';
const SKIN = process.env.SKIN || 'twig:aurora';
const tag = SKIN.replace(/[:.]/g, '-');
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const results = [];
function check(n, ok, i = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + n + (i ? ' -- ' + i : '')); }

(async () => {
  const oldSkin = sql(`SELECT value FROM settings WHERE setting='defaultskin'`);
  const MODS = "'cities','lovers','dwellings','dwshacks','cityprefs'";
  const oldActive = sql(`SELECT GROUP_CONCAT(modulename) FROM modules WHERE active=0 AND modulename IN (${MODS})`);
  sql(`UPDATE modules SET active=1 WHERE modulename IN (${MODS})`);
  execSync(`rm -rf ${cfg.GAME || ''}/data_cache/* 2>/dev/null || true`);
  sql(`UPDATE settings SET value='${SKIN}' WHERE setting='defaultskin';
       INSERT INTO settings (setting,value) VALUES ('partial_render','1') ON DUPLICATE KEY UPDATE value='1';
       UPDATE accounts SET prefs='a:2:{s:13:"partialrender";i:1;s:10:"sexuality";i:1;}', alive=1, location='Degolburg', loggedin=0,
         restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', badguy='', specialinc='', specialmisc='', experience=0, seenmaster=0,
         gold=1000, hitpoints=500, maxhitpoints=500, attack=50, defense=50, turns=20, dragonkills=31 WHERE acctid=1;
       DELETE FROM accounts_output WHERE acctid=1;
       DELETE FROM module_userprefs WHERE modulename IN ('cities','lovers','dwellings') AND userid=1;
       INSERT INTO module_settings (modulename,setting,value) VALUES ('cities','safechance','100') ON DUPLICATE KEY UPDATE value='100';
       INSERT INTO module_settings (modulename,setting,value) VALUES ('cities','travelspecialchance','0') ON DUPLICATE KEY UPDATE value='0';
       INSERT INTO module_settings (modulename,setting,value) VALUES ('dwshacks','maxkeys','5') ON DUPLICATE KEY UPDATE value='5';
       DELETE FROM dwellings; DELETE FROM dwellingkeys;
       INSERT INTO dwellings (ownerid,name,description,location,windowpeer,status,type,storedinfo) VALUES (1,'Test Shack','A shack','Degolburg','',1,'dwshacks','');`);
  const dwid = sql(`SELECT dwid FROM dwellings WHERE ownerid=1`);
  const b = await chromium.launch(cfg.launch);
  try {
    const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    p.on('pageerror', e => console.log('pageerror:', e.message));
    const answers = [];
    p.on('response', r => {
      if (r.request().headers()['hx-request'] === 'true') {
        const h = r.headers();
        answers.push({ url: r.url().replace(BASE, ''), partial: h['x-lotgd-partial'] || '', redirect: h['hx-redirect'] || '', fallback: h['x-lotgd-partial-fallback'] || '', forms: h['x-lotgd-partial-forms'] || '' });
      }
    });
    const last = () => answers[answers.length - 1] || {};
    async function follow(hrefPart, label) {
      const before = answers.length;
      // Space-separated parts, each in the link.
      const attrs = hrefPart.split(' ').map(part => `[href*="${part}"]`).join('');
      const link = await p.$(`[data-lotgd-region="nav"] a${attrs}, [data-lotgd-region="content"] a${attrs}`);
      if (!link) {
        const seen = await p.$$eval('[data-lotgd-region="nav"] a, [data-lotgd-region="content"] a', as => [...new Set(as.map(x => x.getAttribute('href')))].filter(h => h && /module|inn/.test(h)).join(' | '));
        check(label + ': link there', false, hrefPart + ' among ' + seen);
        return null;
      }
      await link.click();
      for (let i = 0; i < 40 && answers.length === before; i++) { await p.waitForTimeout(100); }
      await p.waitForTimeout(500);
      return last();
    }
    const stillSame = () => p.evaluate(() => window.__noReload === 1).catch(() => false);
    const mark = () => p.evaluate(() => { window.__noReload = 1; });

    await p.goto(BASE + 'home.php'); await p.fill('#name', 'Admin'); await p.fill('#password', cfg.PASSWORD);
    await p.click("input[value='Log in'], form[action*='login.php'] button[type='submit']");
    await p.waitForLoadState('load'); await p.goto(BASE + 'badnav.php'); await p.waitForLoadState('load');
    const meta = await p.$eval('meta[name="lotgd-partial-forms"]', m => m.content).catch(() => null);
    check('the village names only its own forms', meta !== null && JSON.parse(meta).length <= 3, meta);
    await mark();

    let a;
    // lovers at the inn.
    a = await follow('inn.php', 'inn');
    a = await follow('module=lovers&op=chat', 'chat');
    check('talking at the inn (lovers) changes in place', a && a.partial === '1' && await stillSame(), JSON.stringify(a));
    a = await follow('inn.php', 'inn again');
    a = await follow('module=lovers&op=flirt', 'flirt');
    check('flirting changes in place', a && a.partial === '1' && await stillSame(), JSON.stringify(a));
    await p.screenshot({ path: `${OUT}/102-modules-${tag}-lovers.png` });
    a = await follow('village.php', 'village');
    // cities: the travel menu, a waylaid trip and its fight.
    a = await follow('module=cities&op=travel', 'travel');
    check('the travel menu changes in place', a && a.partial === '1' && await stillSame(), JSON.stringify(a));
    await p.screenshot({ path: `${OUT}/100-modules-${tag}-travel.png` });
    a = await follow('module=cities&op=travel&city=', 'a trip');
    const fighting = await p.$('[data-lotgd-region="nav"] a[href*="module=cities"][href*="op=fight"]');
    check('a trip with a fight on the road changes in place', a && a.partial === '1' && fighting && await stillSame(), JSON.stringify(a));
    let rounds = 0;
    while (await p.$('[data-lotgd-region="nav"] a[href*="module=cities"][href*="op=fight"]') && rounds < 20) {
      a = await follow('module=cities op=fight', 'a round');
      rounds++;
      if (!a || a.partial !== '1') { break; }
    }
    check('the rounds change in place', rounds > 0 && (a.partial === '1' || /village/.test(a.redirect)), `${rounds} rounds, last ${JSON.stringify(a)}`);
    await p.screenshot({ path: `${OUT}/101-modules-${tag}-fight.png` });
    // On to the destination (a redirect into the village: a page change).
    sql(`UPDATE module_settings SET value='0' WHERE modulename='cities' AND setting='safechance'`);
    for (let i = 0; i < 5 && await p.$('[data-lotgd-region="nav"] a[href*="module=cities&op=travel&city="]'); i++) {
      a = await follow('module=cities&op=travel&city=', 'arrival');
      await p.waitForLoadState('load');
      console.log('  then', JSON.stringify(a), await p.title());
    }
    const arrived = sql(`SELECT location FROM accounts WHERE acctid=1`);
    check('the trip ends in another city', arrived !== 'Degolburg', arrived);

    // Lovers at the inn of the city arrived in; the dwelling is there too.
    sql(`UPDATE dwellings SET location='${arrived}' WHERE dwid=${dwid}`);
    await mark();
    console.log('at', p.url(), await p.title());

    // dwellings: the hamlet with its sortable table, the coffers, a key, renaming.
    a = await follow('module=dwellings', 'hamlet');
    check('the hamlet changes in place', a && a.partial === '1' && await stillSame(), JSON.stringify(a));
    await p.waitForTimeout(1500);
    const sortable = await p.$('#dwellings-owned-local-table_wrapper, .dt-container, .dataTables_wrapper');
    check('its table is sortable after the swap (no inline script)', !!sortable && (await p.$$('[data-lotgd-region="content"] script')).length === 0);
    await p.screenshot({ path: `${OUT}/103-modules-${tag}-hamlet.png` });
    a = await follow(`op=enter&dwid=${dwid}`, 'enter');
    check('entering the dwelling changes in place', a && a.partial === '1' && await stillSame(), JSON.stringify(a));
    const left = await p.evaluate(() => window.jQuery && window.jQuery.fn.dataTable ? window.jQuery.fn.dataTable.settings.length : -1);
    check('the hamlet\'s tables were torn down with the swap', left === 0, 'DataTables instances left: ' + left);
    a = await follow(`op=coffers&dwid=${dwid}`, 'coffers');
    check('the coffers change in place', a && a.partial === '1', JSON.stringify(a));
    const forms = JSON.parse(a.forms || '[]');
    check('the answer names the coffers forms', forms.some(f => /op=coffers&subop=dep/.test(f)), a.forms);
    const goldBefore = Number(sql(`SELECT gold FROM accounts WHERE acctid=1`));
    const depForm = await p.$('form[action*="op=coffers&subop=dep&g=gold"]');
    if (depForm) {
      await depForm.$eval('input[name="amount"]', i => { i.value = '10'; }).catch(() => {});
      const before = answers.length;
      await depForm.$eval('input[type="submit"]', s => s.click());
      for (let i = 0; i < 40 && answers.length === before; i++) { await p.waitForTimeout(100); }
      await p.waitForTimeout(500);
      a = last();
    }
    const goldAfter = Number(sql(`SELECT gold FROM accounts WHERE acctid=1`));
    const coffer = Number(sql(`SELECT gold FROM dwellings WHERE dwid=${dwid}`));
    check('a deposit posts in place and books once', depForm && a.partial === '1' && goldBefore - goldAfter === 10 && coffer === 10 && await stillSame(), `${JSON.stringify(a)} gold ${goldBefore}->${goldAfter} coffer ${coffer}`);
    a = await follow(`op=enter&dwid=${dwid}`, 'back inside');
    a = await follow(`op=manage&dwid=${dwid}`, 'manage');
    a = await follow(`op=keys&dwid=${dwid}`, 'keys');
    a = await follow('subop=givekey', 'give a key');
    const focused = await p.evaluate(() => document.activeElement && document.activeElement.id);
    check('giving a key changes in place, the name field focused', a && a.partial === '1' && focused === 'name', `${JSON.stringify(a)} focus=${focused}`);
    a = await follow(`op=manage&dwid=${dwid}`, 'manage');
    a = await follow('op=manage-names', 'rename');
    check('the rename page changes in place', a && a.partial === '1' && await stillSame(), JSON.stringify(a));
    if (await p.$('#inputnewname')) {
      await p.type('#inputnewname', '`@Green Shack');
      await p.waitForTimeout(300);
    }
    const preview = await p.$eval('#previewtextnewname', e => e.innerHTML).catch(() => '');
    check('the name preview shows the colours', /colLtGreen/.test(preview) && /Green Shack/.test(preview), preview.slice(0, 200));
    await p.screenshot({ path: `${OUT}/104-modules-${tag}-rename.png` });

    // Back: the latest page; a double click: one request.
    await p.goBack(); await p.waitForTimeout(1500);
    check('Back shows the latest page (badnav)', /badnav\.php/.test(p.url()), p.url());
    await p.goto(BASE + 'badnav.php'); await p.waitForLoadState('load');
    a = await follow('module=dwellings&op=enter', 'inside after Back');
    a = await follow('module=dwellings&c=', 'hamlet after Back');
    a = await follow('village.php', 'village after Back');
    await mark();
    console.log('at', p.url(), await p.title());
    const revBefore = Number(sql(`SELECT page_revision FROM accounts WHERE acctid=1`));
    const before = answers.length;
    await p.dblclick('[data-lotgd-region="nav"] a[href*="module=cities&op=travel"]');
    await p.waitForTimeout(1500);
    const revAfter = Number(sql(`SELECT page_revision FROM accounts WHERE acctid=1`));
    check('a double click on Travel sends it once', answers.length - before === 1 && revAfter - revBefore === 1, `${answers.length - before} requests, revision +${revAfter - revBefore}`);

    // A page a module does not declare loads in full.
    a = await follow('village.php', 'village');
    a = await follow('module=dwellings', 'hamlet again');
    a = await follow(`op=enter&dwid=${dwid}`, 'enter again');
    a = await follow(`op=manage&dwid=${dwid}`, 'manage again');
    a = await follow(`op=kickout&dwid=${dwid}`, 'kick out');
    check('a page the module does not declare (kicking out) loads in full before it runs', a && a.partial === '' && /op=kickout/.test(a.redirect), JSON.stringify(a));
  } finally {
    await b.close();
    sql(`UPDATE settings SET value='${oldSkin}' WHERE setting='defaultskin'; UPDATE module_settings SET value='50' WHERE modulename='cities' AND setting='safechance'`);
    if (oldActive && oldActive !== 'NULL') sql(`UPDATE modules SET active=0 WHERE FIND_IN_SET(modulename, '${oldActive}')`);
  }
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
  process.exit(results.every(Boolean) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
