<?php

declare(strict_types=1);

namespace Lotgd\Security;

use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;

/**
 * Persistence helper for passkey credentials used by the TwoFactorAuth module.
 */
class PasskeyCredentialRepository
{
    private const TABLE_NAME = 'twofactorauth_passkeys';

    /**
     * Table lifecycle is managed by core table sync (`install/data/tables.php`).
     *
     * This method is intentionally a no-op for module compatibility.
     */
    public function ensureTable(): void
    {
    }

    /**
     * Check whether the credential table exists so async handlers can fail gracefully.
     */
    public function hasCredentialTable(): bool
    {
        return Database::tableExists(Database::prefix(self::TABLE_NAME));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAccount(int $acctId): array
    {
        // Database readiness is delegated to the Database layer (including DB_NODB),
        // which prevents false negatives from legacy-wrapper-specific checks.
        $acctId = max(0, $acctId);
        $table = Database::prefix(self::TABLE_NAME);
        $result = Database::getDoctrineConnection()->executeQuery(
            "SELECT acctid, credential_id, credential_id_hash, public_key, sign_count, label, transports, created_at, last_used_at FROM {$table} WHERE acctid = :acctid ORDER BY created_at ASC",
            ['acctid' => $acctId],
            ['acctid' => ParameterType::INTEGER]
        );

        $items = [];
        while ($row = Database::fetchAssoc($result)) {
            $items[] = [
                'acctid' => (int) ($row['acctid'] ?? 0),
                'credential_id' => (string) ($row['credential_id'] ?? ''),
                'credential_id_hash' => (string) ($row['credential_id_hash'] ?? ''),
                'public_key' => (string) ($row['public_key'] ?? ''),
                'sign_count' => (int) ($row['sign_count'] ?? 0),
                'label' => (string) ($row['label'] ?? ''),
                'transports' => (string) ($row['transports'] ?? ''),
                'created_at' => (int) ($row['created_at'] ?? 0),
                'last_used_at' => (int) ($row['last_used_at'] ?? 0),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCredentialId(string $credentialId): ?array
    {
        $credentialId = trim($credentialId);
        $credentialIdHash = $this->credentialIdHash($credentialId);
        $table = Database::prefix(self::TABLE_NAME);
        $result = Database::getDoctrineConnection()->executeQuery(
            "SELECT acctid, credential_id, credential_id_hash, public_key, sign_count, label, transports, created_at, last_used_at FROM {$table} WHERE credential_id_hash = :credential_id_hash AND credential_id = :credential_id LIMIT 1",
            [
                'credential_id_hash' => $credentialIdHash,
                'credential_id' => $credentialId,
            ],
            [
                'credential_id_hash' => ParameterType::STRING,
                'credential_id' => ParameterType::STRING,
            ]
        );
        $row = Database::fetchAssoc($result);

        if (!is_array($row)) {
            return null;
        }

        return [
            'acctid' => (int) ($row['acctid'] ?? 0),
            'credential_id' => (string) ($row['credential_id'] ?? ''),
            'public_key' => (string) ($row['public_key'] ?? ''),
            'sign_count' => (int) ($row['sign_count'] ?? 0),
            'label' => (string) ($row['label'] ?? ''),
            'transports' => (string) ($row['transports'] ?? ''),
            'created_at' => (int) ($row['created_at'] ?? 0),
            'last_used_at' => (int) ($row['last_used_at'] ?? 0),
        ];
    }

    /**
     * Save a new credential.
     */
    public function insert(
        int $acctId,
        string $credentialId,
        string $publicKeyPem,
        int $signCount,
        string $label,
        string $transports,
        int $createdAt
    ): void {
        $table = Database::prefix(self::TABLE_NAME);
        $connection = Database::getDoctrineConnection();
        $acctId = max(0, $acctId);
        $credentialId = trim($credentialId);
        $credentialIdHash = $this->credentialIdHash($credentialId);
        $signCount = max(0, $signCount);
        $label = trim($label);
        $createdAt = max(0, $createdAt);

        $connection->executeStatement(
            "INSERT INTO {$table} (acctid, credential_id, credential_id_hash, public_key, sign_count, label, transports, created_at, last_used_at)"
            . ' VALUES (:acctid, :credential_id, :credential_id_hash, :public_key, :sign_count, :label, :transports, :created_at, :last_used_at)',
            [
                'acctid' => $acctId,
                'credential_id' => $credentialId,
                'credential_id_hash' => $credentialIdHash,
                'public_key' => $publicKeyPem,
                'sign_count' => $signCount,
                'label' => $label,
                'transports' => $transports,
                'created_at' => $createdAt,
                'last_used_at' => 0,
            ],
            [
                'acctid' => ParameterType::INTEGER,
                'credential_id' => ParameterType::STRING,
                'credential_id_hash' => ParameterType::STRING,
                'public_key' => ParameterType::STRING,
                'sign_count' => ParameterType::INTEGER,
                'label' => ParameterType::STRING,
                'transports' => ParameterType::STRING,
                'created_at' => ParameterType::INTEGER,
                'last_used_at' => ParameterType::INTEGER,
            ]
        );
    }

    /**
     * Update usage metadata for a known credential.
     */
    public function updateUsage(string $credentialId, int $signCount, int $lastUsedAt): void
    {
        $table = Database::prefix(self::TABLE_NAME);
        $credentialId = trim($credentialId);
        $credentialIdHash = $this->credentialIdHash($credentialId);
        $signCount = max(0, $signCount);
        $lastUsedAt = max(0, $lastUsedAt);

        Database::getDoctrineConnection()->executeStatement(
            "UPDATE {$table} SET sign_count = :sign_count, last_used_at = :last_used_at WHERE credential_id_hash = :credential_id_hash AND credential_id = :credential_id",
            [
                'sign_count' => $signCount,
                'last_used_at' => $lastUsedAt,
                'credential_id_hash' => $credentialIdHash,
                'credential_id' => $credentialId,
            ],
            [
                'sign_count' => ParameterType::INTEGER,
                'last_used_at' => ParameterType::INTEGER,
                'credential_id_hash' => ParameterType::STRING,
                'credential_id' => ParameterType::STRING,
            ]
        );
    }

    /**
     * Delete a credential for a specific account.
     */
    public function deleteForAccount(int $acctId, string $credentialId): bool
    {
        $table = Database::prefix(self::TABLE_NAME);
        $connection = Database::getDoctrineConnection();
        $acctId = max(0, $acctId);
        $credentialId = trim($credentialId);
        $credentialIdHash = $this->credentialIdHash($credentialId);
        $affectedRows = $connection->executeStatement(
            "DELETE FROM {$table} WHERE acctid = :acctid AND credential_id_hash = :credential_id_hash AND credential_id = :credential_id",
            [
                'acctid' => $acctId,
                'credential_id_hash' => $credentialIdHash,
                'credential_id' => $credentialId,
            ],
            [
                'acctid' => ParameterType::INTEGER,
                'credential_id_hash' => ParameterType::STRING,
                'credential_id' => ParameterType::STRING,
            ]
        );

        return $affectedRows > 0;
    }

    /**
     * Table lifecycle is managed by core table sync (`install/data/tables.php`).
     *
     * This method is intentionally a no-op for module compatibility.
     */
    public function dropTable(): void
    {
    }


    /**
     * Build a stable lookup hash for potentially long credential IDs.
     */
    private function credentialIdHash(string $credentialId): string
    {
        return hash('sha256', $credentialId);
    }
}
