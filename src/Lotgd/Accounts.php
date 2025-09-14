<?php

declare(strict_types=1);

/**
 * Helper functions related to account management.
 */

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\Buffs;
use Lotgd\Doctrine\Bootstrap;
use Lotgd\Entity\Account;

use const DATETIME_DATEMIN;

class Accounts
{
    /**
     * Fields in the account entity that store date and time values.
     *
     * @var string[]
     */
    private const DATE_FIELDS = [
        'laston',
        'lastmotd',
        'lasthit',
        'pvpflag',
        'recentcomments',
        'biotime',
        'regdate',
        'clanjoindate',
    ];

    private static ?Account $accountEntity = null;

    public static function setAccountEntity(?Account $account): void
    {
        self::$accountEntity = $account;
    }

    public static function getAccountEntity(): ?Account
    {
        return self::$accountEntity;
    }

    private static function entityToArray(Account $account): array
    {
        $ref  = new \ReflectionClass($account);
        $data = [];

        foreach ($ref->getProperties() as $prop) {
            $prop->setAccessible(true);
            $value = $prop->getValue($account);
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }
            $data[$prop->getName()] = $value;
        }

        return $data;
    }
    /**
     * Persist the current user session to the database.
     *
     * @return void
     */
    public static function saveUser(): void
    {
        global $session, $baseaccount, $companions;

        if (defined('NO_SAVE_USER')) {
            return;
        }

        if (isset($session['loggedin']) && $session['loggedin'] && $session['user']['acctid'] != '') {
            // Ensure that any temporary stat modifications are removed.
            Buffs::restoreBuffFields();

            $session['user']['allowednavs'] = serialize($session['allowednavs'] ?? []);
            $session['user']['bufflist']    = serialize($session['bufflist']);
            // legacy support, allows boolean values for alive
            $session['user']['alive']       = (int) $session['user']['alive'];

            static $bootstrapExists = null;
            if ($bootstrapExists === null) {
                $bootstrapExists = class_exists(Bootstrap::class);
            }

            if ($bootstrapExists) {
                $em = Bootstrap::getEntityManager();
                if (self::$accountEntity && self::$accountEntity->getAcctid() === $session['user']['acctid']) {
                    $account = self::$accountEntity;
                } else {
                    $account = $em->find(Account::class, $session['user']['acctid']);
                    self::$accountEntity = $account;
                }

                if ($account) {
                    foreach ($session['user'] as $key => $val) {
                        if (is_array($val)) {
                            $val = serialize($val);
                        }
                        if ($baseaccount[$key] != $val) {
                            $method = 'set' . ucfirst($key);
                            if (
                                method_exists($account, $method)
                                || property_exists($account, $key)
                            ) {
                                if (
                                    $val
                                    && in_array($key, self::DATE_FIELDS, true)
                                    && ! $val instanceof \DateTimeInterface
                                ) {
                                    try {
                                        $val = new \DateTime($val);
                                    } catch (\Exception $e) {
                                        $val = new \DateTime(DATETIME_DATEMIN);
                                    }
                                }
                                $account->$method($val);
                            }
                        }
                    }
                    $account->setLaston(new \DateTime());
                    $em->flush();
                }
            } else {
                $sql = '';
                foreach ($session['user'] as $key => $val) {
                    if (is_array($val)) {
                        $val = serialize($val);
                    }
                    if ($baseaccount[$key] != $val) {
                        if (is_string($val)) {
                            $escapedVal = addslashes($val);
                        } else {
                            $escapedVal = $val;
                        }
                        $sql .= "$key='" . $escapedVal . "', ";
                    }
                }
                // Always update laston due to output moving to separate table
                $sql .= "laston='" . date('Y-m-d H:i:s') . "', ";
                $sql  = substr($sql, 0, strlen($sql) - 2);
                $sql  = 'UPDATE ' . Database::prefix('accounts') . ' SET ' . $sql .
                    ' WHERE acctid = ' . $session['user']['acctid'];
                Database::query($sql);
            }
            if (isset($session['output']) && $session['output']) {
                $sql_output = 'UPDATE ' . Database::prefix('accounts_output') .
                    " SET output='" . addslashes(gzcompress($session['output'], 1)) . "' WHERE acctid={$session['user']['acctid']};";
                Database::query($sql_output);
                if (Database::affectedRows() < 1) {
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
