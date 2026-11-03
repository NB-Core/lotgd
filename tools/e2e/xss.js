// Injection probe: player-controlled text through the live paths.
const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const P = `"'><img src=x onerror="window.__xss=1"><svg onload="window.__xss=2"><a hx-get="/xss-probe" hx-trigger="load">x</a>`;
const esc = s => s.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
const results = [];
function check(n, ok, i = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + n + (i ? ' -- ' + i : '')); }
async function login(page, name) {
  await page.goto(BASE + 'home.php'); await page.fill('#name', name); await page.fill('#password', cfg.PASSWORD);
  await page.click("input[value='Log in'], form[action*='login.php'] button[type='submit']");
  await page.waitForLoadState('load'); await page.goto(BASE + 'badnav.php'); await page.waitForLoadState('load');
}
(async () => {
  const oldName = sql(`SELECT name FROM accounts WHERE acctid=385`);
  sql(`UPDATE settings SET value='twig:aurora' WHERE setting='defaultskin'; UPDATE accounts SET name='${esc("`&O'><img src=x onerror=__xss=3>")}' WHERE acctid=385; UPDATE settings SET value='1' WHERE setting IN ('partial_render','realtime_enabled','live_encounters','grants_enabled','guarded_actions');
       UPDATE accounts SET prefs='a:2:{s:13:"partialrender";i:1;s:4:"ajax";i:1;}', alive=1, location='Degolburg', loggedin=0, restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}' WHERE acctid IN (1,385);

       INSERT INTO mail (msgfrom, msgto, subject, body, sent, seen) VALUES (385, 1, '${esc('Hi' + P)}', '${esc('Body' + P)}', NOW(), 0);`);
  const b = await chromium.launch(cfg.launch);
  const probes = [];
  try {
    const a = await (await b.newContext()).newPage(); const o = await (await b.newContext()).newPage();
    for (const p of [a, o]) {
      p.on('request', r => { if (r.url().includes('xss-probe')) probes.push(r.url()); });
      p.on('dialog', d => d.dismiss());
    }
    await login(a, 'Admin'); await login(o, 'offe2e');
    const bad = async p => p.evaluate(() => ({ xss: window.__xss || 0, imgs: document.querySelectorAll('img[src="x"]').length, svgs: document.querySelectorAll('svg[onload]').length, hx: document.querySelectorAll('[hx-trigger="load"]').length }));
    // 1. A chat line, posted over the async client, seen live by the other.
    await o.fill('[name="insertcommentary"]', 'Line' + P); await o.press('[name="insertcommentary"]', 'Enter');
    await a.waitForTimeout(6000);
    for (const [n, p] of [['poster', o], ['reader', a]]) { const r = await bad(p); check(`chat line stays text (${n})`, !r.xss && !r.imgs && !r.svgs && !r.hx, JSON.stringify(r)); }
    check('the line shows as text', (await a.innerText('body')).includes('onerror='));
    // 2. Mail in the overlay: inbox, then the message.
    await a.click('[data-lotgd-slot="mail"] a'); await a.waitForSelector('.lotgd-overlay[open]'); await a.waitForTimeout(800);
    let r = await bad(a); check('inbox subject stays text', !r.xss && !r.imgs && !r.hx, JSON.stringify(r));
    const link = await a.$('.lotgd-overlay__body a[href*="op=read"]'); if (link) { await link.click(); await a.waitForTimeout(1200); }
    r = await bad(a); check('mail body stays text', link && !r.xss && !r.imgs && !r.hx && (await a.innerText('.lotgd-overlay__body')).includes('onerror='), JSON.stringify(r));
    await a.keyboard.press('Escape');
    // 3. The name in the duelling ground's presence list and a challenge to it.
    await a.goto(BASE + 'encounter.php'); await a.waitForTimeout(800);
    r = await bad(a); check('a name in the presence list stays text', !r.xss && !r.imgs && !r.hx, JSON.stringify(r));
    await o.goto(BASE + 'encounter.php'); await o.waitForTimeout(500);
    const ch = await o.$('form[action*="op=challenge"] button[name="target"][value="1"]');
    if (ch) { await ch.click(); await o.waitForTimeout(500); }
    await a.waitForTimeout(7000);
    r = await bad(a); check('a challenge by that name stays text (grant, header, region)', !r.xss && !r.imgs && !r.hx, JSON.stringify(r) + ' challenged=' + !!ch);
    // 4. Full pages: the mail window (no dialog), the player list, the bio.
    await a.goto(BASE + 'mail.php'); await a.waitForTimeout(600);
    r = await bad(a); check('mail.php keeps the name as text', !r.xss && !r.imgs && !r.hx, JSON.stringify(r));
    await a.goto(BASE + 'badnav.php'); await a.waitForTimeout(400);
    await a.click('a[href^="list.php"] >> nth=0'); await a.waitForTimeout(1200);
    r = await bad(a); check('list.php keeps the name as text', /list\.php/.test(a.url()) || (await a.title()).length, JSON.stringify(r) + ' ' + a.url());
    check('the list has no injected markup', !r.xss && !r.imgs && !r.hx, JSON.stringify(r));
    const bio = await a.$('a[href*="bio.php?char=385"]');
    if (bio) { await bio.click(); await a.waitForTimeout(1200); }
    r = await bad(a); check('the bio keeps the name as text', bio && /bio/i.test(await a.innerText('body')) && !r.xss && !r.imgs && !r.hx, JSON.stringify(r) + ' bio-link=' + !!bio);
    check('no request was triggered by injected htmx attributes', probes.length === 0, probes.join(','));
  } finally {
    await b.close();
    sql(`UPDATE accounts SET name='${esc(oldName)}' WHERE acctid=385; DELETE FROM mail WHERE msgfrom=385 AND msgto=1 AND subject LIKE 'Hi%'; DELETE FROM commentary WHERE comment LIKE 'Line%onerror%'; UPDATE encounters SET state='cancelled' WHERE state='pending'; DELETE FROM encounter_slots;`);
  }
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
})().catch(e => { console.error(e); process.exit(2); });
