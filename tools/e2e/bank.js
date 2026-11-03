const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const results = [];
function check(name, ok, info = '') { results.push({ name, ok: !!ok }); console.log((ok ? 'PASS ' : 'FAIL ') + name + (info ? ' -- ' + info : '')); }
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const settle = (page, ms = 600) => page.waitForTimeout(ms);
const money = id => sql(`SELECT CONCAT(gold,'/',goldinbank,'/',version) FROM accounts WHERE acctid=${id}`);
const text = async page => (await page.textContent('body')).replace(/\s+/g, ' ');

async function login(page) {
  await page.goto(BASE + 'home.php');
  await page.fill('#name', 'Admin');
  await page.fill('#password', cfg.PASSWORD);
  await page.click("input[value='Log in']");
  await page.waitForLoadState('load');
  await settle(page);
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
    await page.waitForLoadState('load');
    await settle(page);
  }
}
async function nav(page, prefix) {
  const href = await page.$eval(`a[href^="${prefix}"]`, a => a.getAttribute('href')).catch(() => null);
  if (!href) throw new Error('no link ' + prefix + ' on ' + page.url());
  await page.click(`a[href="${href}"] >> nth=0`);
  await page.waitForLoadState('load');
  await settle(page);
}
// The form as the browser would send it again: its own action id and token.
const captureForm = page => page.$eval('form[action*="finish"], form[action*="transfer3"]', f => ({ action: f.getAttribute('action'), fields: [...new FormData(f).entries()] }));
async function resend(page, form) {
  await page.evaluate(({ action, fields }) => {
    const f = document.createElement('form');
    f.method = 'POST'; f.action = action;
    for (const [k, v] of fields) { const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); }
    document.body.appendChild(f); f.submit();
  }, form);
  await page.waitForLoadState('load');
  await settle(page);
}

