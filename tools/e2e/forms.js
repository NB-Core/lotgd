// Forms in place: bank, preferences (tabs, search, save, password check), game settings,
// player search, the bartender's colours, the clan MOTD, and a form the server hands back.
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
async function submit(page, formSelector, button = "input[type='submit'], button[type='submit']") {
  await page.click(`${formSelector} ${button} >> nth=0`);
  await page.waitForLoadState('load'); await settle(page, 900);
}
async function login(page) {
  await page.goto(BASE + 'home.php');
  await page.fill('#name', 'Admin');
  await page.fill('#password', cfg.PASSWORD);
  await page.click("input[value='Log in'], form[action*='login.php'] button[type='submit']");
  await page.waitForLoadState('load'); await settle(page);
}
async function toVillage(page) {
  await page.goto(BASE + 'badnav.php'); await settle(page);
  for (let i = 0; i < 10 && !(await page.title()).includes('Square'); i++) {
    if (!(await go(page, /^(village\.php|newday\.php)/, 'content')) && !(await go(page, /^(village\.php|newday\.php)/))) break;
  }
}

(async () => {
  const skin = process.env.SKIN || 'twig:aurora';
  const tag = skin.replace(/[:.]/g, '-');
  const oldSkin = sql(`SELECT value FROM settings WHERE setting='defaultskin'`);
  sql(`INSERT INTO settings (setting,value) VALUES ('partial_render','1'),('realtime_enabled','1'),('realtime_transport','poll'),('guarded_actions','1') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`UPDATE settings SET value='${skin}' WHERE setting='defaultskin'`);
  // The clan these checks use; another suite may have removed it.
  sql(`INSERT INTO clans (clanid,clanname,clanshort,clanmotd,clandesc,motdauthor,descauthor,customsay) VALUES (26,'Formtest Hall','FT','','',1,1,'') ON DUPLICATE KEY UPDATE clanid=clanid`);
  sql(`UPDATE accounts SET prefs='a:2:{s:13:"partialrender";i:1;s:9:"mailwidth";i:60;}', alive=1, level=5, dragonkills=1, gold=1000, goldinbank=100, transferredtoday=0, hitpoints=100, maxhitpoints=100, experience=0, seenmaster=1, location='Degolburg', badguy='', clanid=26, clanrank=30, loggedin=0, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}' WHERE acctid=1; UPDATE accounts SET level=5, goldinbank=50, transferredtoday=0 WHERE acctid=385; DELETE FROM accounts_output WHERE acctid=1; UPDATE clans SET clanmotd='' WHERE clanid=26`);
  try {
    const browser = await chromium.launch(cfg.launch);
    const a = await (await browser.newContext({ viewport: { width: 1280, height: 1000 } })).newPage();
    a.on('pageerror', e => console.log('pageerror:', e.message));
    const dialogs = [];
    a.on('dialog', d => { dialogs.push(d.message()); d.accept(); });
    const posts = [];
    a.on('request', r => { if (r.method() === 'POST' && !r.url().includes('async/')) posts.push({ url: r.url(), htmx: !!r.headers()['hx-request'], body: r.postData() || '' }); });
    await login(a);
    await toVillage(a);
    await a.evaluate(() => { window.__noReload = 1; });

    // Bank: deposit, withdraw, transfer.
    await go(a, /^bank\.php/);
    await go(a, /^bank\.php\?op=deposit/);
    const bank0 = Number(acct('goldinbank'));
    await a.fill("form[action*='op=depositfinish'] input[name='amount']", '100');
    await submit(a, "form[action*='op=depositfinish']");
    check('a deposit posts in place', await noReload(a) && Number(acct('goldinbank')) === bank0 + 100, `${bank0} -> ${acct('goldinbank')}`);
    check('the stats show the new gold', ((await a.textContent('[data-lotgd-region="stats"]')) || '').includes(acct('gold')), acct('gold'));
    check('the booking went with htmx', posts.length > 0 && posts[posts.length - 1].htmx, JSON.stringify(posts.slice(-1)));
    await shot(a, `70-forms-${tag}-bank`);

    await go(a, /^bank\.php\?op=withdraw/);
    const bank1 = Number(acct('goldinbank'));
    await a.fill("form[action*='op=withdrawfinish'] input[name='amount']", '30');
    await submit(a, "form[action*='op=withdrawfinish']");
    check('a withdrawal posts in place', await noReload(a) && Number(acct('goldinbank')) === bank1 - 30, `${bank1} -> ${acct('goldinbank')}`);

    // A double click on deposit books once.
    await go(a, /^bank\.php\?op=deposit/);
    const bank2 = Number(acct('goldinbank'));
    await a.fill("form[action*='op=depositfinish'] input[name='amount']", '10');
    await a.dblclick("form[action*='op=depositfinish'] input[type='submit']");
    await a.waitForLoadState('load'); await settle(a, 1500);
    check('a double click deposits once', Number(acct('goldinbank')) === bank2 + 10, `${bank2} -> ${acct('goldinbank')}`);
    if (!(await noReload(a))) { await toVillage(a); await go(a, /^bank\.php/); await a.evaluate(() => { window.__noReload = 1; }); }

    await go(a, /^bank\.php\?op=transfer/);
    const other0 = Number(sql('SELECT goldinbank FROM accounts WHERE acctid=385'));
    await a.fill("form[action*='op=transfer2'] input[name='amount']", '10');
    await a.fill("form[action*='op=transfer2'] input[name='to']", 'offe2e');
    await submit(a, "form[action*='op=transfer2']");
    check('the transfer confirmation in place', await noReload(a) && (await a.$("form[action*='op=transfer3']")) !== null, (await text(a)).slice(0, 200));
    if (await a.$("form[action*='op=transfer3']")) await submit(a, "form[action*='op=transfer3']");
    check('the transfer booked in place', await noReload(a) && Number(sql('SELECT goldinbank FROM accounts WHERE acctid=385')) === other0 + 10, `${other0} -> ${sql('SELECT goldinbank FROM accounts WHERE acctid=385')} ${(await text(a)).slice(0, 300)}`);

    // Preferences: tabs and search after the swap, save in place, the tab stays.
    await go(a, /^village\.php/);
    await go(a, /^prefs\.php/);
    check('the preferences swap in', await noReload(a) && (await a.$('[data-showform-tab]')) !== null, await a.title());
    const tabs = await a.$$eval('[data-showform-tab]', t => t.map(x => x.textContent));
    check('the tabs are there, with All', tabs[0] === 'All' && tabs.length > 3, tabs.join('|'));
    const visible = () => a.$$eval('table[id^="showFormTable"] tbody tr', rows => rows.filter(r => r.style.display !== 'none').length);
    const all = await visible();
    await a.fill('input[type="search"]', 'mail');
    await settle(a, 300);
    const found = await visible();
    check('the search narrows the rows', found > 0 && found < all, `${all} -> ${found}`);
    await a.fill('input[type="search"]', '');
    // The tab with the mail width in it.
    const tabName = await a.$eval("input[name='mailwidth']", f => f.closest('tr').getAttribute('data-section'));
    await a.click(`[data-showform-tab][data-section="${tabName}"]`);
    check('a tab shows its section only', (await visible()) < all, tabName);
    await shot(a, `71-forms-${tag}-prefs-tab`);
    const mailField = await a.$("input[name='mailwidth']");
    if (mailField) await mailField.fill('72');
    await submit(a, "form[action='prefs.php?op=save']");
    check('saving the preferences in place', await noReload(a) && (await text(a)).includes('Settings saved'), (await text(a)).slice(0, 160));
    check('the saved preference stored', acct('prefs').includes('s:9:"mailwidth";s:2:"72"') || acct('prefs').includes('"mailwidth";i:72'), acct('prefs').slice(0, 200));
    const active = await a.$eval('[data-showform-tab].is-active', t => t.textContent).catch(() => '');
    check('the page comes back on the same tab', active === tabName, `${active} vs ${tabName}`);
    const before = posts.length;
    await a.click('[data-showform-tab][data-section-id="0"]');
    await a.fill("input[name='pass1']", 'ab');
    await a.fill("input[name='pass2']", 'ab');
    await a.click("form[action='prefs.php?op=save'] input[type='submit']");
    await settle(a, 800);
    check('a short password is caught before sending', dialogs.some(m => /too short/.test(m)) && posts.length === before, dialogs.join('|'));
    await a.fill("input[name='pass1']", '');
    await a.fill("input[name='pass2']", '');

    // The player search.
    await go(a, /^village\.php/);
    await go(a, /^list\.php/);
    await a.fill("form[action='list.php?op=search'] input[name='name']", 'Otto');
    await submit(a, "form[action='list.php?op=search']");
    check('the player search in place', await noReload(a) && (await text(a)).includes('Otto'), (await text(a)).slice(0, 200));

    // The bartender's colours.
    await go(a, /^village\.php/);
    await go(a, /^inn\.php/);
    await go(a, /^inn\.php\?op=bartender/);
    for (let i = 0; i < 8 && !(await hrefIn(a, 'nav', /act=colors/)); i++) {
      if (!(await hrefIn(a, 'nav', /act=bribe&type=gem&amt=3/))) await go(a, /act=bribe$|act=bribe&c=/);
      await go(a, /act=bribe&type=gem&amt=3/);
      if (!(await hrefIn(a, 'nav', /act=colors/))) await go(a, /^inn\.php\?op=bartender(&c=[^&]*)?$/);
    }
    await go(a, /act=colors/);
    if (await a.$("input[name='testtext']")) {
      await a.fill("input[name='testtext']", '`@green`$red');
      await submit(a, "form:has(input[name='testtext'])");
    }
    check('the colour test posts in place', await noReload(a) && (await a.$('[data-lotgd-region="content"] .colLtGreen')) !== null, (await text(a)).slice(0, 200));

    // The clan's message of the day.
    await go(a, /^village\.php/);
    await go(a, /^clan\.php/);
    await go(a, /^clan\.php\?op=motd/);
    check('the clan MOTD form in place', await noReload(a) && (await a.$("form[action='clan.php?op=motd']")) !== null, await a.title());
    await a.fill("form[action='clan.php?op=motd'] textarea[name='clanmotd']", 'Meet at dawn');
    await submit(a, "form[action='clan.php?op=motd']");
    check('the clan MOTD saved in place', await noReload(a) && sql('SELECT clanmotd FROM clans WHERE clanid=26') === 'Meet at dawn', sql('SELECT clanmotd FROM clans WHERE clanid=26'));

    // A form the server does not take in place is sent again, whole, as a page change.
    await go(a, /^village\.php/);
    // The client read the list at start; a fresh page does not, so reload the client's copy.
    await a.goto(BASE + 'badnav.php'); await settle(a);
    await a.route('**/*', async route => {
      const r = route.request();
      if (r.resourceType() !== 'document' && !r.headers()['hx-request']) return route.continue();
      const resp = await route.fetch();
      let body = await resp.text();
      body = body.replace(/(<meta name="lotgd-partial-forms" content=")([^"]*)(")/, (m, a1, json, a3) => {
        const forms = JSON.parse(json.replace(/&quot;/g, '"'));
        forms.push('village.php?op=resubmittest');
        return a1 + JSON.stringify(forms).replace(/"/g, '&quot;') + a3;
      });
      route.fulfill({ response: resp, body });
    });
    await a.reload(); await settle(a);
    await a.unroute('**/*');
    await a.evaluate(() => {
      const form = document.createElement('form');
      form.action = 'village.php?op=resubmittest';
      form.method = 'post';
      form.id = 'resubmit-probe';
      form.innerHTML = "<input name='typed' value='hello world'><button type='submit' name='go' value='yes'>Go</button>";
      document.querySelector('[data-lotgd-region="content"]').appendChild(form);
      window.htmx.process(form);
    });
    const mark = posts.length;
    await a.click('#resubmit-probe button');
    await a.waitForLoadState('load'); await settle(a, 1500);
    const sent = posts.slice(mark);
    check('the handed-back form is sent again as a page change, with what was typed',
      sent.length === 2 && sent[0].htmx && !sent[1].htmx && /typed=hello(\+|%20)world/.test(sent[1].body) && /go=yes/.test(sent[1].body),
      JSON.stringify(sent));

    // Game settings: one searchable table, saved on the same tab.
    await a.goto(BASE + 'configuration.php'); await settle(a);
    const cfgTabs = await a.$$('[data-showform-tab]');
    check('the game settings have tabs', cfgTabs.length > 5, String(cfgTabs.length));
    const cfgAll = await visible();
    await a.fill('input[type="search"]', 'pvp');
    await settle(a, 300);
    const cfgFound = await visible();
    const pvpRows = await a.$$eval('table[id^="showFormTable"] tbody tr', rows => rows.filter(r => r.style.display !== 'none').map(r => r.textContent.toLowerCase()).filter(t => t.includes('pvp')).length);
    check('searching "pvp" finds the PvP rows', cfgFound > 0 && cfgFound < cfgAll && pvpRows > 0, `${cfgAll} -> ${cfgFound} (${pvpRows} with pvp)`);
    await shot(a, `72-forms-${tag}-settings-search`);
    await a.fill('input[type="search"]', '');
    await cfgTabs[3].click();
    const cfgTab = await cfgTabs[3].textContent();
    await submit(a, "form[action='configuration.php?op=save']");
    const cfgActive = await a.$eval('[data-showform-tab].is-active', t => t.textContent).catch(() => '');
    check('saving the settings keeps the tab', cfgActive === cfgTab && (await a.textContent('body')).includes('Settings saved'), `${cfgActive} vs ${cfgTab}`);
    check('the tab is not stored as a setting', sql("SELECT COUNT(*) FROM settings WHERE setting LIKE 'showFormTab%'") === '0', sql("SELECT setting FROM settings WHERE setting LIKE 'showFormTab%'"));
    await shot(a, `73-forms-${tag}-settings-saved`);

    await browser.close();
  } finally {
    sql(`UPDATE settings SET value='${oldSkin}' WHERE setting='defaultskin'; UPDATE accounts SET clanid=0, clanrank=0, gold=250 WHERE acctid=1`);
  }
  const passed = results.filter(Boolean).length;
  console.log(`${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
