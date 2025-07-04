<?php
namespace Lotgd;

use Lotgd\Settings;
use Lotgd\PlayerFunctions;
use Lotgd\Mail;
use Lotgd\GameLog;

/**
 * Maintenance tasks for deleting inactive characters and notifying players
 * about impending account expiration.
 */
class ExpireChars
{
    /** @var Settings */
    private static $settingsExtended;

    /** Execute the full expiration routine. */
    public static function expire(): void
    {
        self::$settingsExtended = new Settings('settings_extended');

        if (!self::needsExpiration()) {
            return;
        }

        self::cleanupExpiredAccounts();
        self::notifyUpcomingExpirations();
    }

    /**
     * Determine whether the expiration routine should run.
     * If the last run was more than 23 hours ago, update the timestamp
     * and return true.
     */
    private static function needsExpiration(): bool
    {
        $lastExpire = strtotime(getsetting('last_char_expire', DATETIME_DATEMIN));
        if ($lastExpire >= strtotime('-23 hours')) {
            return false;
        }
        savesetting('last_char_expire', date('Y-m-d H:i:s'));
        return true;
    }

    /**
     * Remove accounts that have passed the configured inactivity thresholds
     * and log statistics on the removed characters.
     */
    private static function cleanupExpiredAccounts(): void
    {
        $old = getsetting('expireoldacct', 45);
        $new = getsetting('expirenewacct', 10);
        $trash = getsetting('expiretrashacct', 1);

        $rows = self::fetchAccountsToExpire($old, $new, $trash);
        if (empty($rows)) {
            return;
        }

        $acctIds = [];
        foreach ($rows as $row) {
            PlayerFunctions::charCleanup($row['acctid'], CHAR_DELETE_AUTO);
            $acctIds[] = $row['acctid'];
        }

        self::logExpiredAccountStats($rows);
        self::deleteAccounts($acctIds);
    }

    /**
     * Select accounts eligible for deletion.
     *
     * @return array<int,array{acctid:int,login:string,dragonkills:int,level:int}>
     */
    private static function fetchAccountsToExpire(int $old, int $new, int $trash): array
    {
        $sql = 'SELECT login,acctid,dragonkills,level FROM ' . db_prefix('accounts') .
            ' WHERE (superuser&' . NO_ACCOUNT_EXPIRATION . ')=0 AND (1=0'
            . ($old > 0 ? " OR (laston < '" . date('Y-m-d H:i:s', strtotime("-$old days")) . "')" : '')
            . ($new > 0 ? " OR (laston < '" . date('Y-m-d H:i:s', strtotime("-$new days")) . "' AND level=1 AND dragonkills=0)" : '')
            . ($trash > 0 ? " OR (laston < '" . date('Y-m-d H:i:s', strtotime('-' . ($trash + 1) . ' days')) . "' AND level=1 AND experience < 10 AND dragonkills=0)" : '')
            . ')';

        $result = db_query($sql);
        $rows = [];
        while ($row = db_fetch_assoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Write summary information about deleted accounts to the gamelog.
     */
    private static function logExpiredAccountStats(array $rows): void
    {
        $acctCount = count($rows);
        if ($acctCount === 0) {
            return;
        }

        $dk0lvl = $dk0ct = $dk1lvl = $dk1ct = $dks = 0;
        $info = [];
        foreach ($rows as $row) {
            $info[] = "{$row['login']}:dk{$row['dragonkills']}-lv{$row['level']}";
            if ($row['dragonkills'] == 0) {
                $dk0lvl += $row['level'];
                $dk0ct++;
            } elseif ($row['dragonkills'] == 1) {
                $dk1lvl += $row['level'];
                $dk1ct++;
            }
            $dks += $row['dragonkills'];
        }

        $msg = "[{$dk0ct}] with 0 dk avg lvl [" . round($dk0lvl / max(1, $dk0ct), 2) . "]\n";
        $msg .= "[{$dk1ct}] with 1 dk avg lvl [" . round($dk1lvl / max(1, $dk1ct), 2) . "]\n";
        $msg .= 'Avg DK: [' . round($dks / max(1, $acctCount), 2) . "]\n";
        $msg .= 'Accounts: ' . implode(', ', $info);

        GameLog::log('Deleted ' . $acctCount . " accounts:\n$msg", 'char expiration');
    }

    /**
     * Delete the supplied account ids from the database.
     */
    private static function deleteAccounts(array $acctIds): void
    {
        if (empty($acctIds)) {
            return;
        }

        $sql = 'DELETE FROM ' . db_prefix('accounts') . ' WHERE acctid IN (' . implode(',', $acctIds) . ')';
        db_query($sql);
    }

    /**
     * Send expiration warning emails to players nearing deletion.
     */
    private static function notifyUpcomingExpirations(): void
    {
        $old = max(1, getsetting('expireoldacct', 45) - getsetting('notifydaysbeforedeletion', 5));
        $sql = 'SELECT login,acctid,emailaddress FROM ' . db_prefix('accounts') .
            " WHERE 1=0 " . ($old > 0 ? "OR (laston < '" . date('Y-m-d H:i:s', strtotime("-$old days")) . "')" : '') .
            " AND emailaddress!='' AND sentnotice=0 AND (superuser&" . NO_ACCOUNT_EXPIRATION . ')=0';
        $result = db_query($sql);

        $subject = translate_inline(self::$settingsExtended->getSetting('expirationnoticesubject'));
        $message = translate_inline(self::$settingsExtended->getSetting('expirationnoticetext'));
        $message = str_replace('{server}', getsetting('serverurl', 'http://nodomain.notd'), $message);

        $collector = [];
        $from = [getsetting('gameadminemail', 'postmaster@localhost') => getsetting('gameadminemail', 'postmaster@localhost')];
        $cc = [];
        while ($row = db_fetch_assoc($result)) {
            $to = [$row['emailaddress'] => $row['emailaddress']];
            $body = str_replace('{charname}', $row['login'], $message);
            Mail::send($to, $body, $subject, $from, $cc, 'text/html');
            $collector[] = $row['acctid'];
        }

        if (!empty($collector)) {
            $sql = 'UPDATE ' . db_prefix('accounts') . ' SET sentnotice=1 WHERE acctid IN (' . implode(',', $collector) . ');';
            db_query($sql);
        }
    }
}
