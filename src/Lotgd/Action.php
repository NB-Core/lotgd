<?php

declare(strict_types=1);

namespace Lotgd;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\ParameterType;
use Lotgd\Action\ActionAborted;
use Lotgd\Action\ActionResult;
use Lotgd\Doctrine\Bootstrap;
use Lotgd\MySQL\Database;

/**
 * A guarded game action: one database transaction, run at most once per form.
 *
 * Action::run() is the order docs/ReactiveCore.md (Phase 3) fixes:
 *
 *  1. refuse to nest inside an open transaction;
 *  2. begin;
 *  3. lock the account row. Every action of one account queues here, across
 *     tabs and devices, so the same form sent twice at once runs once;
 *  4. look the action id up in action_log: the same submission again is
 *     answered from its stored result -- even though its page is stale by
 *     now --, the same id with other parameters is refused;
 *  5. compare the locked version with the one the page was read with: a
 *     change by another tab, device or player is a conflict, nothing runs;
 *  6. snapshot the session state the action may change;
 *  7. run the action;
 *  8. persist the account (version-checked) and log the action with its
 *     result, in the same transaction;
 *  9. commit, then run the after-commit callbacks.
 *
 * Anything thrown in between rolls the transaction back, restores the
 * snapshot, lets go of the cached account entity and is rethrown -- except a
 * version conflict, a deadlock or a lock timeout, which are a conflict, and
 * an ActionAborted, which is a refusal. No log row is left, so the same id
 * may run again. A process that dies mid-action
 * leaves an open transaction the database rolls back when the connection goes.
 */
final class Action
{
    private const ID_PATTERN = '/^[0-9a-f]{32}$/';
    public const FIELD = 'action_id';

    private static bool $running = false;

    /** @var list<callable():void> */
    private static array $afterCommit = [];

    /**
     * @param array<string,mixed>                 $params The parameters that make the action what it is;
     *                                                    hashed to tell a repeat from a reused id.
     * @param callable():array<string,mixed>      $fn     The action; returns the result the page shows.
     */
    public static function run(string $name, string $actionId, array $params, callable $fn): ActionResult
    {
        global $session, $baseaccount, $companions;

        if (preg_match(self::ID_PATTERN, $actionId) !== 1) {
            return ActionResult::rejected('missing-action-id');
        }

        $conn = Database::getDoctrineConnection();
        if (self::$running || $conn->isTransactionActive()) {
            throw new \LogicException('Action::run() cannot run inside another transaction.');
        }

        $acctid = (int) ($session['user']['acctid'] ?? 0);
        $paramsHash = self::paramsHash($name, $params);

        $snapshot = [
            'user' => $session['user'] ?? [],
            'bufflist' => $session['bufflist'] ?? [],
            'allowednavs' => $session['allowednavs'] ?? [],
            'companions' => $companions ?? null,
            'baseaccount' => $baseaccount ?? [],
        ];

        $conn->beginTransaction();
        self::$running = true;
        self::$afterCommit = [];

        try {
            $current = $conn->fetchOne(
                'SELECT version FROM ' . Database::prefix('accounts') . ' WHERE acctid = :acctid FOR UPDATE',
                ['acctid' => $acctid],
                ['acctid' => ParameterType::INTEGER]
            );

            // A plain read, not a locking one: the account lock above already
            // queues every action of this account, and this read's snapshot is
            // taken after it, so it sees every earlier action's row. A locking
            // read of the missing row would take a gap lock instead, and two
            // players' first actions would deadlock on each other's insert.
            $logged = $conn->fetchAssociative(
                'SELECT action_name, params_hash, result FROM ' . Database::prefix('action_log')
                . ' WHERE acctid = :acctid AND action_id = :action_id',
                ['acctid' => $acctid, 'action_id' => $actionId],
                ['acctid' => ParameterType::INTEGER, 'action_id' => ParameterType::STRING]
            );
            if (is_array($logged)) {
                self::finish($conn, false);
                if ($logged['action_name'] === $name && hash_equals((string) $logged['params_hash'], $paramsHash)) {
                    $data = json_decode((string) $logged['result'], true);

                    return ActionResult::replayed(is_array($data) ? $data : []);
                }

                return ActionResult::rejected('action-id-reused');
            }

            if ($current === false || (int) $current !== (int) ($baseaccount['version'] ?? -1)) {
                self::finish($conn, false);

                return ActionResult::conflict();
            }

            $result = $fn();

            Accounts::persist(true);

            $conn->executeStatement(
                'INSERT INTO ' . Database::prefix('action_log')
                . ' (acctid, action_id, action_name, params_hash, request_uri, result, created)'
                . ' VALUES (:acctid, :action_id, :action_name, :params_hash, :request_uri, :result, :created)',
                [
                    'acctid' => $acctid,
                    'action_id' => $actionId,
                    'action_name' => $name,
                    'params_hash' => $paramsHash,
                    'request_uri' => substr(PhpGenericEnvironment::getRequestUri(), 0, 255),
                    'result' => (string) json_encode($result),
                    'created' => date('Y-m-d H:i:s'),
                ],
                ['acctid' => ParameterType::INTEGER]
            );

            self::finish($conn, true);
        } catch (VersionConflict $conflict) {
            self::abort($conn, $snapshot);

            return ActionResult::conflict();
        } catch (RetryableException $contention) {
            // A deadlock or a lock wait that timed out -- two players
            // crediting each other at the same moment. The database has
            // rolled one of them back; to that player it is a changed account.
            self::abort($conn, $snapshot);

            return ActionResult::conflict();
        } catch (ActionAborted $aborted) {
            self::abort($conn, $snapshot);

            return ActionResult::rejected($aborted->getMessage());
        } catch (\Throwable $error) {
            self::abort($conn, $snapshot);

            throw $error;
        }

        foreach (self::takeAfterCommit() as $callback) {
            try {
                $callback();
            } catch (\Throwable $error) {
                // Best effort by definition: the action is committed. The log
                // records what a player may now miss (a mail, a debug line).
                SecurityLog::event(
                    'Guarded action follow-up failed: ' . $error->getMessage(),
                    ['action' => $name],
                    $acctid
                );
            }
        }

        return ActionResult::done($result);
    }

