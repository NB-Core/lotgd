# Passkey Service (Core Engine)

This document explains how the core passkey service works, where it should be
used, and which security boundaries it relies on.

> Primary implementation classes:
>
> - `src/Lotgd/Security/PasskeyService.php`
> - `src/Lotgd/Security/PasskeyCredentialRepository.php`
>
> Primary core module integration:
>
> - `modules/twofactorauth.php`
> - `src/Lotgd/Async/Handler/TwoFactorAuthPasskey.php`

## What the service does

`Lotgd\Security\PasskeyService` encapsulates WebAuthn registration and
assertion verification for account-bound credentials.

It provides four main operations:

1. `beginRegistration(...)`
2. `finishRegistration(...)`
3. `beginAuthentication(...)`
4. `finishAuthentication(...)`

The service is intentionally stateful through server-side challenge storage:
challenges are stored in session and consumed once, with a short TTL.

## Intended usage pattern

Use the passkey service from authenticated, server-controlled flows only.
Core usage should follow the two-factor auth module pattern.

### Setup / enrollment flow (authenticated user)

1. Validate user session and CSRF token.
2. Load existing credentials for that account.
3. Call `beginRegistration(...)` and return options JSON to browser.
4. Browser calls `navigator.credentials.create(...)`.
5. POST credential payload back.
6. Call `finishRegistration(...)` to verify and persist.

In core, this is wired in:

- `modules/twofactorauth.php` setup operations.
- `src/Lotgd/Async/Handler/TwoFactorAuthPasskey.php` async/Jaxon handler.

### Challenge / login verification flow (pending 2FA only)

1. Ensure a pending challenge exists for the account.
2. Validate CSRF token.
3. Call `beginAuthentication(...)`.
4. Browser calls `navigator.credentials.get(...)`.
5. POST assertion payload back.
6. Call `finishAuthentication(...)`.
7. On success, clear pending challenge state.

In core, this is wired in both synchronous and async challenge endpoints in the
`twofactorauth` module and the dedicated async handler.

## Security model and boundaries

### Server-side ownership checks

`finishAuthentication(...)` resolves the credential by ID and verifies it belongs
to the authenticated account before verification succeeds.

### Challenge lifecycle

Challenges are stored server-side in session with:

- ceremony type (`register` or `auth`),
- account ID binding,
- expiry timestamp,
- single-use consumption.

This prevents replay and cross-account reuse when session boundaries are intact.

### CSRF boundary

The service itself validates WebAuthn payloads and challenge state. Request-level
CSRF validation must happen in the caller (for example the twofactorauth module
or async handler) before invoking service operations.

### Async boundary

When used over Jaxon (`async/process.php`), callers must enforce:

- authenticated session context,
- pending challenge gating for login verification,
- CSRF token validation,
- failure counters / lockouts.

Core `twofactorauth` integration already implements this pattern.

## Do and do not

### Do

- Do call the service from authenticated server handlers only.
- Do validate CSRF in the handler before calling the service.
- Do keep registration and authentication flows account-bound.
- Do clear pending state after successful verification.
- Do log failures with sanitized error codes (not raw secrets).

### Do not

- Do not expose service calls directly to unauthenticated routes.
- Do not treat client-provided credential IDs as trusted without ownership checks.
- Do not persist unbounded client payload blobs.
- Do not bypass pending challenge checks during login verification.

## Minimal integration example (server-side)

```php
<?php

declare(strict_types=1);

use Lotgd\Security\PasskeyCredentialRepository;
use Lotgd\Security\PasskeyService;

$repository = new PasskeyCredentialRepository();
$service = new PasskeyService($repository);

// Example: registration begin for authenticated account.
$acctId = (int) $session['user']['acctid'];
$login = (string) $session['user']['login'];
$display = (string) ($session['user']['name'] ?? $login);
$existing = $repository->listForAccount($acctId);
$excludeIds = array_map(static fn(array $row): string => (string) ($row['credential_id'] ?? ''), $existing);

$options = $service->beginRegistration($acctId, $login, $display, $excludeIds);
```

Keep endpoint-specific security checks (auth/session, CSRF, rate limiting,
pending challenge checks) outside this snippet, in your controller/handler.

## Related files for reference

- `modules/twofactorauth.php`
- `src/Lotgd/Async/Handler/TwoFactorAuthPasskey.php`
- `src/Lotgd/Security/PasskeyService.php`
- `tests/Security/PasskeyServiceTest.php`
- `tests/Async/TwoFactorAuthPasskeyHandlerTest.php`
