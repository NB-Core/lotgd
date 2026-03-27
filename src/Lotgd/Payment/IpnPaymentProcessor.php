<?php

declare(strict_types=1);

namespace Lotgd\Payment;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Throwable;

/**
 * Handles database-side IPN persistence and crediting in a testable, stepwise workflow.
 *
 *
 * Legacy duplicate handling policy:
 * - Canonical row is the *oldest* paylog row for a txnid (`MIN(payid)`).
 * - Only the canonical row may be credited.
 * - Crediting is gated by a transactional claim on `processed = 0`.
 * - Credit and `processed=1` are guarded in one transaction using a conditional
 *   `UPDATE ... WHERE payid = :payid AND processed = 0`, which emulates row-lock
 *   claim semantics on platforms without portable `SELECT ... FOR UPDATE` support.
 */
final class IpnPaymentProcessor
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $accountsTable,
        private readonly string $paylogTable
    ) {
    }

    /**
     * Process a single verified PayPal IPN message.
     *
     * @param array<string, mixed> $post Raw posted payload that is persisted to paylog.info.
     * @param array<string, mixed> $payload Normalized runtime values used for processing.
     * @param callable             $adjustDonation Hook-like callback: fn (array $input): array{points:mixed,messages:mixed}
     */
    public function processVerifiedPayment(array $post, array $payload, callable $adjustDonation): IpnProcessingResult
    {
        $result = new IpnProcessingResult();
        $result->donationAmount = $this->calculateDonationAmount(
            (float) ($payload['paymentAmount'] ?? 0.0),
            (float) ($payload['paymentFee'] ?? 0.0),
            (string) ($payload['txnType'] ?? '')
        );
        $result->accountLogin = $this->extractAccountLogin((string) ($payload['itemNumber'] ?? ''));
        $txnid = (string) ($payload['txnId'] ?? '');

        if (! $this->hasValidTransactionId($txnid, $result)) {
            return $result;
        }

        if (! $this->insertPaylogIfNew($post, $payload, $result)) {
            return $result;
        }

        $result->accountId = $this->resolveAccountId($result->accountLogin, $result);

        // No account match: keep the inserted paylog row as processed=0 and return safely.
        if ($result->accountId <= 0) {
            return $result;
        }
        $this->updatePaylogAccountId($result);

        $canonicalRow = $this->resolveCanonicalPaylogRow($txnid, $result);
        if ($canonicalRow === null) {
            return $result;
        }

        // Legacy-safe idempotency policy:
        // only the instance that inserted the canonical paylog row may perform crediting.
        if ($result->paylogId !== $canonicalRow['payid']) {
            $result->duplicateTransaction = true;
            $result->warnings[] = sprintf(
                'Skipped non-canonical paylog row for txnid (%s); canonical payid is %d.',
                $txnid,
                $canonicalRow['payid']
            );
            return $result;
        }

        if ($canonicalRow['processed'] !== 0) {
            $result->duplicateTransaction = true;
            $result->warnings[] = sprintf('Transaction (%s) was already processed.', $txnid);
            return $result;
        }

        $pointsPerCurrencyUnit = (float) ($payload['pointsPerCurrencyUnit'] ?? 0.0);
        $adjusted = $adjustDonation([
            'points' => $result->donationAmount * $pointsPerCurrencyUnit,
            'amount' => $result->donationAmount,
            'acctid' => $result->accountId,
            'messages' => [],
        ]);

        $points = (int) round((float) ($adjusted['points'] ?? 0.0));
        $result->creditedPoints = $points;

        $messages = $adjusted['messages'] ?? [];
        if (! is_array($messages)) {
            $messages = [$messages];
        }
        foreach ($messages as $message) {
            if (is_string($message) && $message !== '') {
                $result->debugMessages[] = $message;
            }
        }

        $this->creditCanonicalRowInGuardedFlow($result, $points, $txnid);

        return $result;
    }

    /**
     * Convert a legacy item number (`login:anything`) into a login key.
     */
    public function extractAccountLogin(string $itemNumber): string
    {
        if (! str_contains($itemNumber, ':')) {
            // Preserve legacy behavior: only login:item payloads are eligible for account resolution.
            return '';
        }

        $parts = explode(':', $itemNumber, 2);
        return trim($parts[0] ?? '');
    }

    /**
     * Determine whether an exception code/state maps to a duplicate-key conflict.
     */
    public function isDuplicateTransactionError(Throwable $exception): bool
    {
        $code = (string) $exception->getCode();

        if ($code === '1062' || $code === '23505') {
            return true;
        }

        $message = strtolower($exception->getMessage());

        if ($code === '23000') {
            return str_contains($message, 'duplicate')
                || str_contains($message, 'unique constraint')
                || str_contains($message, 'unique violation');
        }

        return false;
    }

    /**
     * Validate the transaction identifier; an empty txnid is never eligible for crediting.
     */
    private function hasValidTransactionId(string $txnid, IpnProcessingResult $result): bool
    {
        if ($txnid === '') {
            $result->errors[] = 'Payment payload has an empty transaction ID.';
            return false;
        }

        return true;
    }

    private function resolveAccountId(string $accountLogin, IpnProcessingResult $result): int
    {
        if ($accountLogin === '') {
            return 0;
        }

        try {
            $row = $this->connection->fetchAssociative(
                "SELECT acctid FROM {$this->accountsTable} WHERE login = :login",
                ['login' => $accountLogin],
                ['login' => ParameterType::STRING]
            );
        } catch (Throwable $exception) {
            $result->errors[] = 'Failed to resolve donation account: ' . $exception->getMessage();
            return 0;
        }

        if (! is_array($row)) {
            return 0;
        }

        return (int) ($row['acctid'] ?? 0);
    }

    /**
     * Persist paylog with a single-statement, best-effort "insert-if-not-exists" guard for auditability.
     *
     * This method intentionally does not fail hard when the row already exists because
     * legacy datasets may already contain duplicate txnid rows; canonical-row
     * validation and guarded claim checks are applied later before any crediting is attempted.
     *
     * Note: without a DB-level unique constraint on txnid, this guard does not fully
     * prevent concurrent duplicate inserts; canonical claim logic is the true idempotency gate.
     *
     * When a duplicate txnid is detected, this method resolves and stores the canonical
     * payid so downstream guarded processing can still claim/process resumable rows.
     */
    private function insertPaylogIfNew(array $post, array $payload, IpnProcessingResult $result): bool
    {
        $txnid = (string) ($payload['txnId'] ?? '');

        try {
            $inserted = $this->connection->executeStatement(
                "INSERT INTO {$this->paylogTable} (
                    info,
                    response,
                    txnid,
                    amount,
                    name,
                    acctid,
                    processed,
                    filed,
                    txfee,
                    processdate
                ) SELECT
                    :info,
                    :response,
                    :txnid,
                    :amount,
                    :name,
                    :acctid,
                    :processed,
                    :filed,
                    :txfee,
                    :processdate
                WHERE 1 = 1
                AND NOT EXISTS (
                    SELECT 1 FROM {$this->paylogTable} WHERE txnid = :txnid_check
                )",
                [
                    'info' => serialize($post),
                    'response' => (string) ($payload['response'] ?? ''),
                    'txnid' => $txnid,
                    'amount' => (string) ($payload['paymentAmount'] ?? ''),
                    'name' => $result->accountLogin,
                    // acctid is resolved after the paylog insert to preserve atomic duplicate gating.
                    'acctid' => 0,
                    'processed' => 0,
                    'filed' => 0,
                    'txfee' => (string) ($payload['paymentFee'] ?? ''),
                    'processdate' => (string) ($payload['processDate'] ?? date('Y-m-d H:i:s')),
                    'txnid_check' => $txnid,
                ],
                [
                    'info' => ParameterType::STRING,
                    'response' => ParameterType::STRING,
                    'txnid' => ParameterType::STRING,
                    'amount' => ParameterType::STRING,
                    'name' => ParameterType::STRING,
                    'acctid' => ParameterType::INTEGER,
                    'processed' => ParameterType::INTEGER,
                    'filed' => ParameterType::INTEGER,
                    'txfee' => ParameterType::STRING,
                    'processdate' => ParameterType::STRING,
                    'txnid_check' => ParameterType::STRING,
                ]
            );
            if ($inserted === 0) {
                $result->duplicateTransaction = true;
                $result->warnings[] = sprintf('Already logged this transaction ID (%s)', $txnid);
                $result->paylogId = $this->resolveCanonicalPaylogId($txnid, $result);
                return true;
            }
            $result->paylogInserted = true;
            $result->paylogId = (int) $this->connection->lastInsertId();
            return true;
        } catch (Throwable $exception) {
            if ($this->isDuplicateTransactionError($exception)) {
                $result->duplicateTransaction = true;
                $result->warnings[] = sprintf('Already logged this transaction ID (%s)', $txnid);
                $result->paylogId = $this->resolveCanonicalPaylogId($txnid, $result);
                return true;
            }

            $result->errors[] = 'Failed to persist payment log: ' . $exception->getMessage();
            return false;
        }
    }

    /**
     * Resolve the canonical paylog identifier for an already-existing transaction.
     *
     * Canonical policy is always `MIN(payid)` so retries can safely resume against
     * the same deterministic row even when legacy duplicate txnid rows exist.
     */
    private function resolveCanonicalPaylogId(string $txnid, IpnProcessingResult $result): int
    {
        try {
            $row = $this->connection->fetchAssociative(
                "SELECT MIN(payid) AS payid FROM {$this->paylogTable} WHERE txnid = :txnid",
                ['txnid' => $txnid],
                ['txnid' => ParameterType::STRING]
            );
        } catch (Throwable $exception) {
            $result->errors[] = 'Failed to resolve existing paylog row: ' . $exception->getMessage();
            return 0;
        }

        return (int) ($row['payid'] ?? 0);
    }

    /**
     * Persist resolved account ID into paylog even when crediting fails.
     */
    private function updatePaylogAccountId(IpnProcessingResult $result): void
    {
        if ($result->paylogId <= 0 || $result->accountId <= 0) {
            return;
        }

        try {
            $this->connection->executeStatement(
                "UPDATE {$this->paylogTable} SET acctid = :acctid WHERE payid = :payid",
                [
                    'acctid' => $result->accountId,
                    'payid' => $result->paylogId,
                ],
                [
                    'acctid' => ParameterType::INTEGER,
                    'payid' => ParameterType::INTEGER,
                ]
            );
        } catch (Throwable $exception) {
            $result->errors[] = 'Failed to update paylog account mapping: ' . $exception->getMessage();
        }
    }

    /**
     * Resolve the canonical paylog row for a transaction ID.
     *
     * Policy: canonical = `MIN(payid)` to preserve legacy-first processing order.
     * This deterministic choice prevents older duplicate rows from being bypassed.
     *
     * @return array{payid:int,processed:int}|null
     */
    private function resolveCanonicalPaylogRow(string $txnid, IpnProcessingResult $result): ?array
    {
        try {
            $row = $this->connection->fetchAssociative(
                "SELECT payid, processed
                 FROM {$this->paylogTable}
                 WHERE payid = (
                    SELECT MIN(payid)
                    FROM {$this->paylogTable}
                    WHERE txnid = :txnid
                 )",
                ['txnid' => $txnid],
                ['txnid' => ParameterType::STRING]
            );
        } catch (Throwable $exception) {
            $result->errors[] = 'Failed to resolve canonical paylog row: ' . $exception->getMessage();
            return null;
        }

        if (! is_array($row)) {
            $result->errors[] = sprintf('Missing paylog row for transaction ID (%s).', $txnid);
            return null;
        }

        return [
            'payid' => (int) ($row['payid'] ?? 0),
            'processed' => (int) ($row['processed'] ?? 0),
        ];
    }

    /**
     * Execute donation crediting in one guarded transaction.
     *
     * Guard order:
     * 1) claim canonical paylog row via `processed = 0` conditional update,
     * 2) credit account donation points,
     * 3) commit both changes together.
     *
     * The conditional claim emulates row-lock ownership on engines where portable
     * `SELECT ... FOR UPDATE` is not available.
     */
    private function creditCanonicalRowInGuardedFlow(IpnProcessingResult $result, int $points, string $txnid): void
    {
        if ($result->paylogId <= 0 || $result->accountId <= 0) {
            return;
        }

        try {
            $this->connection->beginTransaction();

            $claimed = $this->connection->executeStatement(
                "UPDATE {$this->paylogTable}
                 SET processed = :processed
                 WHERE payid = :payid
                 AND processed = :unprocessed",
                [
                    'processed' => 1,
                    'payid' => $result->paylogId,
                    'unprocessed' => 0,
                ],
                [
                    'processed' => ParameterType::INTEGER,
                    'payid' => ParameterType::INTEGER,
                    'unprocessed' => ParameterType::INTEGER,
                ]
            );

            if ($claimed === 0) {
                $this->connection->rollBack();
                $result->duplicateTransaction = true;
                $result->warnings[] = sprintf('Transaction (%s) was already processed.', $txnid);
                return;
            }

            $affected = $this->connection->executeStatement(
                "UPDATE {$this->accountsTable} SET donation = donation + :points WHERE acctid = :acctid",
                [
                    'points' => $points,
                    'acctid' => $result->accountId,
                ],
                [
                    'points' => ParameterType::INTEGER,
                    'acctid' => ParameterType::INTEGER,
                ]
            );

            if ($affected <= 0) {
                $this->connection->rollBack();
                return;
            }

            $this->connection->commit();
            $result->processed = 1;
            $result->credited = true;
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $result->errors[] = 'Failed to credit donation points: ' . $exception->getMessage();
        }
    }

    /**
     * Apply legacy reversal adjustment to donation amount used for point crediting.
     */
    private function calculateDonationAmount(float $amount, float $paymentFee, string $txnType): float
    {
        if ($txnType === 'reversal') {
            return $amount - $paymentFee;
        }

        return $amount;
    }
}
