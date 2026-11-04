<?php

declare(strict_types=1);

namespace Lotgd\Realtime;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\ParameterType;
use Lotgd\Action;
use Lotgd\MySQL\Database;

/**
 * Hands committed events their delivery number.
 *
 * One transaction: the row of relay_sequence is locked first and stays locked
 * until commit, so relays run one after the other however many there are --
 * no number is handed out twice, none is skipped. Events are taken in the
 * order they were written; one whose transaction has not committed yet is not
 * visible and is taken by a later run. The delivery order is the relay's
 * order, not the commit order, so a client reading by number never jumps over
 * an event that committed late.
 */
final class Relay
{
    /**
     * @return int How many events were relayed.
     */
    public static function run(int $limit = 100): int
    {
        if (Action::isRunning()) {
            throw new \LogicException('The relay runs in a transaction of its own, never inside a guarded action.');
        }

        $conn = Database::getDoctrineConnection();
        if ($conn->isTransactionActive()) {
            throw new \LogicException('The relay runs in a transaction of its own.');
        }

        $sequence = Database::prefix('relay_sequence');
        $events = Database::prefix('events');
        $deliveries = Database::prefix('event_deliveries');

        $conn->beginTransaction();
        try {
            $next = $conn->fetchOne("SELECT next_seq FROM {$sequence} WHERE id = 1 FOR UPDATE");
            if ($next === false || $next === null) {
                // An installation whose sequence row went missing starts over
                // after the highest number delivered.
                $conn->executeStatement("INSERT IGNORE INTO {$sequence} (id, next_seq) SELECT 1, COALESCE(MAX(seq), 0) + 1 FROM {$deliveries}");
                $next = $conn->fetchOne("SELECT next_seq FROM {$sequence} WHERE id = 1 FOR UPDATE");
            }
            $next = (int) $next;

            // A plain read, taken after the sequence lock: it sees every event
            // committed by now and every earlier relay's relayed_at, and it
            // does not wait for an action that is still running -- that one's
            // events are taken by a later run. Only relays write relayed_at,
            // and they queue on the sequence row.
            $pending = $conn->fetchAllAssociative(
                "SELECT id, event_id, channel, type, payload FROM {$events}"
                . ' WHERE relayed_at IS NULL ORDER BY id LIMIT ' . max(1, $limit)
            );
            if ($pending === []) {
                $conn->commit();

                return 0;
            }

            $now = date('Y-m-d H:i:s');
            $seq = $next;
            foreach ($pending as $event) {
                $conn->executeStatement(
                    "INSERT INTO {$deliveries} (seq, event_id, channel, type, payload, created)"
                    . ' VALUES (:seq, :event_id, :channel, :type, :payload, :created)',
                    [
                        'seq' => $seq,
                        'event_id' => (string) $event['event_id'],
                        'channel' => (string) $event['channel'],
                        'type' => (string) $event['type'],
                        'payload' => (string) $event['payload'],
                        'created' => $now,
                    ],
                    ['seq' => ParameterType::INTEGER]
                );
                $conn->executeStatement(
                    "UPDATE {$events} SET relayed_at = :now WHERE id = :id",
                    ['now' => $now, 'id' => (int) $event['id']],
                    ['id' => ParameterType::INTEGER]
                );
                $seq++;
            }
            $conn->executeStatement(
                "UPDATE {$sequence} SET next_seq = :next WHERE id = 1",
                ['next' => $seq],
                ['next' => ParameterType::INTEGER]
            );
            $conn->commit();

            return count($pending);
        } catch (RetryableException $contention) {
            // Another relay held the sequence too long; cron.php or the next
            // change takes these events.
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            return 0;
        } catch (\Throwable $error) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $error;
        }
    }

    /**
     * Relay until nothing is left, for cron.php.
     */
    public static function drain(int $rounds = 50): int
    {
        $total = 0;
        for ($round = 0; $round < $rounds; $round++) {
            $relayed = self::run();
            $total += $relayed;
            if ($relayed === 0) {
                break;
            }
        }

        return $total;
    }
}
