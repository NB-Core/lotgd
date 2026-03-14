<?php

declare(strict_types=1);

namespace Lotgd\Security;

if (!function_exists(__NAMESPACE__ . '\\getsetting')) {
    function getsetting(string $name, mixed $default = null): mixed
    {
        if ($name === 'serverurl') {
            return 'https://example.test';
        }

        if ($name === 'gameadminemail') {
            return 'Legend of the Green Dragon';
        }

        return $default;
    }
}

namespace Lotgd\Tests\Security;

use Lotgd\Security\PasskeyCredentialRepository;
use Lotgd\Security\PasskeyService;
use PHPUnit\Framework\TestCase;

/**
 * In-memory repository stub for passkey flow tests.
 */
class InMemoryPasskeyCredentialRepository extends PasskeyCredentialRepository
{
    /** @var array<string, array<string, mixed>> */
    public array $items = [];

    public function ensureTable(): void
    {
    }

    public function dropTable(): void
    {
        $this->items = [];
    }

    public function listForAccount(int $acctId): array
    {
        return array_values(array_filter($this->items, static fn(array $item): bool => (int) $item['acctid'] === $acctId));
    }

    public function findByCredentialId(string $credentialId): ?array
    {
        return $this->items[$credentialId] ?? null;
    }

    public function insert(
        int $acctId,
        string $credentialId,
        string $publicKeyPem,
        int $signCount,
        string $label,
        string $transports,
        int $createdAt
    ): void {
        $this->items[$credentialId] = [
            'acctid' => $acctId,
            'credential_id' => $credentialId,
            'public_key' => $publicKeyPem,
            'sign_count' => $signCount,
            'label' => $label,
            'transports' => $transports,
            'created_at' => $createdAt,
            'last_used_at' => 0,
        ];
    }

    public function updateUsage(string $credentialId, int $signCount, int $lastUsedAt): void
    {
        if (isset($this->items[$credentialId])) {
            $this->items[$credentialId]['sign_count'] = $signCount;
            $this->items[$credentialId]['last_used_at'] = $lastUsedAt;
        }
    }

    public function deleteForAccount(int $acctId, string $credentialId): bool
    {
        if (!isset($this->items[$credentialId]) || (int) $this->items[$credentialId]['acctid'] !== $acctId) {
            return false;
        }

        unset($this->items[$credentialId]);

        return true;
    }
}

class PasskeyServiceTest extends TestCase
{
    private InMemoryPasskeyCredentialRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryPasskeyCredentialRepository();
        $GLOBALS['session'] = [];
    }

    public function testAuthenticationChallengeIsBoundToAccount(): void
    {
        $service = new PasskeyService($this->repo);
        $service->beginAuthentication(100, []);

        $result = $service->finishAuthentication(200, []);

        self::assertFalse($result['ok']);
        self::assertSame('challenge_missing', $result['error']);
    }

    public function testAuthenticationFailsWhenCredentialIsNotOwnedByAccount(): void
    {
        $service = new PasskeyService($this->repo);

        $this->repo->insert(100, 'credential-owned-by-100', 'pem', 1, 'Device', 'internal', time());
        $service->beginAuthentication(200, []);

        $result = $service->finishAuthentication(200, [
            'id' => 'credential-owned-by-100',
            'response' => [
                'clientDataJSON' => 'abc',
                'authenticatorData' => 'abc',
                'signature' => 'abc',
            ],
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('credential_not_owned', $result['error']);
    }

    public function testDeleteForAccountEnforcesOwnership(): void
    {
        $this->repo->insert(100, 'credential-a', 'pem', 0, 'A', 'internal', time());

        self::assertFalse($this->repo->deleteForAccount(200, 'credential-a'));
        self::assertTrue($this->repo->deleteForAccount(100, 'credential-a'));
    }
}
