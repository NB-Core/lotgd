<?php

declare(strict_types=1);

/**
 * Functions related to ban checking.
 */

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\Cookies;

class CheckBan
{
    /**
     * Check if the current user or ip/unique id is banned.
     *
     * @param string|null $login Optional user login name to check
     *
     * @return void
     */
    public static function check(?string $login = null): void
    {
        global $session;

        if (isset($session['banoverride']) && $session['banoverride']) {
            return;
        }

        if ($login === null) {
            $ip = $_SERVER['REMOTE_ADDR'];
            $id = Cookies::getLgi() ?? '';
        } else {
            $sql = "SELECT lastip,uniqueid,banoverride,superuser FROM " . Database::prefix('accounts') . " WHERE login='$login'";
            $result = Database::query($sql);
            $row = Database::fetchAssoc($result);
            if ($row['banoverride'] || ($row['superuser'] & ~SU_DOESNT_GIVE_GROTTO)) {
                $session['banoverride'] = true;
                Database::freeResult($result);
                return;
            }
            Database::freeResult($result);
            $ip = $row['lastip'];
            $id = $row['uniqueid'];
        }

        Database::query("DELETE FROM " . Database::prefix('bans') . " WHERE banexpire < '" . date('Y-m-d H:m:s') . "' AND banexpire<'" . DATETIME_DATEMAX . "'");
        $sql = "SELECT * FROM " . Database::prefix('bans') . " WHERE ((substring('$ip',1,length(ipfilter))=ipfilter AND ipfilter<>'') OR (uniqueid='$id' AND uniqueid<>'')) AND banexpire>='" . date('Y-m-d H:m:s') . "'";
        $result = Database::query($sql);
        if (Database::numRows($result) > 0) {
            $session = [];
            tlschema('ban');
            if (!isset($session['message'])) {
                $session['message'] = '';
            }
            $session['message'] .= translate_inline("`n`4You fall under a ban currently in place on this website:`n");
            while ($row = Database::fetchAssoc($result)) {
                $session['message'] .= $row['banreason'] . "`n";
                if ($row['banexpire'] == DATETIME_DATEMAX) {
                    $session['message'] .= translate_inline("`\$This ban is permanent!`0");
                } else {
                    $leftover = strtotime($row['banexpire']) - strtotime('now');
                    $hours = floor($leftover / 3600);
                    $tl_hours = ($hours != 1 ? 'hours' : 'hour');
                    $mins = round(($leftover - ($hours * 3600)) / 60, 2);
                    $tl_mins = ($mins != 1 ? 'minutes' : 'minute');
                    $session['message'] .= sprintf_translate("`^This ban will be removed `\$after`^ %s.`n`0", date('M d, Y', strtotime($row['banexpire'])));
                    $session['message'] .= sprintf_translate("`^(This means in %s %s and %s %s)`0", $hours, $tl_hours, $mins, $tl_mins);
                }
                $sql = "UPDATE " . Database::prefix('bans') . " SET lasthit='" . date('Y-m-d H:i:s') . "' WHERE ipfilter='{$row['ipfilter']}' AND uniqueid='{$row['uniqueid']}'";
                Database::query($sql);
                $session['message'] .= "`n";
                $session['message'] .= sprintf_translate("`n`4The ban was issued by %s`^.`n", $row['banner']);
            }
            $session['message'] .= translate_inline("`4If you wish, you may appeal your ban with the petition link.");
            tlschema();
            header('Location: index.php');
            exit();
        }
        Database::freeResult($result);
    }
}
