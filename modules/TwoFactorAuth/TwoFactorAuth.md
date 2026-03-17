# Two Factor Auth module (`twofactorauth`)

This module adds a second step (TOTP) to the existing password login flow without changing legacy login fields.

## Install and enable

1. Install/activate the module in module management.
2. Configure settings:
   - `issuer_name`
   - `token_digits`
   - `period_seconds`
   - `window`
   - `max_attempts`
   - `lock_seconds`
   - `disable_link_ttl_minutes`
   - `require_verified_email`
   - `qr_provider_endpoint`
   - `qr_code_size`
3. Ask users to visit **Preferences -> Two-factor authentication** and complete setup.
   - Setup now shows a scannable QR code and the manual secret for app enrollment.

## User flow (challenge interstitial + resume)

1. User logs in with password.
2. `player-login` stages a **resume snapshot in session** only:
   - `twofactorauth_resume_restorepage`
   - `twofactorauth_resume_allowednavs`
   - `twofactorauth_pending = true`
3. On the next `everyhit`, the module persists challenge state (`pending_challenge`, `pending_since`, lock counters) and writes the staged resume snapshot into module prefs.
4. While `pending_challenge=1`, navigation stays jailed to challenge actions only.
5. Successful verification does **not** jump directly to village; it offers a continue link to `runmodule.php?module=twofactorauth&op=resume`.
6. `op=resume` restores `restorepage` and `allowednavs` from the stored snapshot, validates that the target URI is allowlisted for this transition, then redirects to the original destination. If no valid target exists, it falls back to village.

### Why session staging exists before `everyhit`

`player-login` runs before the module has established persisted pending state for the challenge interstitial. Staging restore context in session first avoids writing partial/early snapshot prefs during login and lets `everyhit` perform a single cohesive persistence step once the pending challenge is confirmed. This preserves the pre-challenge navigation context safely until the user passes 2FA.

## Recovery flow (disable via email)

1. On the challenge page, choose **Disable via email**.
2. The module sends a signed, time-limited link to the account email.
3. Opening the link disables 2FA and logs an audit event.
4. User must log in again with password and can re-enroll later.

## Security notes

- TOTP defaults are RFC6238-compatible (SHA1, 30-second step, 6 digits).
- Drift window is configurable.
- Replay protection rejects previously used timesteps.
- Brute-force mitigation uses failed-attempt counters and lockouts.
- Invalid token submissions keep the pending challenge active, add a short delay (`~2s`), and present the retry form again instead of forcing immediate logout.
- Token verification outcomes are audit-logged via debug log entries (success and categorized failure reasons: `format`, `mismatch`, `replay`, `locked`) without recording token values.

## Passkeys (WebAuthn) as 2FA alternative

This iteration adds passkeys as an **alternative** challenge method while keeping TOTP in place.

### Enrollment in Preferences

- The setup page now includes a **Passkeys** section.
- Users can enroll multiple passkeys (for example: phone + laptop + hardware key).
- Each passkey stores:
  - credential id (base64url)
  - credential public key
  - signature counter
  - label
  - transports metadata
  - created timestamp
  - last used timestamp
- Credentials are listed with created/last-used times.
- Each credential can be removed individually from the same page.

### Login challenge behavior

- During the existing pending 2FA challenge, users can:
  - continue with **TOTP token** (unchanged), or
  - choose **Use passkey** to complete assertion with `navigator.credentials.get`.
- On success, pending challenge state is cleared and resume flow remains unchanged (`op=resume`).
- On failure, failed-attempt counters/lockout behavior are shared with TOTP challenge policy.

### Security controls

- Registration and authentication challenges are short-lived and bound to account id in session.
- RP ID/origin checks are enforced by WebAuthn verification.
- Signature counter updates are persisted; counter regressions are treated as authentication failures (clone-signal policy).
- Passkey registration/authentication success/failure are audit-logged without sensitive credential material.
- Passkey deletion requires both account ownership checks and a CSRF token.

### Async architecture: why passkey flows now run through Jaxon

Passkey setup/login begin+finish calls are routed through a dedicated Jaxon handler (`Lotgd.Async.Handler.TwoFactorAuthPasskey`) and `async/process.php`.

Rationale:

- `async/process.php` is explicitly built for async transport and uses `OVERRIDE_FORCED_NAV`, so payload responses are not replaced by forced-navigation redirects.
- Jaxon handler responses keep the flow inside the established async pipeline used by core AJAX features (commentary/mail/timeout), improving consistency and observability.
- The passkey domain logic remains centralized in `PasskeyService` + `PasskeyCredentialRepository`; the async handler is a transport/orchestration layer only.

Why direct `runmodule.php` fetch is problematic in this context:

- `runmodule.php` requests are still subject to AllowedNav / ForcedNav checks tied to full-page navigation assumptions.
- In challenge/setup edge-cases, those checks can return HTML redirects/chrome instead of JSON, which breaks WebAuthn ceremony parsing in the browser.
- This mismatch caused brittle behavior (for example JSON parse failures on passkey begin/finish) that is avoided by using the dedicated async endpoint.

### Recovery and fallback

- TOTP remains available as fallback while passkeys are introduced.
- Email-based disable/recovery flow remains available for lockout scenarios.
- Full passwordless login redesign is intentionally out of scope for this iteration.



### Challenge polling behaviour and failure modes

- The challenge page initializes Jaxon and background polling via `async/setup.php` while the user submits the classic verify form (`runmodule.php?module=twofactorauth&op=verify`).
- Polling setup is guarded in JavaScript to avoid duplicate interval registration and competing async requests.
- If async endpoints return empty or invalid JSON, browser-side Jaxon parsing can fail with `SyntaxError` (for example `Unexpected end of JSON input`).

### Quick troubleshooting sequence (challenge)

1. **Verify POST first:** confirm the token form posts to `runmodule.php?module=twofactorauth&op=verify` and check module debug checkpoints (`run entry`, `verify handler entry`, token length, and branch `valid`/`invalid`/`locked`).
2. **Then inspect polling response:** check `async/process.php` responses in browser devtools. Ensure failures still return valid JSON payloads, not empty bodies or HTML.
3. **If parse errors persist:** inspect PHP/webserver logs for thrown exceptions and correlate timestamps with the 2FA/passkey debug log entries.

## Troubleshooting passkey enrollment and login

If passkey registration or verification fails, verify the relying party/domain setup first:

- **RP ID must match the site domain** used by players in the browser. WebAuthn validates RP ID against the current origin host.
- **Use a fully-qualified `serverurl` with scheme**, for example `https://game.example.com`.
  - Avoid malformed values such as `game.example.com/path` (no scheme), which can break RP ID parsing.
- **HTTPS is required** for passkeys in normal environments.
  - Local development can use `http://localhost`, but production/staging passkey flows should use valid TLS.
- If you run behind a reverse proxy, ensure the external host players see is consistent with `serverurl`; host mismatches can cause browser-side `NotAllowedError` / RP mismatch failures.
- If the browser shows **"Unexpected end of JSON input"** during passkey begin/finish, the backend likely returned empty content, HTML, or another non-JSON response. Inspect the raw begin/finish endpoint response body first to identify redirects/login pages/PHP errors quickly.
- If begin-response **Raw start is empty**, treat it as backend exception/empty output. Check the Two Factor Auth module debug log and your web server/PHP error log for the root cause.
