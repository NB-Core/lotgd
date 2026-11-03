-- Before every suite (run.sh): what one suite may leave behind for the next.
-- Each suite still sets up the rest of what it needs.
INSERT IGNORE INTO clans (clanid, clanname, clanshort, clanmotd, clandesc, motdauthor, descauthor, customsay)
    VALUES (26, 'Formtest Hall', 'FT', '', '', 1, 1, '');
DELETE FROM action_log WHERE acctid IN (1, 385, 386);
DELETE FROM accounts_output WHERE acctid IN (1, 385, 386);
DELETE FROM realtime_streams;
UPDATE accounts SET loggedin = 0, transferredtoday = 0, amountouttoday = 0,
    restorepage = 'village.php', allowednavs = 'a:1:{s:11:"village.php";b:1;}'
    WHERE acctid IN (1, 385, 386);
