<?php

declare(strict_types=1);

/**
 * Maintenance tasks for deleting inactive characters and notifying players
 * about impending account expiration.
 */

namespace Lotgd;

use Lotgd\Translator;
use Lotgd\MySQL\Database;
use Lotgd\Settings;
use Lotgd\PlayerFunctions;
use Lotgd\Mail;
use Lotgd\GameLog;

class ExpireChars
{
    /** @var Settings */
    private static Settings $settingsExtended;

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
        $settings = Settings::getInstance();
        $lastExpire = strtotime($settings->getSetting('last_char_expire', DATETIME_DATEMIN));
        if ($lastExpire >= strtotime('-23 hours')) {
            return false;
        }
        $settings->saveSetting('last_char_expire', date('Y-m-d H:i:s'));
        return true;
    }

    /**
     * Remove accounts that have passed the configured inactivity thresholds
     * and log statistics on the removed characters.
     */
    private static function cleanupExpiredAccounts(): void
    {
        $settings = Settings::getInstance();
        $old = (int) $settings->getSetting('expireoldacct', 45);
        $new = (int) $settings->getSetting('expirenewacct', 10);
        $trash = (int) $settings->getSetting('expiretrashacct', 1);

        $rows = self::fetchAccountsToExpire($old, $new, $trash);
        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            Database::query('START TRANSACTION');
            $error = null;
            try {
                if (! PlayerFunctions::charCleanup($row['acctid'], CHAR_DELETE_AUTO)) {
                    throw new \RuntimeException('charCleanup failed');
                }

                $sql = 'DELETE FROM ' . Database::prefix('accounts') . ' WHERE acctid=' . (int) $row['acctid'];
                Database::query($sql);
                if (Database::affectedRows() !== 1) {
                    throw new \RuntimeException('deletion failed');
                }

                Database::query('COMMIT');
            } catch (\Throwable $e) {
                Database::query('ROLLBACK');
                $error = $e;
            }

            if ($error) {
                GameLog::log(
                    'Failed to delete account ' . $row['acctid'] . ': ' . $error->getMessage(),
                    'char deletion failure'
                );
            } else {
                GameLog::log('Deleted account ' . (int) $row['acctid'], 'char expiration');
            }
        }

        self::logExpiredAccountStats($rows);
    }
    /**
     * Delete accounts.
     *
     * @param array<int> $acctIds
     */
    private static function deleteAccounts(array $acctIds): void
    {
        if (empty($acctIds)) {
            return;
        }

        foreach ($acctIds as $acctId) {
            $sql = 'DELETE FROM ' . Database::prefix('accounts') . ' WHERE acctid = ' . (int) $acctId;
            Database::query($sql);

            if (Database::affectedRows() !== 1) {
                GameLog::log(
                    sprintf('Failed to delete account %d: %s', (int) $acctId, Database::error()),
                    'char deletion failure'
                );
            }
        }
    }
    

    /**
     * Select accounts eligible for deletion.
     *
     * @return array<int,array{acctid:int,login:string,dragonkills:int,level:int}>
     */
    private static function fetchAccountsToExpire(int $old, int $new, int $trash): array
    {
        $sql = 'SELECT login,acctid,dragonkills,level FROM ' . Database::prefix('accounts') .
            ' WHERE (superuser&' . NO_ACCOUNT_EXPIRATION . ')=0 AND (1=0'
            . ($old > 0 ? " OR (laston < '" . date('Y-m-d H:i:s', strtotime("-$old days")) . "')" : '')
            . ($new > 0 ? " OR (laston < '" . date('Y-m-d H:i:s', strtotime("-$new days")) . "' AND level=1 AND dragonkills=0)" : '')
            . ($trash > 0 ? " OR (laston < '" . date('Y-m-d H:i:s', strtotime('-' . ($trash + 1) . ' days')) . "' AND level=1 AND experience < 10 AND dragonkills=0)" : '')
            . ')';

        $result = Database::query($sql);
        $rows = [];
        while ($row = Database::fetchAssoc($result)) {
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
     * Send expiration warning emails to players nearing deletion.
     */
    private static function notifyUpcomingExpirations(): void
    {
        $settings = Settings::getInstance();
        $old = max(1, ((int) $settings->getSetting('expireoldacct', 45)) - ((int) $settings->getSetting('notifydaysbeforedeletion', 5)));

        $threshold = date('Y-m-d H:i:s', strtotime("-$old days"));
        $sql = 'SELECT login,acctid,emailaddress FROM ' . Database::prefix('accounts')
            . " WHERE (laston < '$threshold')"
            . " AND emailaddress!='' AND sentnotice=0 AND (superuser&" . NO_ACCOUNT_EXPIRATION . ')=0';
        $result = Database::query($sql);

        $subject = Translator::translateInline(self::$settingsExtended->getSetting('expirationnoticesubject'));
        $message = Translator::translateInline(self::$settingsExtended->getSetting('expirationnoticetext'));
        $message = str_replace('{server}', $settings->getSetting('serverurl', 'http://nodomain.notd'), $message);

        $collector = [];
        $from = [$settings->getSetting('gameadminemail', 'postmaster@localhost') => $settings->getSetting('gameadminemail', 'postmaster@localhost')];
        $cc = [];
        while ($row = Database::fetchAssoc($result)) {
            $to = [$row['emailaddress'] => $row['emailaddress']];
            $body = str_replace('{charname}', $row['login'], $message);
            Mail::send($to, $body, $subject, $from, $cc, 'text/html');
            $collector[] = $row['acctid'];
        }

        if (!empty($collector)) {
            $sql = 'UPDATE ' . Database::prefix('accounts') . ' SET sentnotice=1 WHERE acctid IN (' . implode(',', $collector) . ');';
            Database::query($sql);
        }
    }
}
