<?php

declare(strict_types=1);

/**
 * Maintenance tasks for deleting inactive characters and notifying players
 * about impending account expiration.
 */

namespace Lotgd;

use DateTimeImmutable;
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
        $original = Settings::getInstance();

        self::$settingsExtended = new Settings('settings_extended');
        Settings::setInstance($original);
        $GLOBALS['settings'] = $original;

        if (! self::needsExpiration()) {
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
        global $session;
        $settings = Settings::getInstance();
        $old = (int) $settings->getSetting('expireoldacct', 45);
        $new = (int) $settings->getSetting('expirenewacct', 10);
        $trash = (int) $settings->getSetting('expiretrashacct', 1);

        $rows = self::fetchAccountsToExpire($old, $new, $trash);
        if (empty($rows)) {
            return;
        }

        $deletedAcctIds = [];

        foreach ($rows as $row) {
            Database::query('START TRANSACTION');
            $error = null;
            $cleanupPerformed = false;
            try {
                $cleanupPerformed = PlayerFunctions::charCleanup($row['acctid'], CHAR_DELETE_AUTO);

                if ($cleanupPerformed) {
                    $sql = 'DELETE FROM ' . Database::prefix('accounts') . ' WHERE acctid=' . (int) $row['acctid'];
                    Database::query($sql);
                    if (Database::affectedRows() !== 1) {
                        throw new \RuntimeException('deletion failed');
                    }

                    Database::query('COMMIT');
                    $deletedAcctIds[] = (int) $row['acctid'];
                } else {
                    Database::query('ROLLBACK');
                }
            } catch (\Throwable $e) {
                Database::query('ROLLBACK');
                $error = $e;
            }

            if ($error) {
                GameLog::log(
                    'Failed to delete account ' . $row['acctid'] . ': ' . $error->getMessage(),
                    'char deletion failure',
                    false,
                    $session['user']['acctid'] ?? 0
                );
            } elseif ($cleanupPerformed) {
                GameLog::log(
                    sprintf('Deleted account %d (%s)', $row['acctid'], $row['login']),
                    'char expiration',
                    false,
                    $session['user']['acctid'] ?? 0
                );
            } else {
                GameLog::log(
                    'Cleanup skipped for account ' . (int) $row['acctid'] . ' (prevented by hook)',
                    'char expiration',
                    false,
                    $session['user']['acctid'] ?? 0
                );
            }
        }

        $deletedRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array((int) $row['acctid'], $deletedAcctIds, true)
        ));

        self::logExpiredAccountStats($deletedRows);
    }

    /**
     * Select accounts eligible for deletion.
     *
     * @return array<int,array{acctid:int,login:string,dragonkills:int,level:int}>
     */
    private static function fetchAccountsToExpire(int $old, int $new, int $trash, ?DateTimeImmutable $now = null): array
    {
        $base = $now ?? new DateTimeImmutable('now');
        $conditions = [];
        if ($old > 0) {
            $conditions[] = "(laston < '" . $base->modify("-$old days")->format('Y-m-d H:i:s') . "')";
        }
        if ($new > 0) {
            $conditions[] = "(laston < '" . $base->modify("-$new days")->format('Y-m-d H:i:s') . "' AND level=1 AND dragonkills=0)";
        }
        if ($trash > 0) {
            $conditions[] = "(laston < '" . $base->modify('-' . ($trash + 1) . ' days')->format('Y-m-d H:i:s') . "' AND level=1 AND experience < 10 AND dragonkills=0)";
        }

        if (empty($conditions)) {
            return [];
        }

        $sql = 'SELECT login,acctid,dragonkills,level FROM ' . Database::prefix('accounts') .
            ' WHERE (superuser&' . NO_ACCOUNT_EXPIRATION . ')=0 AND (' . implode(' OR ', $conditions) . ')';

        $result = Database::query($sql);
        $rows = [];
        while ($row = Database::fetchAssoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Write summary information about deleted accounts to the gamelog.
     *
     * @param array<int,array{login:string,acctid:int,dragonkills:int,level:int}> $deletedRows
     */
    private static function logExpiredAccountStats(array $deletedRows): void
    {
        global $session;
        $acctCount = count($deletedRows);
        if ($acctCount === 0) {
            return;
        }

        $dk0lvl = $dk0ct = $dk1lvl = $dk1ct = $dks = 0;
        $info = [];
        foreach ($deletedRows as $row) {
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

        GameLog::log(
            'Deleted ' . $acctCount . " accounts:\n$msg",
            'char expiration',
            false,
            $session['user']['acctid'] ?? 0
        );
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
            $mailResult = Mail::send($to, $body, $subject, $from, $cc, 'text/html', true);

            if (is_array($mailResult) && ! $mailResult['success']) {
                error_log(sprintf('Failed to send expiration notice to %s: %s', $row['emailaddress'], $mailResult['error']));
                continue;
            }

            if ($mailResult === false) {
                error_log(sprintf('Failed to send expiration notice to %s.', $row['emailaddress']));
                continue;
            }

            $collector[] = $row['acctid'];
        }

        if (!empty($collector)) {
            $sql = 'UPDATE ' . Database::prefix('accounts') . ' SET sentnotice=1 WHERE acctid IN (' . implode(',', $collector) . ');';
            Database::query($sql);
        }
    }
}