(async () => {
  // Only this run's bookings count below.
  sql(`DELETE FROM action_log WHERE acctid=1`);
  sql(`INSERT INTO settings (setting,value) VALUES ('guarded_actions','1') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  const browser = await chromium.launch(cfg.launch);
  const page = await (await browser.newContext()).newPage();
  page.on('pageerror', e => console.log('pageerror:', e.message));
  await login(page);
  await reachVillage(page);
  sql(`UPDATE accounts SET gold=100, goldinbank=0, level=5, amountouttoday=0 WHERE acctid=1`);
  await nav(page, 'bank.php');
  check('bank reached', (await page.title()).includes('Bank'), await page.title());

  // 1. Deposit, then the same form again after the answer.
  await nav(page, 'bank.php?op=deposit');
  check('deposit form carries an action id', await page.$('form input[name="action_id"]') !== null);
  await page.fill('form input[name="amount"]', '30');
  const deposit = await captureForm(page);
  const v0 = money(1);
  await page.click('form input[type="submit"]');
  await page.waitForLoadState('load'); await settle(page);
  check('deposit booked', money(1).startsWith('70/30/'), money(1) + ' was ' + v0);
  check('deposit message', /records your deposit of 30/.test(await text(page)));
  const afterDeposit = money(1);
  await resend(page, deposit);
  check('resent deposit shows the first booking', /already written down/.test(await text(page)) && /records your deposit of 30/.test(await text(page)), (await text(page)).slice(0, 300));
  check('resent deposit books nothing', money(1) === afterDeposit, money(1));
  check('one log row', sql(`SELECT COUNT(*) FROM action_log WHERE acctid=1`) === '1');

  // 2. Back button, then submit the form shown from history.
  await nav(page, 'bank.php?op=withdraw');
  await page.fill('form input[name="amount"]', '5');
  await page.click('form input[type="submit"]');
  await page.waitForLoadState('load'); await settle(page);
  const afterWithdraw = money(1);
  check('withdraw booked', afterWithdraw.startsWith('75/25/'), afterWithdraw);
  await page.goBack(); await page.waitForLoadState('load'); await settle(page);
  const backForm = await page.$('form[action*="withdrawfinish"] input[type="submit"]');
  if (backForm) {
    await page.fill('form input[name="amount"]', '5').catch(() => {});
    await backForm.click(); await page.waitForLoadState('load'); await settle(page);
    check('back + submit books nothing', money(1) === afterWithdraw, money(1) + ' ' + (await text(page)).slice(0, 200));
  } else {
    check('back + submit books nothing (history page was not a form)', money(1) === afterWithdraw, page.url());
  }

  // 3. Changed elsewhere between showing the form and sending it: the form
  //    carries the version it was shown with, so nothing is booked.
  await nav(page, 'bank.php?op=deposit');
  await page.fill('form input[name="amount"]', '10');
  sql(`UPDATE accounts SET gold=gold+1, version=version+1 WHERE acctid=1`);
  const beforeConflict = money(1);
  await page.click('form input[type="submit"]');
  await page.waitForLoadState('load'); await settle(page);
  check('changed elsewhere: conflict message', /Your account has changed/.test(await text(page)), (await text(page)).slice(0, 300));
  check('changed elsewhere: nothing booked, the change survives', money(1) === beforeConflict, beforeConflict + ' -> ' + money(1));

  // 4. A transfer to a second player.
  sql(`DELETE FROM accounts WHERE login='bobe2e'`);
  const cols = sql(`SELECT GROUP_CONCAT(CONCAT('\`',column_name,'\`')) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='accounts' AND column_name NOT IN ('acctid','login','name','emailaddress','goldinbank','version','transferredtoday')`);
  sql(`INSERT INTO accounts (login,name,emailaddress,goldinbank,version,transferredtoday,${cols}) SELECT 'bobe2e','Bob','bob@example.invalid',0,0,0,${cols} FROM accounts WHERE acctid=1`);
  const bob = sql(`SELECT acctid FROM accounts WHERE login='bobe2e'`);
  await nav(page, 'bank.php?op=transfer');
  await page.fill('form input[name="amount"]', '10');
  await page.fill('form input[name="to"]', 'bobe2e');
  await page.click('form input[type="submit"]');
  await page.waitForLoadState('load'); await settle(page);
  check('transfer confirmation carries an action id', await page.$('form[action*="transfer3"] input[name="action_id"]') !== null);
  const transfer = await captureForm(page);
  const bobBefore = money(bob);
  await page.click('form[action*="transfer3"] input[type="submit"]');
  await page.waitForLoadState('load'); await settle(page);
  check('transfer completed', /The transfer has been completed/.test(await text(page)), (await text(page)).slice(0, 300));
  const bobAfter = money(bob);
  check('recipient credited and moved on', bobAfter.split('/')[1] === '10' && Number(bobAfter.split('/')[2]) === Number(bobBefore.split('/')[2]) + 1, bobBefore + ' -> ' + bobAfter);
  check('recipient mailed once', sql(`SELECT COUNT(*) FROM mail WHERE msgto=${bob}`) === '1');
  await resend(page, transfer);
  check('resent transfer books nothing', money(bob) === bobAfter && sql(`SELECT COUNT(*) FROM mail WHERE msgto=${bob}`) === '1', money(bob));

  // 5. Setting off: the bank as it was.
  sql(`UPDATE settings SET value='0' WHERE setting='guarded_actions'`);
  await nav(page, 'bank.php?op=deposit');
  check('setting off: no action id', await page.$('form input[name="action_id"]') === null);
  await page.fill('form input[name="amount"]', '1');
  const plain = await captureForm(page);
  const beforePlain = money(1);
  await page.click('form input[type="submit"]');
  await page.waitForLoadState('load'); await settle(page);
  const [pg, pb, pv] = beforePlain.split('/').map(Number);
  check('setting off: deposit booked, version moved on by the page end', money(1) === `${pg - 1}/${pb + 1}/${pv + 1}`, beforePlain + ' -> ' + money(1));
  const plainAfter = money(1);
  await resend(page, plain);
  check('setting off: resent form goes to badnav, books nothing', /badnav/.test(page.url()) || money(1) === plainAfter, page.url() + ' ' + money(1));
  check('setting off: resent form books nothing', money(1) === plainAfter, money(1));

  sql(`DELETE FROM accounts WHERE login='bobe2e'`);
  await browser.close();
  const failed = results.filter(r => !r.ok).length;
  console.log(`${results.length - failed}/${results.length} passed`);
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
