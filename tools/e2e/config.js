// Where the browser suites find the game, its database and the browser.
// Every value can be set from the environment; see README.md.
'use strict';
const fs = require('fs');
const path = require('path');

const REPO = path.resolve(__dirname, '../..');
const chromium = process.env.LOTGD_E2E_CHROMIUM || (fs.existsSync('/opt/pw-browsers/chromium') ? '/opt/pw-browsers/chromium' : '');

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
};
