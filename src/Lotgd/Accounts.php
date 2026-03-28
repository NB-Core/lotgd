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
                $conn   = Database::getDoctrineConnection();
                $sets   = [];
                $params = [];

                foreach ($session['user'] as $key => $val) {
                    if ($key === 'acctid') {
                        continue;
                    }
                    if (is_array($val)) {
                        $val = serialize($val);
                    }
                    if ($baseaccount[$key] != $val) {
                        $sets[]       = "$key = :$key";
                        $params[$key] = $val;
                    }
                }

                // Always update laston due to output moving to separate table
                $sets[]           = 'laston = :laston';
                $params['laston'] = date('Y-m-d H:i:s');

                if ($sets) {
                    $params['acctid'] = $session['user']['acctid'];
                    $conn->executeStatement(
                        'UPDATE ' . Database::prefix('accounts') . ' SET '
                            . implode(', ', $sets)
                            . ' WHERE acctid = :acctid',
                        $params
                    );
                }
            }
            if (isset($session['output']) && $session['output']) {
                $conn       = Database::getDoctrineConnection();
                $table      = Database::prefix('accounts_output');
                $compressed = gzcompress($session['output'], 1);
                $acctid     = $session['user']['acctid'];

                $affected = $conn->executeStatement(
                    'UPDATE ' . $table . ' SET output = :output WHERE acctid = :acctid',
                    ['output' => $compressed, 'acctid' => $acctid]
                );

                if ($affected < 1) {
                    $conn->executeStatement(
                        'REPLACE INTO ' . $table . ' (acctid, output) VALUES (:acctid, :output)',
                        ['acctid' => $acctid, 'output' => $compressed]
                    );
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
