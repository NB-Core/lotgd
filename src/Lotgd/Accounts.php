<?php
declare(strict_types=1);
namespace Lotgd;
use Lotgd\MySQL\Database;

use Lotgd\Buffs;

/**
 * Helper functions related to account management.
 */
class Accounts
{
    /**
     * Persist the current user session to the database.
     *
     * @return void
     */
    public static function saveUser(): void
    {
        global $session, $dbqueriesthishit, $baseaccount, $companions;

        if (defined('NO_SAVE_USER')) {
            return;
        }

        if (isset($session['loggedin']) && $session['loggedin'] && $session['user']['acctid'] != '') {
            // Ensure that any temporary stat modifications are removed.
            Buffs::restoreBuffFields();

            $session['user']['allowednavs'] = serialize($session['allowednavs']);
            $session['user']['bufflist']    = serialize($session['bufflist']);
            // legacy support, allows boolean values for alive
            $session['user']['alive']       = (int) $session['user']['alive'];

            $sql = '';
            foreach ($session['user'] as $key => $val) {
                if (is_array($val)) {
                    $val = serialize($val);
                }
                // Only update columns which changed
                if ($baseaccount[$key] != $val) {
                    $sql .= "$key='" . addslashes($val) . "', ";
                }
            }
            // Always update laston due to output moving to separate table
            $sql .= "laston='" . date('Y-m-d H:i:s') . "', ";
            $sql  = substr($sql, 0, strlen($sql) - 2);
            $sql  = 'UPDATE ' . Database::prefix('accounts') . ' SET ' . $sql .
                ' WHERE acctid = ' . $session['user']['acctid'];
            Database::query($sql);
            if (isset($session['output']) && $session['output']) {
                $sql_output = 'UPDATE ' . Database::prefix('accounts_output') .
                    " SET output='" . addslashes(gzcompress($session['output'], 1)) . "' WHERE acctid={$session['user']['acctid']};";
                $result = Database::query($sql_output);
                if (Database::affectedRows($result) < 1) {
                    $sql_output = 'REPLACE INTO ' . Database::prefix('accounts_output') .
                        " VALUES ({$session['user']['acctid']},'" . addslashes(gzcompress($session['output'], 1)) . "');";
                    Database::query($sql_output);
                }
            }
            unset($session['bufflist']);
            $session['user'] = [
                'acctid' => $session['user']['acctid'],
                'login'  => $session['user']['login'],
            ];
        }
    }
}
