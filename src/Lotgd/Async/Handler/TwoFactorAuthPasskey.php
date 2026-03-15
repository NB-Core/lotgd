<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

use Jaxon\Response\Response;
use Lotgd\DebugLog;
use Lotgd\Security\PasskeyCredentialRepository;
use Lotgd\Security\PasskeyService;

use function Jaxon\jaxon;

/**
 * Handle passkey 2FA ceremonies via Jaxon under async/process.php.
 *
 * The handler keeps WebAuthn request/response payload exchange in the async pipeline,
 * preventing forced-navigation and allowed-nav edge-cases on runmodule fetch endpoints.
 */
class TwoFactorAuthPasskey
{
    /**
     * Module slug used when reading/writing module preferences and settings.
     */
    private const MODULE_NAME = 'twofactorauth';

    /**
     * Browser callback used to resolve in-flight Jaxon passkey calls.
     */
    private const CALLBACK_FUNCTION = 'window.twofactorauthHandleJaxonResponse';

    public function __construct(
        private readonly ?PasskeyService $service = null,
        private readonly ?PasskeyCredentialRepository $repository = null
    ) {
    }

    /**
     * Create WebAuthn registration options.
     */
    public function beginRegistration(string $requestId, string $csrfToken, string $label = ''): Response
    {
        $acctId = $this->accountId();
        if ($acctId < 1) {
            return $this->respond($requestId, $this->errorPayload('unauthorized'));
        }

        if (!$this->isCsrfValid($csrfToken)) {
            return $this->respond($requestId, $this->errorPayload('csrf'));
        }

        $login = trim((string) ($GLOBALS['session']['user']['login'] ?? ''));
        $display = trim((string) ($GLOBALS['session']['user']['name'] ?? $login));
        $existing = $this->repository()->listForAccount($acctId);
        $excludeIds = array_map(static fn(array $item): string => (string) ($item['credential_id'] ?? ''), $existing);

        try {
            $options = $this->service()->beginRegistration($acctId, $login, $display, $excludeIds);

            return $this->respond($requestId, ['ok' => true, 'options' => $options]);
        } catch (\Throwable $error) {
            DebugLog::add(
                sprintf('2FA passkey registration begin exception for account %d (%s: %s).', $acctId, $error::class, $error->getMessage()),
                $acctId,
                $acctId,
                '2fa_passkey',
                false,
                false
            );

            return $this->respond($requestId, $this->errorPayload('begin_exception', $error));
        }
    }

    /**
     * Complete registration and persist passkey metadata.
     *
     * @param array<string, mixed> $credentialPayload
     */
    public function finishRegistration(string $requestId, string $csrfToken, string $label, array $credentialPayload): Response
    {
        $acctId = $this->accountId();
        if ($acctId < 1) {
            return $this->respond($requestId, $this->errorPayload('unauthorized'));
        }

        if (!$this->isCsrfValid($csrfToken)) {
            return $this->respond($requestId, $this->errorPayload('csrf'));
        }

        try {
            $result = $this->service()->finishRegistration($acctId, $credentialPayload, $label);
            $ok = (bool) ($result['ok'] ?? false);
            $errorCode = (string) ($result['error'] ?? 'unknown');

            DebugLog::add(
                sprintf('2FA passkey registration %s for account %d%s.', $ok ? 'success' : 'failure', $acctId, $ok ? '' : ' (reason: ' . $errorCode . ')'),
                $acctId,
                $acctId,
                '2fa_passkey',
                false,
                false
            );

            if ($ok) {
                $this->setModulePref('passkeys_enabled', 1, $acctId);

                return $this->respond($requestId, ['ok' => true]);
            }

            return $this->respond($requestId, $this->errorPayload($errorCode));
        } catch (\Throwable $error) {
            DebugLog::add(
                sprintf('2FA passkey registration finish exception for account %d (%s: %s).', $acctId, $error::class, $error->getMessage()),
                $acctId,
                $acctId,
                '2fa_passkey',
                false,
                false
            );

            return $this->respond($requestId, $this->errorPayload('finish_exception', $error));
        }
    }

    /**
     * Create WebAuthn authentication options for the pending challenge.
     */
    public function beginAuthentication(string $requestId, string $csrfToken): Response
    {
        $acctId = $this->accountId();
        if ($acctId < 1 || !$this->isPendingChallenge()) {
            return $this->respond($requestId, $this->errorPayload('no_pending'));
        }

        if (!$this->isCsrfValid($csrfToken)) {
            return $this->respond($requestId, $this->errorPayload('csrf'));
        }

        $existing = $this->repository()->listForAccount($acctId);
        $credentialIds = array_map(static fn(array $item): string => (string) ($item['credential_id'] ?? ''), $existing);

        try {
            $options = $this->service()->beginAuthentication($acctId, $credentialIds);

            return $this->respond($requestId, ['ok' => true, 'options' => $options]);
        } catch (\Throwable $error) {
            DebugLog::add(
                sprintf('2FA passkey authentication begin exception for account %d (%s: %s).', $acctId, $error::class, $error->getMessage()),
                $acctId,
                $acctId,
                '2fa_passkey',
                false,
                false
            );

            return $this->respond($requestId, $this->errorPayload('begin_auth_exception', $error));
        }
    }

