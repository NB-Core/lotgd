-- Once, on a fresh test game installed with `php bin/install --admin=Admin`:
-- the second and third player the suites log in as, copies of Admin with the
-- same password, at the ids the suites use. Run it with LOTGD_E2E_DB_CMD.
CREATE TEMPORARY TABLE e2e_accounts SELECT * FROM accounts WHERE acctid = 1;
UPDATE e2e_accounts SET acctid = 385, login = 'offe2e', name = 'Otto', playername = 'Otto', superuser = 0, emailaddress = 'offe2e@example.invalid';
INSERT IGNORE INTO accounts SELECT * FROM e2e_accounts;
UPDATE e2e_accounts SET acctid = 386, login = 'appe2e', name = 'Nina', playername = 'Nina', emailaddress = 'appe2e@example.invalid';
INSERT IGNORE INTO accounts SELECT * FROM e2e_accounts;
DROP TEMPORARY TABLE e2e_accounts;
UPDATE accounts SET level = 5, experience = 1000, alive = 1, location = 'Degolburg', restorepage = 'village.php' WHERE acctid IN (1, 385, 386);
