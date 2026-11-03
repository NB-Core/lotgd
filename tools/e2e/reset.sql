-- Before every suite (run.sh): what one suite may leave behind for the next.
-- Each suite still sets up the rest of what it needs.
INSERT IGNORE INTO clans (clanid, clanname, clanshort, clanmotd, clandesc, motdauthor, descauthor, customsay)
    VALUES (26, 'Formtest Hall', 'FT', '', '', 1, 1, '');
DELETE FROM accounts_output WHERE acctid IN (1, 385, 386);
-- The reactive tables only where the migrations have created them.
SET @q = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'action_log') > 0,
    'DELETE FROM action_log WHERE acctid IN (1, 385, 386)', 'DO 0');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @q = IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'realtime_streams') > 0,
    'DELETE FROM realtime_streams', 'DO 0');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- Experience back below the next level: with too much, the game forces the
-- master's challenge and every other link is gone (fights.js sets 100000).
-- Location back to the capital: a suite that travels leaves it elsewhere.
UPDATE accounts SET loggedin = 0, transferredtoday = 0, amountouttoday = 0, experience = 1000, seenmaster = 0,
    location = COALESCE((SELECT value FROM settings WHERE setting = 'villagename'), 'Degolburg'),
    restorepage = 'village.php', allowednavs = 'a:1:{s:11:"village.php";b:1;}'
    WHERE acctid IN (1, 385, 386);
