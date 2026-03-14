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
     * Table lifecycle is managed by core table sync (`install/data/tables.php`).
     *
     * This method is intentionally a no-op for module compatibility.
     */
    public function ensureTable(): void
    {
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
        $result = LegacyBridge::dbQuery("SELECT acctid, credential_id, credential_id_hash, public_key, sign_count, label, transports, created_at, last_used_at FROM {$table} WHERE acctid = {$acctId} ORDER BY created_at ASC");

        $items = [];
        while ($row = LegacyBridge::dbFetchAssoc($result)) {
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
        if (!$this->hasDb()) {
            return null;
        }

        $credentialId = trim($credentialId);
        $credentialIdEscaped = LegacyBridge::dbEscape($credentialId);
        $credentialIdHashEscaped = LegacyBridge::dbEscape($this->credentialIdHash($credentialId));
        $table = LegacyBridge::dbPrefix(self::TABLE_NAME);
        $result = LegacyBridge::dbQuery("SELECT acctid, credential_id, credential_id_hash, public_key, sign_count, label, transports, created_at, last_used_at FROM {$table} WHERE credential_id_hash = '{$credentialIdHashEscaped}' AND credential_id = '{$credentialIdEscaped}' LIMIT 1");
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
        $credentialId = trim($credentialId);
        $credentialIdEscaped = LegacyBridge::dbEscape($credentialId);
        $credentialIdHashEscaped = LegacyBridge::dbEscape($this->credentialIdHash($credentialId));
        $publicKeyPem = LegacyBridge::dbEscape($publicKeyPem);
        $signCount = max(0, $signCount);
        $label = LegacyBridge::dbEscape(trim($label));
        $transports = LegacyBridge::dbEscape($transports);
        $createdAt = max(0, $createdAt);

        LegacyBridge::dbQuery(
            "INSERT INTO {$table} (acctid, credential_id, credential_id_hash, public_key, sign_count, label, transports, created_at, last_used_at)"
            . " VALUES ({$acctId}, '{$credentialIdEscaped}', '{$credentialIdHashEscaped}', '{$publicKeyPem}', {$signCount}, '{$label}', '{$transports}', {$createdAt}, 0)"
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
        $credentialId = trim($credentialId);
        $credentialIdEscaped = LegacyBridge::dbEscape($credentialId);
        $credentialIdHashEscaped = LegacyBridge::dbEscape($this->credentialIdHash($credentialId));
        $signCount = max(0, $signCount);
        $lastUsedAt = max(0, $lastUsedAt);

        LegacyBridge::dbQuery("UPDATE {$table} SET sign_count = {$signCount}, last_used_at = {$lastUsedAt} WHERE credential_id_hash = '{$credentialIdHashEscaped}' AND credential_id = '{$credentialIdEscaped}'");
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
        $credentialId = trim($credentialId);
        $credentialIdEscaped = LegacyBridge::dbEscape($credentialId);
        $credentialIdHashEscaped = LegacyBridge::dbEscape($this->credentialIdHash($credentialId));
        LegacyBridge::dbQuery("DELETE FROM {$table} WHERE acctid = {$acctId} AND credential_id_hash = '{$credentialIdHashEscaped}' AND credential_id = '{$credentialIdEscaped}'");

        return LegacyBridge::dbAffectedRows() > 0;
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

    /**
     * Guard for isolated test contexts where DB legacy wrappers are not loaded.
     */
    private function hasDb(): bool
    {
        return LegacyBridge::hasDatabaseApi();
    }
}
