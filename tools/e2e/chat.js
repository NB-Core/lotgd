// The chat without reloads: posting, live lines for the other reader, [Del] live, the clan chat closed.
// MODE=sse|poll|ajax (ajax: no realtime, the AJAX preference only), SKIN.
const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const results = [];
function check(name, ok, info = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (info ? ' -- ' + info : '')); }
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const settle = (p, ms = 700) => p.waitForTimeout(ms);
const OUT = process.env.OUT || '.';
const MODE = process.env.MODE || 'sse';
const shot = async (p, name) => { await settle(p, 300); await p.screenshot({ path: `${OUT}/${name}.png`, fullPage: true }); console.log('shot', name); };
async function waitFor(fn, ms) {
  const until = Date.now() + ms;
  while (Date.now() < until) { if (await fn()) return true; await new Promise(r => setTimeout(r, 300)); }
  return false;
}
async function login(page, name) {
  await page.goto(BASE + 'home.php');
  await page.fill('#name', name);
  await page.fill('#password', cfg.PASSWORD);
  await page.click("input[value='Log in'], form[action*='login.php'] button[type='submit']");
  await page.waitForLoadState('load'); await settle(page);
  await page.goto(BASE + 'badnav.php'); await settle(page);
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
const section = p => p.getAttribute('[data-lotgd-commentary-form]', 'data-lotgd-commentary-form');
const lines = (p, text) => p.evaluate(t => [...document.querySelectorAll('[data-cid]')].filter(l => l.textContent.includes(t)).length, text);
async function say(p, text) {
  await p.fill('[data-lotgd-commentary-form] [name="insertcommentary"]', text);
  await p.press('[data-lotgd-commentary-form] [name="insertcommentary"]', 'Enter');
}

(async () => {
  const skin = cfg.skin('twig:aurora');
  const oldSkin = sql(`SELECT value FROM settings WHERE setting='defaultskin'`);
  const live = MODE === 'ajax' ? '0' : '1';
  sql(`INSERT INTO settings (setting,value) VALUES ('partial_render','1'),('realtime_enabled','${live}'),('realtime_transport','${MODE === 'sse' ? 'sse' : 'poll'}'),('realtime_poll_seconds','5'),('ajax','1'),('postinglimit','0'),('ajax_rate_limit_seconds','1') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`${cfg.setting('defaultskin', skin)}`);
  const prefs = MODE === 'ajax' ? 'a:2:{s:13:"partialrender";i:0;s:4:"ajax";i:1;}' : 'a:2:{s:13:"partialrender";i:1;s:4:"ajax";i:1;}';
  sql(`DELETE FROM accounts WHERE login='chate2e'`);
  const cols = cfg.accountColumns(sql, ['acctid','login','name','emailaddress','version','loggedin','allowednavs','restorepage','prefs','superuser','clanid','clanrank']);
  sql(`INSERT INTO accounts (login,name,emailaddress,version,loggedin,allowednavs,restorepage,prefs,superuser,clanid,clanrank,${cols}) SELECT 'chate2e','\`@Chatty Cat\`0','chat@example.invalid',0,0,'a:1:{s:11:"village.php";b:1;}','village.php','${prefs}',0,0,0,${cols} FROM accounts WHERE acctid=1`);
  sql(`UPDATE accounts SET prefs='${prefs}', alive=1, hitpoints=60, loggedin=0, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', clanid=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid=1`);
  const tag = 'e2e' + Date.now().toString(36);
  try {
    const browser = await chromium.launch(cfg.launch);
    const page = async who => { const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } }); const p = await ctx.newPage(); p.on('pageerror', e => console.log(who, 'pageerror:', e.message)); p.on('dialog', d => d.accept()); return p; };
    const a = await page('A'); const b = await page('B');
    await login(a, 'Admin'); await login(b, 'chate2e');
    const sec = await section(a);
    check('the talk form posts over the async client', sec !== null, String(sec));
    await a.evaluate(() => { window.__noReload = 1; });
    await b.evaluate(() => { window.__noReload = 1; });

    // A writes; A sees it at once, B without a reload.
    await say(a, `Hello ${tag}`);
    check('posting leaves the page in place', await waitFor(async () => (await lines(a, `Hello ${tag}`)) === 1, 3000) && await a.evaluate(() => window.__noReload === 1));
    check('the field is empty again', (await a.inputValue('[data-lotgd-commentary-form] [name="insertcommentary"]')) === '');
    const wait = MODE === 'sse' ? 6000 : 15000;
    check('the other reader sees it without a reload', await waitFor(async () => (await lines(b, `Hello ${tag}`)) === 1, wait) && await b.evaluate(() => window.__noReload === 1), MODE);
    await shot(a, `50-chat-${MODE}-${skin.replace(/[:.]/g, '-')}-gepostet`);

    // Three in a row, each once.
    for (let i = 1; i <= 3; i++) {
      await say(a, `Line ${i} ${tag}`);
      await settle(a, 1300);
    }
    const counts = [];
    for (let i = 1; i <= 3; i++) counts.push(await lines(a, `Line ${i} ${tag}`));
    check('three lines in a row, each once', counts.every(c => c === 1), counts.join(','));
    check('and once each for the other reader', await waitFor(async () => {
      for (let i = 1; i <= 3; i++) if ((await lines(b, `Line ${i} ${tag}`)) !== 1) return false;
      return true;
    }, wait));

    // Colours and /me.
    await settle(a, 1200);
    await say(a, `/me waves \`4red ${tag}`);
    check('/me and a colour code', await waitFor(async () => a.evaluate(t => [...document.querySelectorAll('[data-cid]')].some(l => l.textContent.includes('waves') && l.textContent.includes(t) && l.querySelector('.colDkRed')), tag), 3000));

    // A double post.
    await settle(a, 1200);
    await say(a, `/me waves \`4red ${tag}`);
    check('a double post is said, not posted', await waitFor(async () => /Double post/.test(await a.textContent('[data-lotgd-commentary-status]')), 3000) && (await a.evaluate(t => [...document.querySelectorAll('[data-cid]')].filter(l => l.textContent.includes('waves') && l.textContent.includes(t)).length, tag)) === 1);

    // A's own new line, fetched over the endpoint, carries [Del] for a moderator.
    const ownLine = await a.evaluate(t => { const l = [...document.querySelectorAll('[data-cid]')].find(x => x.textContent.includes(t)); return l ? l.getAttribute('data-cid') : null; }, `Hello ${tag}`);
    check('a posted line carries [Del] for the moderator', ownLine !== null && (await a.$(`form[data-lotgd-comment-remove="${ownLine}"]`)) !== null, String(ownLine));

    // B writes; A moderates it away with [Del], live for both.
    await say(b, `Rude ${tag}`);
    check('B posts too', await waitFor(async () => (await lines(b, `Rude ${tag}`)) === 1, 3000));
    // A's [Del] buttons are on the lines a page drew: load the village once more.
    const villageHref = await a.evaluate(() => { const l = [...document.querySelectorAll('a')].find(x => /^village\.php/.test(x.getAttribute('href') || '') && !/comscroll|removecomment/.test(x.getAttribute('href'))); return l ? l.getAttribute('href') : null; });
    await a.click(`a[href="${villageHref}"] >> nth=0`); await a.waitForLoadState('load'); await settle(a, 1000);
    await a.evaluate(() => { window.__noReload = 1; });
    const rude = await a.evaluate(t => { const l = [...document.querySelectorAll('[data-cid]')].find(x => x.textContent.includes(t)); return l ? l.getAttribute('data-cid') : null; }, `Rude ${tag}`);
    require('fs').writeFileSync(OUT + '/a-comments.html', await a.evaluate(() => { const e = document.querySelector('[id$="-comment"]'); return e ? e.innerHTML : 'none'; }));
    const del = rude ? await a.$(`form[data-lotgd-comment-remove="${rude}"] button`) : null;
    check('the moderator has [Del] on the line', del !== null, String(rude) + ' ' + (await a.title()) + ' cids=' + (await a.evaluate(() => document.querySelectorAll('[data-cid]').length)) + ' dels=' + (await a.evaluate(() => document.querySelectorAll('[data-lotgd-comment-remove]').length)) + ' ' + sql(`SELECT commentid,section,LEFT(comment,30) FROM commentary ORDER BY commentid DESC LIMIT 3`).replace(/\n/g, ' | '));
    if (del) {
      await del.click();
      check('[Del] removes the line in place', await waitFor(async () => (await lines(a, `Rude ${tag}`)) === 0, 3000) && await a.evaluate(() => window.__noReload === 1));
      check('the line is gone from the database', sql(`SELECT COUNT(*) FROM commentary WHERE commentid=${Number(rude)}`) === '0');
      if (MODE !== 'ajax') {
        check('and gone for the other reader, live', await waitFor(async () => (await lines(b, `Rude ${tag}`)) === 0, wait));
      } else {
        console.log('(ajax only: no realtime, B sees the removal on the next page)');
      }
    }

    // A clan's chat stays closed to someone outside it, whatever the request names.
    const clan = sql(`SELECT clanid FROM clans ORDER BY clanid LIMIT 1`) || '1';
    const before = sql(`SELECT COUNT(*) FROM commentary WHERE section='clan-${clan}'`);
    const answer = await b.evaluate(c => window.lotgd.async.call('Commentary', 'post', ['clan-' + c, 'sneaky', 0]).then(d => d.commentPost && d.commentPost.status).catch(e => 'error ' + e.status), clan);
    check('a clan chat takes no line from outside', answer === 'refused' && sql(`SELECT COUNT(*) FROM commentary WHERE section='clan-${clan}'`) === before, String(answer));

    await shot(b, `51-chat-${MODE}-${skin.replace(/[:.]/g, '-')}-leser`);

    // A classic player (neither realtime nor AJAX) keeps the form that posts.
    sql(`UPDATE accounts SET prefs='a:2:{s:13:"partialrender";i:0;s:4:"ajax";i:0;}' WHERE login='chate2e'`);
    const bv = await b.evaluate(() => { const l = [...document.querySelectorAll('a')].find(x => /^village\.php/.test(x.getAttribute('href') || '') && !/comscroll|removecomment/.test(x.getAttribute('href'))); return l ? l.getAttribute('href') : null; });
    await b.click(`a[href="${bv}"] >> nth=0`); await b.waitForLoadState('load'); await settle(b, 1000);
    check('a classic player keeps the classic form', (await b.$('[data-lotgd-commentary-form]')) === null && (await b.$('[name="insertcommentary"]')) !== null);
    await b.fill('[name="insertcommentary"]', `Classic ${tag}`);
    await Promise.all([b.waitForNavigation(), b.press('[name="insertcommentary"]', 'Enter')]);
    await settle(b);
    check('and its post loads the page as before', (await b.content()).includes(`Classic ${tag}`));

    await browser.close();
  } finally {
    sql(`${cfg.setting('defaultskin', oldSkin)}; DELETE FROM accounts WHERE login='chate2e'; INSERT INTO settings (setting,value) VALUES ('postinglimit','1') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  }
  const passed = results.filter(Boolean).length;
  console.log(`${passed}/${results.length} passed`);
  process.exit(passed === results.length ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
