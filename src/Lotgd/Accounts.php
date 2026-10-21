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
     * Fields that change on every page without changing the game state. They
     * are written as before, but do not move the account version on.
     *
     * @var string[]
     */
    private const BOOKKEEPING_FIELDS = [
        'laston',
        'lasthit',
        'allowednavs',
        'page_revision',
        'version',
        'gensize',
        'gentime',
        'gentimecount',
        'restorepage',
        'lastip',
        'uniqueid',
    ];

    /**
     * Persist the current user session to the database and end the request's
     * use of it.
     *
     * Kept as it always was: persist() writes the changed fields,
     * finishRequest() stores the page for badnav.php and reduces the session.
     *
     * @return void
     */
    public static function saveUser(): void
    {
        if (defined('NO_SAVE_USER')) {
            return;
        }

        if (!self::hasLoggedInUser()) {
            return;
        }

        self::persist();
        self::finishRequest();
    }

    /**
     * Write the fields of the session user that changed since they were read.
     *
     * A change to the game state moves accounts.version on (once the column
     * exists); bookkeeping fields such as laston do not. Afterwards the base
     * the diff is taken against is the written state, so calling this twice
     * writes nothing twice.
     *
     * With $checkVersion -- only from inside Action::run() -- the write is a
     * conditional update on the version read with the account and throws
     * VersionConflict when another request changed the account meanwhile.
     */
    public static function persist(bool $checkVersion = false): void
    {
        global $session, $baseaccount;

        if (!self::hasLoggedInUser()) {
            return;
        }

        // Ensure that any temporary stat modifications are removed.
        Buffs::restoreBuffFields();

        $session['user']['allowednavs'] = serialize($session['allowednavs'] ?? []);
        $session['user']['bufflist']    = serialize($session['bufflist']);
        // legacy support, allows boolean values for alive
        $session['user']['alive']       = (int) $session['user']['alive'];

        $changed = [];
        foreach ($session['user'] as $key => $val) {
            if (is_array($val)) {
                $val = serialize($val);
            }
            if (($baseaccount[$key] ?? null) != $val) {
                $changed[$key] = $val;
            }
        }

        $versioned = array_key_exists('version', $session['user']);
        $expectedVersion = (int) ($baseaccount['version'] ?? ($session['user']['version'] ?? 0));
        if ($versioned && array_diff_key($changed, array_flip(self::BOOKKEEPING_FIELDS)) !== []) {
            $session['user']['version'] = $expectedVersion + 1;
            $changed['version'] = $session['user']['version'];
        }

        if ($checkVersion) {
            self::persistChecked($changed, $expectedVersion);
        } else {
            self::persistUnchecked($changed);
        }

        foreach ($changed as $key => $val) {
            $baseaccount[$key] = $val;
        }
    }

    /**
     * Store the page for badnav.php and reduce the session user to the fields
     * that identify it, as the end of every request does.
     */
    public static function finishRequest(): void
    {
        global $session;

        if (!self::hasLoggedInUser()) {
            return;
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

    private static function hasLoggedInUser(): bool
    {
        global $session;

        return isset($session['loggedin']) && $session['loggedin'] && ($session['user']['acctid'] ?? '') != '';
    }

    /**
     * The write saveUser() has always made: through the Doctrine entity when
     * Doctrine is available, otherwise one UPDATE. laston is always set.
     *
     * @param array<string,mixed> $changed
     */
    private static function persistUnchecked(array $changed): void
    {
        global $session;

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
                foreach ($changed as $key => $val) {
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
                $account->setLaston(new \DateTime());
                $em->flush();
            }

            return;
        }

        $conn   = Database::getDoctrineConnection();
        $sets   = [];
        $params = [];

        foreach ($changed as $key => $val) {
            if ($key === 'acctid') {
                continue;
            }
            $sets[]       = "$key = :$key";
            $params[$key] = $val;
        }

        // Always update laston due to output moving to separate table
        $sets[]           = 'laston = :laston';
        $params['laston'] = date('Y-m-d H:i:s');

        $params['acctid'] = $session['user']['acctid'];
        $conn->executeStatement(
            'UPDATE ' . Database::prefix('accounts') . ' SET '
                . implode(', ', $sets)
                . ' WHERE acctid = :acctid',
            $params
        );
    }

    /**
     * A conditional update on the version the account was read with.
     *
     * Always through DBAL, so the condition and the rowcount are exact; the
     * cached entity is let go so no stale copy is flushed later in the request.
     *
     * @param array<string,mixed> $changed
     *
     * @throws VersionConflict When the account's version moved on meanwhile.
     */
    private static function persistChecked(array $changed, int $expectedVersion): void
    {
        global $session;

        unset($changed['acctid']);
        $sets   = [];
        $params = [];
        foreach ($changed as $key => $val) {
            // Like the entity path: only what the account maps. A module that
            // keeps a value of its own in the session user must not turn the
            // action into an unknown-column error.
            if (!property_exists(Account::class, $key)) {
                continue;
            }
            $sets[]       = "$key = :$key";
            $params[$key] = $val;
        }
        if ($sets === []) {
            // Nothing to write (a second persist() in the same action): the
            // row is locked by the action already, and laston is the page
            // end's to write.
            return;
        }
        $sets[]           = 'laston = :laston';
        $params['laston'] = date('Y-m-d H:i:s');
        $params['acctid'] = (int) $session['user']['acctid'];
        $params['expected_version'] = $expectedVersion;

        $affected = Database::getDoctrineConnection()->executeStatement(
            'UPDATE ' . Database::prefix('accounts') . ' SET '
                . implode(', ', $sets)
                . ' WHERE acctid = :acctid AND version = :expected_version',
            $params
        );

        if ((int) $affected !== 1) {
            throw new VersionConflict(sprintf(
                'Account %d changed since version %d was read.',
                $params['acctid'],
                $expectedVersion
            ));
        }

        if (self::$accountEntity !== null && class_exists(Bootstrap::class)) {
            $em = Bootstrap::getEntityManager();
            if ($em->contains(self::$accountEntity)) {
                $em->detach(self::$accountEntity);
            }
        }
        self::$accountEntity = null;
    }
}
