<?php
namespace Lotgd;

use Lotgd\Settings;
use Lotgd\PlayerFunctions;
use Lotgd\Mail;
use Lotgd\GameLog;

class ExpireChars
{
    public static function expire(): void
    {
        global $settings_extended;
        $settings_extended = new Settings('settings_extended');

        $lastexpire = strtotime(getsetting('last_char_expire', DATETIME_DATEMIN));
        $needtoexpire = strtotime('-23 hours');
        if ($lastexpire < $needtoexpire) {
            savesetting('last_char_expire', date('Y-m-d H:i:s'));
            $old = getsetting('expireoldacct', 45);
            $new = getsetting('expirenewacct', 10);
            $trash = getsetting('expiretrashacct', 1);

            $sql1 = 'SELECT login,acctid,dragonkills,level FROM ' . db_prefix('accounts') .
                ' WHERE (superuser&' . NO_ACCOUNT_EXPIRATION . ')=0 AND (1=0'
                . ($old > 0 ? " OR (laston < '" . date('Y-m-d H:i:s', strtotime("-$old days")) . "')" : '')
                . ($new > 0 ? " OR (laston < '" . date('Y-m-d H:i:s', strtotime("-$new days")) . "' AND level=1 AND dragonkills=0)" : '')
                . ($trash > 0 ? " OR (laston < '" . date('Y-m-d H:i:s', strtotime('-' . ($trash + 1) . ' days')) . "' AND level=1 AND experience < 10 AND dragonkills=0)" : '')
                . ')';
            $result1 = db_query($sql1);
            $acctids = [];
            $pinfo = [];
            $dk0lvl = 0;
            $dk0ct = 0;
            $dk1lvl = 0;
            $dk1ct = 0;
            $dks = 0;
            while ($row1 = db_fetch_assoc($result1)) {
                PlayerFunctions::charCleanup($row1['acctid'], CHAR_DELETE_AUTO);
                $acctids[] = $row1['acctid'];
                $pinfo[] = "{$row1['login']}:dk{$row1['dragonkills']}-lv{$row1['level']}";
                if ($row1['dragonkills'] == 0) {
                    $dk0lvl += $row1['level'];
                    $dk0ct++;
                } elseif ($row1['dragonkills'] == 1) {
                    $dk1lvl += $row1['level'];
                    $dk1ct++;
                }
                $dks += $row1['dragonkills'];
            }

            $msg = "[{$dk0ct}] with 0 dk avg lvl [" . round($dk0lvl / max(1, $dk0ct), 2) . "]\n";
            $msg .= "[{$dk1ct}] with 1 dk avg lvl [" . round($dk1lvl / max(1, $dk1ct), 2) . "]\n";
            $msg .= 'Avg DK: [' . round($dks / max(1, count($acctids)), 2) . "]\n";
            $msg .= 'Accounts: ' . implode(', ', $pinfo);
            GameLog::log('Deleted ' . count($acctids) . " accounts:\n$msg", 'char expiration');

            if (count($acctids)) {
                $sql = 'DELETE FROM ' . db_prefix('accounts') .
                    ' WHERE acctid IN (' . implode(',', $acctids) . ')';
                db_query($sql);
            }

            $old = max(1, $old - getsetting('notifydaysbeforedeletion', 5));
            $sql = 'SELECT login,acctid,emailaddress FROM ' . db_prefix('accounts') .
                " WHERE 1=0 " . ($old > 0 ? "OR (laston < '" . date('Y-m-d H:i:s', strtotime("-$old days")) . "')" : '') .
                " AND emailaddress!='' AND sentnotice=0 AND (superuser&" . NO_ACCOUNT_EXPIRATION . ')=0';
            $result = db_query($sql);
            $subject = translate_inline($settings_extended->getSetting('expirationnoticesubject'));
            $message = translate_inline($settings_extended->getSetting('expirationnoticetext'));
            $message = str_replace('{server}', getsetting('serverurl', 'http://nodomain.notd'), $message);

            $collector = [];
            $from_array = [getsetting('gameadminemail', 'postmaster@localhost') => getsetting('gameadminemail', 'postmaster@localhost')];
            $cc_array = [];
            while ($row = db_fetch_assoc($result)) {
                $to_array = [$row['emailaddress'] => $row['emailaddress']];
                $body = str_replace('{charname}', $row['login'], $message);
                Mail::send($to_array, $body, $subject, $from_array, $cc_array, 'text/html');
                $collector[] = $row['acctid'];
            }
            if (!empty($collector)) {
                $sql = 'UPDATE ' . db_prefix('accounts') . ' SET sentnotice=1 WHERE acctid IN (' . implode(',', $collector) . ');';
                db_query($sql);
            }
        }
    }
}