    /**
     * Verify WebAuthn assertion for login challenge.
     *
     * @param array<string, mixed> $credentialPayload
     */
    public function verifyAuthentication(string $requestId, string $csrfToken, array $credentialPayload): Response
    {
        $acctId = $this->accountId();
        if ($acctId < 1 || !$this->isPendingChallenge()) {
            return $this->respond($requestId, $this->errorPayload('no_pending'));
        }

        if (!$this->isCsrfValid($csrfToken)) {
            return $this->respond($requestId, $this->errorPayload('csrf'));
        }

        $lockedUntil = (int) $this->getModulePref('locked_until', $acctId);
        $now = time();
        if ($lockedUntil > $now) {
            return $this->respond($requestId, $this->errorPayload('locked'));
        }

        try {
            $result = $this->service()->finishAuthentication($acctId, $credentialPayload);
        } catch (\Throwable $error) {
            DebugLog::add(
                sprintf('2FA passkey authentication verify exception for account %d (%s: %s).', $acctId, $error::class, $error->getMessage()),
                $acctId,
                $acctId,
                '2fa_passkey',
                false,
                false
            );

            return $this->respond($requestId, $this->errorPayload('verify_exception', $error));
        }

        if ((bool) ($result['ok'] ?? false)) {
            $this->clearPendingState();
            DebugLog::add(sprintf('2FA passkey authentication success for account %d.', $acctId), $acctId, $acctId, '2fa_passkey', false, false);

            return $this->respond($requestId, ['ok' => true]);
        }

        $errorCode = (string) ($result['error'] ?? 'verify_failed');
        $fails = (int) $this->getModulePref('failed_attempts', $acctId) + 1;
        $this->setModulePref('failed_attempts', $fails, $acctId);
        $maxAttempts = (int) $this->getModuleSetting('max_attempts');
        if ($fails >= $maxAttempts) {
            $this->setModulePref('locked_until', $now + (int) $this->getModuleSetting('lock_seconds'), $acctId);
        }

        DebugLog::add(
            sprintf('2FA passkey authentication failure for account %d (reason: %s).', $acctId, $errorCode),
            $acctId,
            $acctId,
            '2fa_passkey',
            false,
            false
        );

        return $this->respond($requestId, $this->errorPayload($errorCode));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respond(string $requestId, array $payload): Response
    {
        $response = jaxon()->newResponse();
        $response->call(self::CALLBACK_FUNCTION, $requestId, $payload);

        return $response;
    }

    private function accountId(): int
    {
        return (int) ($GLOBALS['session']['user']['acctid'] ?? 0);
    }

    private function isPendingChallenge(): bool
    {
        return (int) $this->getModulePref('pending_challenge', $this->accountId()) === 1;
    }

    private function isCsrfValid(string $csrfToken): bool
    {
        if (\function_exists('twofactorauth_csrf_token')) {
            return hash_equals((string) \twofactorauth_csrf_token(), $csrfToken);
        }

        // async/process.php does not include module functions by default; in that path,
        // validate against the session-backed token generated by module setup/challenge views.
        return hash_equals((string) ($GLOBALS['session']['twofactorauth_csrf'] ?? ''), $csrfToken);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorPayload(string $code, ?\Throwable $error = null): array
    {
        $payload = ['ok' => false, 'error' => $code, 'message' => 'Passkey operation failed.'];

        $superuserFlags = (int) ($GLOBALS['session']['user']['superuser'] ?? 0);
        if ($error instanceof \Throwable && \defined('SU_MEGAUSER') && ($superuserFlags & SU_MEGAUSER) === SU_MEGAUSER) {
            $payload['debug_message'] = $error->getMessage();
            $payload['diagnostic'] = [
                'type' => $error::class,
                'code' => $error->getCode(),
            ];
        }

        return $payload;
    }

    private function clearPendingState(): void
    {
        $acctId = $this->accountId();
        $this->setModulePref('pending_challenge', 0, $acctId);
        $this->setModulePref('pending_since', 0, $acctId);
        $this->setModulePref('failed_attempts', 0, $acctId);
        $this->setModulePref('locked_until', 0, $acctId);
        $this->setModulePref('disable_token_uri', '', $acctId);
    }

    /**
     * Read a module preference from the canonical module namespace.
     */
    private function getModulePref(string $name, ?int $acctId = null): mixed
    {
        if (\function_exists('get_module_pref')) {
            return \call_user_func('get_module_pref', $name, self::MODULE_NAME, $acctId);
        }

        $resolvedAcctId = $acctId ?? 0;

        return $GLOBALS['twofactorauth_module_prefs'][$resolvedAcctId][$name] ?? 0;
    }

    /**
     * Persist a module preference into the canonical module namespace.
     */
    private function setModulePref(string $name, mixed $value, ?int $acctId = null): void
    {
        if (\function_exists('set_module_pref')) {
            \call_user_func('set_module_pref', $name, $value, self::MODULE_NAME, $acctId);

            return;
        }

        $resolvedAcctId = $acctId ?? 0;
        if (!isset($GLOBALS['twofactorauth_module_prefs'][$resolvedAcctId]) || !is_array($GLOBALS['twofactorauth_module_prefs'][$resolvedAcctId])) {
            $GLOBALS['twofactorauth_module_prefs'][$resolvedAcctId] = [];
        }

        $GLOBALS['twofactorauth_module_prefs'][$resolvedAcctId][$name] = $value;
    }

    /**
     * Read a module setting from the canonical module namespace.
     */
    private function getModuleSetting(string $name): mixed
    {
        if (\function_exists('get_module_setting')) {
            return \call_user_func('get_module_setting', $name, self::MODULE_NAME);
        }

        return $GLOBALS['twofactorauth_module_settings'][$name] ?? 0;
    }
    private function repository(): PasskeyCredentialRepository
    {
        return $this->repository ?? new PasskeyCredentialRepository();
    }

    private function service(): PasskeyService
    {
        return $this->service ?? new PasskeyService($this->repository());
    }
}