    /**
     * Queue work that must not happen unless the action commits -- an e-mail,
     * a mail to another player, a debug line. Outside an action it runs now.
     */
    public static function afterCommit(callable $callback): void
    {
        if (!self::$running) {
            $callback();

            return;
        }

        self::$afterCommit[] = $callback;
    }

    /**
     * Whether pages run their game actions guarded (setting guarded_actions).
     */
    public static function enabled(): bool
    {
        return Settings::hasInstance() && (int) Settings::getInstance()->getSetting('guarded_actions', 0) === 1;
    }

    /**
     * Whether this request sends again a form whose action already ran from
     * this very URI.
     *
     * The page that ran it gave out new links, so the address is no longer an
     * allowed navigation and the request would end on badnav.php -- and the
     * player who lost the answer would never learn whether the money moved.
     * ForcedNavigation lets such a request through to its page instead, where
     * the page's own CSRF check runs and Action::run() answers from the log.
     * Nothing runs again: the log row is what lets the request in, and the
     * same row makes the action a replay.
     */
    public static function isReplayOf(int $acctid, string $requestUri): bool
    {
        if (!self::enabled() || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return false;
        }
        $actionId = self::postedId();
        if ($actionId === '' || $acctid <= 0) {
            return false;
        }

        $found = Database::getDoctrineConnection()->fetchOne(
            'SELECT 1 FROM ' . Database::prefix('action_log')
            . ' WHERE acctid = :acctid AND action_id = :action_id AND request_uri = :request_uri',
            ['acctid' => $acctid, 'action_id' => $actionId, 'request_uri' => substr($requestUri, 0, 255)],
            ['acctid' => ParameterType::INTEGER]
        );

        return $found !== false && $found !== null;
    }

    public static function isRunning(): bool
    {
        return self::$running;
    }

    /**
     * Refuse a statement that would commit the action's transaction behind
     * its back (LOCK TABLES, DDL, OPTIMIZE TABLE).
     */
    public static function guardImplicitCommit(string $what): void
    {
        if (self::$running) {
            throw new \LogicException($what . ' would commit the running guarded action implicitly.');
        }
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * The hidden form field that carries a fresh action id.
     */
    public static function idField(): string
    {
        return "<input type='hidden' name='" . self::FIELD . "' value='" . self::newId() . "'>";
    }

    /**
     * The action id the form posted, or '' when it is missing or malformed.
     */
    public static function postedId(): string
    {
        $posted = Http::post(self::FIELD);

        return is_string($posted) && preg_match(self::ID_PATTERN, $posted) === 1 ? $posted : '';
    }

    /**
     * @param array<string,mixed> $params
     */
    public static function paramsHash(string $name, array $params): string
    {
        ksort($params);

        return hash('sha256', $name . '|' . json_encode($params));
    }

    /**
     * For tests: forget a running state left by a test that failed midway.
     */
    public static function resetState(): void
    {
        self::$running = false;
        self::$afterCommit = [];
    }

    private static function finish(object $conn, bool $commit): void
    {
        if ($commit) {
            $conn->commit();
        } else {
            $conn->rollBack();
            self::$afterCommit = [];
        }
        self::$running = false;
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private static function abort(object $conn, array $snapshot): void
    {
        global $session, $baseaccount, $companions;

        if ($conn->isTransactionActive()) {
            $conn->rollBack();
        }
        self::$running = false;
        self::$afterCommit = [];

        $session['user'] = $snapshot['user'];
        $session['bufflist'] = $snapshot['bufflist'];
        $session['allowednavs'] = $snapshot['allowednavs'];
        $companions = $snapshot['companions'];
        $baseaccount = $snapshot['baseaccount'];

        if (class_exists(Bootstrap::class)) {
            Bootstrap::getEntityManager()->clear();
        }
        Accounts::setAccountEntity(null);
    }

    /**
     * @return list<callable():void>
     */
    private static function takeAfterCommit(): array
    {
        $callbacks = self::$afterCommit;
        self::$afterCommit = [];

        return $callbacks;
    }
}
