const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const results = [];
function check(name, ok, info = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + name + (info ? ' -- ' + info : '')); }
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const settle = (p, ms = 700) => p.waitForTimeout(ms);
async function login(page, name) {
  await page.goto(BASE + 'home.php');
  await page.fill('#name', name);
  await page.fill('#password', cfg.PASSWORD);
  await page.click("input[value='Log in']");
  await page.waitForLoadState('load'); await settle(page);
  await page.goto(BASE + 'badnav.php'); await settle(page);
  for (let i = 0; i < 25 && !(await page.title()).includes('Square'); i++) {
    const href = await page.evaluate(() => {
      const links = [...document.querySelectorAll('a')].filter(a => !a.hasAttribute('onclick'));
      const pick = links.find(a => /^village\.php(\?c=|$)/.test(a.getAttribute('href') || '')) || links.find(a => /^newday\.php/.test(a.getAttribute('href') || ''));
      return pick ? pick.getAttribute('href') : null;
    });
    if (!href) break;
    await page.click(`a[href="${href}"] >> nth=0`); await page.waitForLoadState('load'); await settle(page);
  }
}
const dialogText = async p => (await p.evaluate(() => { const d = document.querySelector('dialog.lotgd-overlay'); return d && d.open ? d.innerText.replace(/\s+/g, ' ') : null; }));
const isOpen = async p => (await dialogText(p)) !== null;

