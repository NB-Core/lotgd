<?php

declare(strict_types=1);

namespace Lotgd\Payment;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Throwable;

/**
 * Handles database-side IPN persistence and crediting in an atomic, testable workflow.
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

        $result->accountId = $this->resolveAccountId($result->accountLogin, $result);

        if ($this->isKnownTransaction($txnid, $result)) {
            return $result;
        }

        if (! $this->insertPaylog($post, $payload, $result)) {
            return $result;
        }

        // No account match: keep the inserted paylog row as processed=0 and return safely.
        if ($result->accountId <= 0) {
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

        try {
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
        } catch (Throwable $exception) {
            $result->errors[] = 'Failed to credit donation points: ' . $exception->getMessage();
            return $result;
        }

        if ($affected > 0) {
            $result->processed = 1;
            $result->credited = true;
            $this->updatePaylogProcessedState((string) ($payload['txnId'] ?? ''), $result);
        }

        return $result;
    }

    /**
     * Convert a legacy item number (`login:anything`) into a login key.
     */
    public function extractAccountLogin(string $itemNumber): string
    {
        $parts = explode(':', $itemNumber, 2);

        return trim($parts[0] ?? '');
    }

    /**
     * Determine whether an exception code/state maps to a duplicate-key conflict.
     */
    public function isDuplicateTransactionError(Throwable $exception): bool
    {
        $code = (string) $exception->getCode();

        if ($code === '1062' || $code === '23000' || $code === '23505') {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'unique violation');
    }

    /**
     * Compare txnid against existing paylog rows to avoid re-processing known transactions.
     */
    private function isKnownTransaction(string $txnid, IpnProcessingResult $result): bool
    {
        if ($txnid === '') {
            $result->errors[] = 'Payment payload has an empty transaction ID.';
            return true;
        }

        try {
            $existing = $this->connection->fetchAssociative(
                "SELECT txnid FROM {$this->paylogTable} WHERE txnid = :txnid",
                ['txnid' => $txnid],
                ['txnid' => ParameterType::STRING]
            );
        } catch (Throwable $exception) {
            $result->errors[] = 'Failed to verify transaction duplication: ' . $exception->getMessage();
            return true;
        }

        if ($existing !== false) {
            $result->duplicateTransaction = true;
            $result->warnings[] = sprintf('Already logged this transaction ID (%s)', $txnid);
            return true;
        }

        return false;
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
     * Persist initial paylog row after txnid compare-check passes.
     */
    private function insertPaylog(array $post, array $payload, IpnProcessingResult $result): bool
    {
        $txnid = (string) ($payload['txnId'] ?? '');

        try {
            $this->connection->executeStatement(
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
                ) VALUES (
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
                )",
                [
                    'info' => serialize($post),
                    'response' => (string) ($payload['response'] ?? ''),
                    'txnid' => $txnid,
                    'amount' => (string) ($payload['paymentAmount'] ?? ''),
                    'name' => $result->accountLogin,
                    'acctid' => $result->accountId,
                    'processed' => 0,
                    'filed' => 0,
                    'txfee' => (string) ($payload['paymentFee'] ?? ''),
                    'processdate' => (string) ($payload['processDate'] ?? date('Y-m-d H:i:s')),
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
                ]
            );
            $result->paylogInserted = true;
            return true;
        } catch (Throwable $exception) {
            if ($this->isDuplicateTransactionError($exception)) {
                $result->duplicateTransaction = true;
                $result->warnings[] = sprintf('Already logged this transaction ID (%s)', $txnid);
                return false;
            }

            $result->errors[] = 'Failed to persist payment log: ' . $exception->getMessage();
            return false;
        }
    }

    /**
     * Mark paylog row as processed once donation points are successfully credited.
     */
    private function updatePaylogProcessedState(string $txnid, IpnProcessingResult $result): void
    {
        try {
            $this->connection->executeStatement(
                "UPDATE {$this->paylogTable} SET processed = :processed WHERE txnid = :txnid",
                [
                    'processed' => 1,
                    'txnid' => $txnid,
                ],
                [
                    'processed' => ParameterType::INTEGER,
                    'txnid' => ParameterType::STRING,
                ]
            );
        } catch (Throwable $exception) {
            $result->errors[] = 'Failed to update paylog processed state: ' . $exception->getMessage();
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
