<?php

declare(strict_types=1);

namespace Lotgd\Security;

/**
 * Persistence helper for passkey credentials used by the TwoFactorAuth module.
 */
class PasskeyCredentialRepository
{
    private const TABLE_NAME = 'twofactorauth_passkeys';

    /**
     * Ensure the passkey credential table exists.
     */
    public function ensureTable(): void
    {
        if (!$this->hasDb()) {
            return;
        }
        $table = LegacyBridge::dbPrefix(self::TABLE_NAME);
        LegacyBridge::dbQuery(
            "CREATE TABLE IF NOT EXISTS {$table} ("
            . 'acctid INT NOT NULL,'
            . 'credential_id VARCHAR(255) NOT NULL,'
            . 'public_key TEXT NOT NULL,'
            . 'sign_count BIGINT NOT NULL DEFAULT 0,'
            . 'label VARCHAR(255) NOT NULL DEFAULT \"\",'
            . 'transports VARCHAR(255) NOT NULL DEFAULT \"\",'
            . 'created_at INT NOT NULL,'
            . 'last_used_at INT NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (credential_id),'
            . 'KEY idx_passkey_acctid (acctid)'
            . ')'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAccount(int $acctId): array
    {
        if (!$this->hasDb()) {
            return [];
        }

        $acctId = max(0, $acctId);
        $table = LegacyBridge::dbPrefix(self::TABLE_NAME);
        $result = LegacyBridge::dbQuery("SELECT acctid, credential_id, public_key, sign_count, label, transports, created_at, last_used_at FROM {$table} WHERE acctid = {$acctId} ORDER BY created_at ASC");

        $items = [];
        while ($row = LegacyBridge::dbFetchAssoc($result)) {
            $items[] = [
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

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCredentialId(string $credentialId): ?array
    {
        if (!$this->hasDb()) {
            return null;
        }

        $credentialId = LegacyBridge::dbEscape($credentialId);
        $table = LegacyBridge::dbPrefix(self::TABLE_NAME);
        $result = LegacyBridge::dbQuery("SELECT acctid, credential_id, public_key, sign_count, label, transports, created_at, last_used_at FROM {$table} WHERE credential_id = '{$credentialId}' LIMIT 1");
        $row = LegacyBridge::dbFetchAssoc($result);

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
        if (!$this->hasDb()) {
            return;
        }

        $table = LegacyBridge::dbPrefix(self::TABLE_NAME);
        $acctId = max(0, $acctId);
        $credentialId = LegacyBridge::dbEscape($credentialId);
        $publicKeyPem = LegacyBridge::dbEscape($publicKeyPem);
        $signCount = max(0, $signCount);
        $label = LegacyBridge::dbEscape(trim($label));
        $transports = LegacyBridge::dbEscape($transports);
        $createdAt = max(0, $createdAt);

        LegacyBridge::dbQuery(
            "INSERT INTO {$table} (acctid, credential_id, public_key, sign_count, label, transports, created_at, last_used_at)"
            . " VALUES ({$acctId}, '{$credentialId}', '{$publicKeyPem}', {$signCount}, '{$label}', '{$transports}', {$createdAt}, 0)"
        );
    }

    /**
     * Update usage metadata for a known credential.
     */
    public function updateUsage(string $credentialId, int $signCount, int $lastUsedAt): void
    {
        if (!$this->hasDb()) {
            return;
        }

        $table = LegacyBridge::dbPrefix(self::TABLE_NAME);
        $credentialId = LegacyBridge::dbEscape($credentialId);
        $signCount = max(0, $signCount);
        $lastUsedAt = max(0, $lastUsedAt);

        LegacyBridge::dbQuery("UPDATE {$table} SET sign_count = {$signCount}, last_used_at = {$lastUsedAt} WHERE credential_id = '{$credentialId}'");
    }

    /**
     * Delete a credential for a specific account.
     */
    public function deleteForAccount(int $acctId, string $credentialId): bool
    {
        if (!$this->hasDb()) {
            return false;
        }

        $table = LegacyBridge::dbPrefix(self::TABLE_NAME);
        $acctId = max(0, $acctId);
        $credentialId = LegacyBridge::dbEscape($credentialId);
        LegacyBridge::dbQuery("DELETE FROM {$table} WHERE acctid = {$acctId} AND credential_id = '{$credentialId}'");

        return LegacyBridge::dbAffectedRows() > 0;
    }

    /**
     * Drop passkey table during uninstall.
     */
    public function dropTable(): void
    {
        if (!$this->hasDb()) {
            return;
        }

        $table = LegacyBridge::dbPrefix(self::TABLE_NAME);
        LegacyBridge::dbQuery("DROP TABLE IF EXISTS {$table}");
    }

    /**
     * Guard for isolated test contexts where DB legacy wrappers are not loaded.
     */
    private function hasDb(): bool
    {
        return LegacyBridge::hasDatabaseApi();
    }
}
