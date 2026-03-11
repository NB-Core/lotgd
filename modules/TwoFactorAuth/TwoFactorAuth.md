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
