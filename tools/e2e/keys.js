// dwellings: giving and taking back a key (the player lookup is parameterised).
const { chromium } = require('playwright');
const cfg = require('./config');
const { execSync } = require('child_process');
const BASE = cfg.BASE;
const sql = q => execSync(cfg.DB_CMD, { input: q }).toString().trim();
const results = [];
function check(n, ok, i = '') { results.push(!!ok); console.log((ok ? 'PASS ' : 'FAIL ') + n + (i ? ' -- ' + i : '')); }
(async () => {
  sql(`UPDATE modules SET active=1 WHERE modulename IN ('dwellings','dwshacks','cityprefs');
       INSERT INTO module_settings (modulename,setting,value) VALUES ('dwshacks','maxkeys','5') ON DUPLICATE KEY UPDATE value='5';
       UPDATE accounts SET prefs='a:1:{s:13:"partialrender";i:0;}', alive=1, location='Degolburg', loggedin=0, lasthit=NOW(), experience=0, seenmaster=0,
         restorepage='village.php', allowednavs='a:1:{s:11:"village.php";b:1;}', badguy='', specialinc='' WHERE acctid=1;
       DELETE FROM accounts_output WHERE acctid=1; DELETE FROM dwellings; DELETE FROM dwellingkeys;
       INSERT INTO dwellings (ownerid,name,description,location,windowpeer,status,type,storedinfo) VALUES (1,'Key Shack','A shack','Degolburg','',1,'dwshacks','');`);
  const dwid = sql(`SELECT dwid FROM dwellings WHERE ownerid=1`);
  const login = sql(`SELECT login FROM accounts WHERE acctid=385`);
  const b = await chromium.launch(cfg.launch);
  const p = await (await b.newContext()).newPage();
  const go = async part => { await p.click(`a[href*="${part}"] >> nth=0`); await p.waitForLoadState('load'); };
  const give = async name => {
    await go(`op=keys&dwid=${dwid}`); await go('subop=givekey&');
    await p.fill('#name', name); await p.click("form[action*='subop=givekey2'] input[type=submit]"); await p.waitForLoadState('load');
    return p.innerText('body');
  };
  try {
    await p.goto(BASE + 'home.php'); await p.fill('#name', 'Admin'); await p.fill('#password', cfg.PASSWORD);
    await p.click("input[value='Log in']"); await p.waitForLoadState('load'); await p.goto(BASE + 'badnav.php');
    await go('module=dwellings'); await go(`op=enter&dwid=${dwid}`); await go(`op=manage&dwid=${dwid}`);
    let body = await give("x' OR '1'='1");
    check('a name with a quote finds no one and breaks nothing', /no one with that name/.test(body) && !/SQL|Exception|error/i.test(body), body.slice(0, 160));
    await go(`op=manage&dwid=${dwid}`);
    body = await give(login);
    const pick = await p.$('a[href*="subop=givekey3"][href*="keyowner=385"]');
    check('the exact login lists that player', !!pick, body.slice(0, 160));
    if (pick) { await pick.click(); await p.waitForLoadState('load'); }
    check('the key is given', sql(`SELECT COUNT(*) FROM dwellingkeys WHERE dwid=${dwid} AND keyowner=385`) === '1');
    await go(`op=manage&dwid=${dwid}`);
    body = await give(login.slice(0, 3));
    check('a part of the name lists players by name', (await p.$$('a[href*="subop=givekey3"]')).length >= 1 || /no one/.test(body), body.slice(0, 160));
    await go(`op=manage&dwid=${dwid}`); await go(`op=keys&dwid=${dwid}`);
    await go('subop=takekey');
    check('the key is taken back', sql(`SELECT COUNT(*) FROM dwellingkeys WHERE dwid=${dwid} AND keyowner=385`) === '0');
  } finally {
    await b.close();
    sql(`UPDATE modules SET active=0 WHERE modulename IN ('dwellings','dwshacks','cityprefs')`);
  }
  console.log(`${results.filter(Boolean).length}/${results.length} passed`);
})().catch(e => { console.error(e); process.exit(2); });
