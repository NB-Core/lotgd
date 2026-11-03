// Where the browser suites find the game, its database and the browser.
// Every value can be set from the environment; see README.md.
'use strict';
const fs = require('fs');
const path = require('path');

const REPO = path.resolve(__dirname, '../..');
const chromium = process.env.LOTGD_E2E_CHROMIUM || (fs.existsSync('/opt/pw-browsers/chromium') ? '/opt/pw-browsers/chromium' : '');

// Set async settings for one suite. The game reads config/async.settings.php.dist
// and the optional override config/async.settings.php on top: start from the
// override if there is one, else from the defaults, and put back afterwards
// what was there (the old override, or none).
function overrideAsyncSettings(game, values) {
  const file = game + '/config/async.settings.php';
  const existed = fs.existsSync(file);
  const saved = fs.readFileSync(existed ? file : file + '.dist', 'utf8');
  let text = saved;
  for (const [key, value] of Object.entries(values)) {
    const re = new RegExp(`'${key}'\\s*=>\\s*\\d+`);
    if (!re.test(text)) throw new Error(`no ${key} in ${existed ? file : file + '.dist'}`);
    text = text.replace(re, `'${key}' => ${value}`);
  }
  fs.writeFileSync(file, text);
  return () => (existed ? fs.writeFileSync(file, saved) : fs.unlinkSync(file));
}

// Empty the game's data cache without a shell, so any path works.
function clearDataCache(game) {
  const dir = path.join(game, 'data_cache');
  if (!fs.existsSync(dir)) return;
  for (const name of fs.readdirSync(dir)) fs.rmSync(path.join(dir, name), { recursive: true, force: true });
}

// The skin a suite runs on (SKIN, set by run.sh), or the fallback. Only the
// forms the game knows pass: suites put it into SQL and into URLs.
function skin(fallback) {
  const value = process.env.SKIN || fallback;
  if (!/^(twig:[A-Za-z0-9_-]+|(legacy:)?[A-Za-z0-9_-]+\.htm)$/.test(value)) {
    throw new Error(`SKIN must be twig:<name>, legacy:<name>.htm or <name>.htm, not ${JSON.stringify(value)}`);
  }
  return value;
}

// The realtime transport a suite runs with (TRANSPORT, set by run.sh).
function transport(fallback) {
  const value = process.env.TRANSPORT || fallback;
  if (value !== 'poll' && value !== 'sse') {
    throw new Error(`TRANSPORT must be poll or sse, not ${JSON.stringify(value)}`);
  }
  return value;
}

// A string as an SQL literal that means the same whatever the SQL mode
// (backslashes, quotes, NO_BACKSLASH_ESCAPES): its UTF-8 bytes in hex.
function sqlString(value) {
  return `CONVERT(X'${Buffer.from(String(value), 'utf8').toString('hex')}' USING utf8mb4)`;
}

// SQL that sets one game setting, creating it if the row is missing (a plain
// UPDATE would then do nothing).
function setting(name, value) {
  return `INSERT INTO settings (setting, value) VALUES (${sqlString(name)}, ${sqlString(value)}) ON DUPLICATE KEY UPDATE value = VALUES(value)`;
}

// The accounts columns except the named ones, as a backquoted list for an
// INSERT ... SELECT copy of an account. One row per column: GROUP_CONCAT
// would cut a long list at group_concat_max_len (1024 on MySQL).
function accountColumns(sql, exclude) {
  const names = sql(`SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'accounts' ORDER BY ordinal_position`)
    .split('\n').map(n => n.trim()).filter(n => n !== '' && !exclude.includes(n));
  return names.map(n => `\`${n.replace(/`/g, '``')}\``).join(',');
}

// Remember the named game settings; the returned function puts them back
// (and removes those that were not set). sql(query) runs a query and
// returns its output.
function keepSettings(sql, names) {
  const list = names.map(n => `'${n}'`).join(',');
  const rows = sql(`SELECT setting, value FROM settings WHERE setting IN (${list})`);
  const saved = new Map(rows === '' ? [] : rows.split('\n').map(line => line.split('\t')));
  return () => sql(names.map(n => saved.has(n)
    ? `${setting(n, saved.get(n))};`
    : `DELETE FROM settings WHERE setting='${n}';`).join(' '));
}

module.exports = {
  // The running game, with a trailing slash.
  BASE: process.env.LOTGD_E2E_BASE || 'http://127.0.0.1:8099/',
  // The game's directory: some suites rewrite config/async.settings.php or empty its data cache.
  GAME: process.env.LOTGD_E2E_GAME || process.env.GAME || REPO,
  // A shell command that runs the SQL on its standard input against the game's database, one value per line (-N).
  DB_CMD: process.env.LOTGD_E2E_DB_CMD || 'docker exec -i lotgd-e2e-db mariadb -N -ulotgd -plotgdpw lotgd',
  // The password of the test accounts (Admin, offe2e, appe2e).
  PASSWORD: process.env.LOTGD_E2E_PASSWORD || 'AdminPass123!',
  // This checkout (realtime_worker.php comes from here).
  REPO,
  // Playwright's launch options.
  launch: chromium !== '' ? { executablePath: chromium } : {},
  overrideAsyncSettings,
  clearDataCache,
  keepSettings,
  sqlString,
  transport,
  setting,
  accountColumns,
  skin,
  // Where the screenshots go: run.sh sets OUT, alone a suite writes here.
  OUT: process.env.OUT || '.',
};
