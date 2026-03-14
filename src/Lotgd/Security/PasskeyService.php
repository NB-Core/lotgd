<?php

declare(strict_types=1);

namespace Lotgd\Security;

use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use Throwable;

/**
 * Handles WebAuthn registration/authentication ceremonies for passkey-based 2FA.
 */
class PasskeyService
{
    private const SESSION_KEY_PREFIX = 'twofactorauth_passkey_challenge_';
    private const CHALLENGE_TTL_SECONDS = 300;

    public function __construct(private readonly PasskeyCredentialRepository $credentials)
    {
    }

    /**
     * Create passkey registration options and bind challenge state to the account.
     *
     * @param array<int, string> $excludeCredentialIds
     *
     * @return array<string, mixed>
     */
    public function beginRegistration(int $acctId, string $login, string $displayName, array $excludeCredentialIds): array
    {
        $webauthn = $this->createWebAuthn();
        // Stored credential IDs are base64url; WebAuthn options require binary IDs.
        $excludeCredentialBuffers = $this->decodeCredentialIdList($excludeCredentialIds);
        $args = $webauthn->getCreateArgs((string) $acctId, $login, $displayName, 60, false, true, null, $excludeCredentialBuffers);
        $challenge = $webauthn->getChallenge();

        $this->storeChallengeState('register', $acctId, $challenge->getBinaryString());

        return json_decode(json_encode($args, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Verify registration response and persist the new credential.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{ok:bool,error:string}
     */
    public function finishRegistration(int $acctId, array $payload, string $label): array
    {
        $challenge = $this->consumeChallengeState('register', $acctId);
        if ($challenge === '') {
            return ['ok' => false, 'error' => 'challenge_missing'];
        }

        $clientCredentialId = $this->normalizeCredentialId((string) ($payload['id'] ?? ''));
        $response = $payload['response'] ?? null;
        if ($clientCredentialId === '' || !is_array($response)) {
            return ['ok' => false, 'error' => 'payload_invalid'];
        }

        $clientDataJson = $this->decodeBase64Url((string) ($response['clientDataJSON'] ?? ''));
        $attestationObject = $this->decodeBase64Url((string) ($response['attestationObject'] ?? ''));
        if ($clientDataJson === '' || $attestationObject === '') {
            return ['ok' => false, 'error' => 'payload_invalid'];
        }

        try {
            $webauthn = $this->createWebAuthn();
            $credential = $webauthn->processCreate($clientDataJson, $attestationObject, $challenge, true, true, false, false);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'verify_failed'];
        }

        $transportsValue = $response['transports'] ?? [];
        $transports = is_array($transportsValue) ? implode(',', array_map('strval', $transportsValue)) : '';
        $saveLabel = trim($label);
        if ($saveLabel === '') {
            $saveLabel = 'Passkey';
        }

        try {
            $verifiedCredentialId = $this->base64UrlEncode((string) ($credential->credentialId ?? ''));
            if ($verifiedCredentialId === '' || $verifiedCredentialId !== $clientCredentialId) {
                return ['ok' => false, 'error' => 'payload_invalid'];
            }

            $publicKey = (string) ($credential->credentialPublicKey ?? '');
            if ($publicKey === '') {
                return ['ok' => false, 'error' => 'verify_failed'];
            }

            $this->credentials->insert(
                $acctId,
                $verifiedCredentialId,
                $publicKey,
                max(0, (int) ($credential->signatureCounter ?? 0)),
                $saveLabel,
                $transports,
                time()
            );
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'credential_exists'];
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Create passkey authentication options for an account.
     *
     * @param array<int, string> $credentialIds
     *
     * @return array<string, mixed>
     */
    public function beginAuthentication(int $acctId, array $credentialIds): array
    {
        $webauthn = $this->createWebAuthn();
        // Stored credential IDs are base64url; WebAuthn options require binary IDs.
        $credentialBuffers = $this->decodeCredentialIdList($credentialIds);
        $args = $webauthn->getGetArgs($credentialBuffers, 60, true, true, true, true, true, true);
        $challenge = $webauthn->getChallenge();

        $this->storeChallengeState('auth', $acctId, $challenge->getBinaryString());

        return json_decode(json_encode($args, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Verify assertion and update counter metadata.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{ok:bool,error:string,clone:bool}
     */
    public function finishAuthentication(int $acctId, array $payload): array
    {
        $challenge = $this->consumeChallengeState('auth', $acctId);
        if ($challenge === '') {
            return ['ok' => false, 'error' => 'challenge_missing', 'clone' => false];
        }

        $credentialId = $this->normalizeCredentialId((string) ($payload['id'] ?? ''));
        $response = $payload['response'] ?? null;
        if ($credentialId === '' || !is_array($response)) {
            return ['ok' => false, 'error' => 'payload_invalid', 'clone' => false];
        }

        $stored = $this->credentials->findByCredentialId($credentialId);
        if ($stored === null || (int) ($stored['acctid'] ?? 0) !== $acctId) {
            return ['ok' => false, 'error' => 'credential_not_owned', 'clone' => false];
        }

        $clientDataJson = $this->decodeBase64Url((string) ($response['clientDataJSON'] ?? ''));
        $authenticatorData = $this->decodeBase64Url((string) ($response['authenticatorData'] ?? ''));
        $signature = $this->decodeBase64Url((string) ($response['signature'] ?? ''));
        if ($clientDataJson === '' || $authenticatorData === '' || $signature === '') {
            return ['ok' => false, 'error' => 'payload_invalid', 'clone' => false];
        }

        try {
            $webauthn = $this->createWebAuthn();
            $webauthn->processGet(
                $clientDataJson,
                $authenticatorData,
                $signature,
                (string) ($stored['public_key'] ?? ''),
                $challenge,
                (int) ($stored['sign_count'] ?? 0),
                true,
                true
            );
            $newCount = $webauthn->getSignatureCounter() ?? (int) ($stored['sign_count'] ?? 0);
            $this->credentials->updateUsage($credentialId, $newCount, time());
        } catch (Throwable $error) {
            $clone = $error instanceof WebAuthnException
                && $error->getCode() === WebAuthnException::SIGNATURE_COUNTER;

            return ['ok' => false, 'error' => $clone ? 'clone_detected' : 'verify_failed', 'clone' => $clone];
        }

        return ['ok' => true, 'error' => '', 'clone' => false];
    }

    private function createWebAuthn(): WebAuthn
    {
        $rpId = $this->resolveRpId();

        return new WebAuthn($this->resolveRpName(), $rpId, ['none', 'packed', 'fido-u2f', 'apple'], true);
    }

    private function resolveRpId(): string
    {
        $serverUrl = trim((string) LegacyBridge::getSetting('serverurl', 'http://localhost'));
        $host = (string) parse_url($serverUrl, PHP_URL_HOST);

        return $host !== '' ? $host : 'localhost';
    }

    private function resolveRpName(): string
    {
        // Use server description as human-facing RP name; gameadminemail is not user-facing.
        $serverDescription = trim((string) LegacyBridge::getSetting('serverdesc', 'Legend of the Green Dragon'));

        return $serverDescription !== '' ? $serverDescription : 'Legend of the Green Dragon';
    }

    private function storeChallengeState(string $type, int $acctId, string $challenge): void
    {
        $session = &$this->sessionStore();
        $session[$this->challengeSessionKey($type)] = [
            'type' => $type,
            'acctid' => $acctId,
            'challenge' => base64_encode($challenge),
            'expires_at' => time() + self::CHALLENGE_TTL_SECONDS,
        ];
    }

    private function consumeChallengeState(string $expectedType, int $acctId): string
    {
        $session = &$this->sessionStore();
        $sessionKey = $this->challengeSessionKey($expectedType);
        $state = $session[$sessionKey] ?? null;

        if (!is_array($state)) {
            return '';
        }

        if ((string) ($state['type'] ?? '') !== $expectedType || (int) ($state['acctid'] ?? 0) !== $acctId) {
            return '';
        }

        if ((int) ($state['expires_at'] ?? 0) < time()) {
            unset($session[$sessionKey]);

            return '';
        }

        $challenge = base64_decode((string) ($state['challenge'] ?? ''), true) ?: '';
        if ($challenge === '') {
            unset($session[$sessionKey]);

            return '';
        }

        // Consume only after successful validation to avoid clobbering parallel/accidental requests.
        unset($session[$sessionKey]);

        return $challenge;
    }

    /**
     * @return array<string, mixed>
     */
    private function &sessionStore(): array
    {
        if (isset($GLOBALS['session']) && is_array($GLOBALS['session'])) {
            return $GLOBALS['session'];
        }

        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }

        return $_SESSION;
    }

    /**
     * Build the session key for a specific ceremony type.
     */
    private function challengeSessionKey(string $type): string
    {
        return self::SESSION_KEY_PREFIX . $type;
    }

    private function normalizeCredentialId(string $id): string
    {
        return trim($id);
    }

    private function decodeBase64Url(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            $buffer = ByteBuffer::fromBase64Url($value);

            return $buffer->getBinaryString();
        } catch (Throwable) {
            // Invalid client payload should be treated as validation failure, not a 500.
            return '';
        }
    }


    /**
     * Decode a list of base64url credential IDs to raw binary values for WebAuthn options.
     *
     * @param array<int, string> $credentialIds
     *
     * @return array<int, string>
     */
    private function decodeCredentialIdList(array $credentialIds): array
    {
        $decoded = [];
        foreach ($credentialIds as $credentialId) {
            $binaryId = $this->decodeBase64Url((string) $credentialId);
            if ($binaryId === '') {
                continue;
            }

            $decoded[] = $binaryId;
        }

        return $decoded;
    }

    /**
     * Encode binary credential IDs to base64url for safe storage.
     */
    private function base64UrlEncode(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