function reset(extra = '') {
  sql(`INSERT INTO settings (setting,value) VALUES ('ajax','1'),('partial_render','0'),('realtime_enabled','0'),('forcedmotdpopup','0') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  sql(`UPDATE accounts SET prefs='a:1:{s:4:"ajax";i:1;}', lastmotd=NOW(), restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', loggedin=0, clanid=0, clanrank=0 WHERE acctid=1; DELETE FROM accounts_output WHERE acctid=1; DELETE FROM mail WHERE msgto=1 OR msgfrom=1; ${extra}`);
}

(async () => {
  const browser = await chromium.launch(cfg.launch);

  // --- Aurora: mail in the dialog
  reset(`INSERT INTO mail (msgfrom, msgto, subject, body, sent, seen) VALUES (1, 1, 'Overlay test subject', 'Overlay test body', NOW(), 0)`);
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const errors = []; page.on('pageerror', e => errors.push(e.message)); page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  let popups = 0; ctx.on('page', () => { popups++; });
  await login(page, 'Admin');
  const villageUrl = page.url();
  check('the mail link is an overlay link', await page.$('[data-lotgd-slot="mail"] a[data-lotgd-overlay]') !== null);
  await page.click('[data-lotgd-slot="mail"] a[data-lotgd-overlay]');
  await page.waitForFunction(() => { const d = document.querySelector('dialog.lotgd-overlay'); return d && d.open && d.querySelector('.lotgd-overlay__body').innerHTML.length > 0; }, null, { timeout: 10000 });
  const inbox = await dialogText(page);
  check('the inbox opens in a dialog, not a window', inbox !== null && /Overlay test subject/.test(inbox) && popups === 0, (inbox || '').slice(0, 200));
  check('the dialog has the popup title', /Poste/.test(await page.textContent('#lotgd-overlay-title')), await page.textContent('#lotgd-overlay-title'));
  check('the inbox carries no script', await page.evaluate(() => document.querySelector('.lotgd-overlay__body script') === null));

  await page.click('dialog.lotgd-overlay a:has-text("Overlay test subject")'); await settle(page, 1200);
  check('reading the mail stays in the dialog', /Overlay test body/.test(await dialogText(page) || '') && page.url() === villageUrl);

  await page.click('dialog.lotgd-overlay a[href*="op=write"]'); await settle(page, 1200);
  const write = await dialogText(page) || '';
  check('reply opens the write form in the dialog', await page.$('dialog.lotgd-overlay textarea') !== null, write.slice(0, 160));
  await page.fill('dialog.lotgd-overlay textarea', 'Reply from the overlay');
  await page.type('dialog.lotgd-overlay textarea', '!');
  check('the character counter follows the text', /\d/.test(await page.textContent('dialog.lotgd-overlay #sizemsg') || ''), await page.textContent('dialog.lotgd-overlay #sizemsg'));
  const rowsBefore = await page.$eval('dialog.lotgd-overlay textarea', t => t.rows);
  const grow = await page.$('dialog.lotgd-overlay [data-lotgd-resize^="rows:+"]');
  if (grow) { await grow.click(); }
  check('the resize buttons work', grow !== null && (await page.$eval('dialog.lotgd-overlay textarea', t => t.rows)) > rowsBefore);
  const sendClose = await page.$('dialog.lotgd-overlay [name="sendclose"]');
  await (sendClose || await page.$('dialog.lotgd-overlay input[type=submit]')).click(); await settle(page, 1500);
  check('send and close closes the dialog', !(await isOpen(page)));
  check('the reply was sent', sql(`SELECT COUNT(*) FROM mail WHERE msgto=1 AND body LIKE '%Reply from the overlay!%'`) === '1');
  await settle(page, 1500);
  check('the header mail link updated after closing', /new/.test(await page.textContent('[data-lotgd-slot="mail"]')), await page.textContent('[data-lotgd-slot="mail"]'));

  await page.click('[data-lotgd-slot="mail"] a[data-lotgd-overlay]'); await settle(page, 1200);
  const checkAll = await page.$('dialog.lotgd-overlay [data-lotgd-check-all]');
  check('check all is declarative', checkAll !== null);
  if (checkAll) { await checkAll.click(); }
  const ticked = await page.evaluate(() => [...document.querySelectorAll('dialog.lotgd-overlay input[type=checkbox]')].every(b => b.checked));
  check('check all ticks every message', ticked);
  page.once('dialog', d => d.accept());
  await page.click('dialog.lotgd-overlay input[type=submit]'); await settle(page, 1500);
  check('deleting follows the redirect inside the dialog', await isOpen(page) && sql(`SELECT COUNT(*) FROM mail WHERE msgto=1`) === '0', (await dialogText(page) || '').slice(0, 120));
  await page.keyboard.press('Escape'); await settle(page);
  check('Escape closes the dialog', !(await isOpen(page)));
  const nav = await page.$eval('[data-lotgd-region="nav"] a[href^="forest.php"]', a => a.getAttribute('href'));
  await page.click(`a[href="${nav}"] >> nth=0`); await page.waitForLoadState('load'); await settle(page);
  check('the page behind kept its links', /Forest/.test(await page.title()) && !page.url().includes('badnav'), page.url());
  check('no window was opened', popups === 0, String(popups));
  check('no page errors', errors.length === 0, errors.join(' | '));
  await ctx.close();

  // --- Aurora: forced MOTD opens by itself
  reset(`UPDATE accounts SET lastmotd='2000-01-01 00:00:00' WHERE acctid=1; INSERT INTO motd (motdtitle, motdbody, motddate, motdtype, motdauthor) VALUES ('Overlay MOTD', 'Hello from the MOTD', NOW(), 0, 1)`);
  sql(`UPDATE settings SET value='1' WHERE setting='forcedmotdpopup'`);
  const ctx2 = await browser.newContext();
  const p2 = await ctx2.newPage();
  let popups2 = 0; ctx2.on('page', () => { popups2++; });
  await p2.goto(BASE + 'home.php'); await p2.fill('#name', 'Admin'); await p2.fill('#password', cfg.PASSWORD);
  await p2.click("input[value='Log in']"); await p2.waitForLoadState('load');
  const opened = await p2.waitForFunction(() => { const d = document.querySelector('dialog.lotgd-overlay'); return d && d.open && /Hello from the MOTD/.test(d.innerText); }, null, { timeout: 10000 }).then(() => true, () => false);
  check('a new MOTD opens in the dialog by itself', opened && popups2 === 0);
  await p2.click('dialog.lotgd-overlay .lotgd-overlay__close'); await settle(p2);
  await p2.goto(BASE + 'badnav.php'); await settle(p2, 1500);
  check('seen once, it does not open again', !(await isOpen(p2)));
  await ctx2.close();
  sql(`UPDATE settings SET value='0' WHERE setting='forcedmotdpopup'; DELETE FROM motd WHERE motdtitle='Overlay MOTD'`);

  // --- Legacy skin (jade): the dialog too, since #1613
  reset(`INSERT INTO mail (msgfrom, msgto, subject, body, sent, seen) VALUES (1, 1, 'Legacy overlay subject', 'Legacy overlay body', NOW(), 0)`);
  const ctx3 = await browser.newContext();
  await ctx3.addCookies([{ name: 'template', value: 'legacy:jade.htm', url: BASE }]);
  const p3 = await ctx3.newPage();
  let popups3 = 0; ctx3.on('page', () => { popups3++; });
  await login(p3, 'Admin');
  const marked3 = await p3.$('a[href="mail.php"][data-lotgd-overlay]');
  check('jade marks the mail link for the dialog', marked3 !== null);
  if (marked3) {
    await marked3.click(); await settle(p3, 1200);
    const text3 = await dialogText(p3);
    check('jade opens mail in the dialog, not a window', text3 !== null && /Legacy overlay subject/.test(text3) && popups3 === 0, (text3 || '').slice(0, 160));
    check('the jade dialog has the popup title', ((await p3.textContent('#lotgd-overlay-title')) || '').trim() !== '');
    await p3.click('dialog.lotgd-overlay .lotgd-overlay__close'); await settle(p3);
    check('the jade dialog closes', !(await isOpen(p3)));
  }
  await ctx3.close();

  // --- A skin without the overlay key: the window as before
  const fs = require('fs');
  const GAME = cfg.GAME + '/templates/';
  const jade = fs.readFileSync(GAME + 'jade.htm', 'utf8');
  const noOverlay = jade.replace('{"regions": true, "overlay": true}', '{"regions": true}');
  const tmpSkin = 'zz-no-overlay.htm';
  fs.writeFileSync(GAME + tmpSkin, noOverlay);
  try {
    check('the test skin really drops the key', noOverlay !== jade);
    reset();
    const ctx4 = await browser.newContext();
    await ctx4.addCookies([{ name: 'template', value: 'legacy:' + tmpSkin, url: BASE }]);
    const p4 = await ctx4.newPage();
    await login(p4, 'Admin');
    const marked4 = await p4.$('a[href="mail.php"][data-lotgd-overlay]');
    const link4 = await p4.$('a[href="mail.php"]');
    check('a skin without overlay keeps plain popup links', marked4 === null && link4 !== null);
    if (link4) {
      const [popup] = await Promise.all([ctx4.waitForEvent('page', { timeout: 5000 }).catch(() => null), link4.click()]);
      check('and opens mail in a window', popup !== null);
    }
    await ctx4.close();
  } finally {
    fs.unlinkSync(GAME + tmpSkin);
  }

  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
  await browser.close();
  process.exit(results.every(Boolean) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
