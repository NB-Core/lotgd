<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Security\PasskeyCredentialRepository;
use Lotgd\Security\PasskeyService;
use Lotgd\Settings;
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

        $settings = $this->createMock(Settings::class);
        $settings->method('getSetting')->willReturnCallback(static function (string $name, mixed $default = false): mixed {
            return match ($name) {
                'serverurl' => 'https://example.test',
                'serverdesc' => 'Legend of the Green Dragon Test Realm',
                default => $default,
            };
        });

        Settings::setInstance($settings);
        $GLOBALS['settings'] = $settings;
    }

    protected function tearDown(): void
    {
        Settings::setInstance(null);
        unset($GLOBALS['settings']);

        parent::tearDown();
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

    public function testWrongAccountAttemptDoesNotConsumeAuthenticationChallenge(): void
    {
        $service = new PasskeyService($this->repo);
        $service->beginAuthentication(100, []);

        $wrongAccountResult = $service->finishAuthentication(200, []);
        self::assertFalse($wrongAccountResult['ok']);
        self::assertSame('challenge_missing', $wrongAccountResult['error']);

        $correctAccountResult = $service->finishAuthentication(100, []);
        self::assertFalse($correctAccountResult['ok']);
        self::assertSame('payload_invalid', $correctAccountResult['error']);
    }

    public function testWrongCeremonyTypeDoesNotConsumeStoredChallenge(): void
    {
        $service = new PasskeyService($this->repo);
        $service->beginAuthentication(100, []);

        $wrongTypeResult = $service->finishRegistration(100, [], 'Device');
        self::assertFalse($wrongTypeResult['ok']);
        self::assertSame('challenge_missing', $wrongTypeResult['error']);

        $authResult = $service->finishAuthentication(100, []);
        self::assertFalse($authResult['ok']);
        self::assertSame('payload_invalid', $authResult['error']);
    }

    public function testExpiredAuthenticationChallengeIsRejected(): void
    {
        $service = new PasskeyService($this->repo);
        $service->beginAuthentication(100, []);

        $GLOBALS['session']['twofactorauth_passkey_challenge_auth']['expires_at'] = time() - 1;

        $result = $service->finishAuthentication(100, []);

        self::assertFalse($result['ok']);
        self::assertSame('challenge_missing', $result['error']);
    }

    public function testWrongAccountFinishRegistrationDoesNotConsumeStoredChallenge(): void
    {
        $service = new PasskeyService($this->repo);
        $service->beginRegistration(100, 'tester', 'Tester', []);

        $wrongAccountResult = $service->finishRegistration(200, [], 'Device');
        self::assertFalse($wrongAccountResult['ok']);
        self::assertSame('challenge_missing', $wrongAccountResult['error']);

        $correctAccountResult = $service->finishRegistration(100, [], 'Device');
        self::assertFalse($correctAccountResult['ok']);
        self::assertSame('payload_invalid', $correctAccountResult['error']);
    }

    public function testMalformedAssertionPayloadReturnsPayloadInvalid(): void
    {
        $service = new PasskeyService($this->repo);
        $this->repo->insert(100, 'credential-a', 'pem', 0, 'Device', 'internal', time());

        $service->beginAuthentication(100, ['credential-a']);

        $result = $service->finishAuthentication(100, [
            'id' => 'credential-a',
            'response' => [
                'clientDataJSON' => '%%%not-base64url%%%',
                'authenticatorData' => '%%%not-base64url%%%',
                'signature' => '%%%not-base64url%%%',
            ],
        ]);

        self::assertFalse($result['ok']);
        self::assertContains($result['error'], ['payload_invalid', 'verify_failed']);
    }

    public function testBeginAuthenticationSkipsInvalidCredentialIdValues(): void
    {
        $service = new PasskeyService($this->repo);
        $options = $service->beginAuthentication(100, ['%%%invalid%%%']);

        self::assertArrayHasKey('publicKey', $options);
        self::assertArrayHasKey('challenge', $options['publicKey']);
    }

    public function testRegistrationRejectsMalformedPayloadAfterChallengeIssued(): void
    {
        $service = new PasskeyService($this->repo);
        $service->beginRegistration(100, 'tester', 'Tester', []);

        $result = $service->finishRegistration(100, [
            'id' => 'credential-a',
            'response' => [
                'clientDataJSON' => '%%%not-base64url%%%',
                'attestationObject' => '%%%not-base64url%%%',
            ],
        ], 'Device');

        self::assertFalse($result['ok']);
        self::assertContains($result['error'], ['payload_invalid', 'verify_failed']);
    }

}
