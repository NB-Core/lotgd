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
3. Ask users to visit **Preferences -> Two-factor authentication** and complete setup.

## User flow

1. User logs in with password.
2. If 2FA is enabled, login enters a pending challenge state.
3. User is redirected to `runmodule.php?module=twofactorauth&op=challenge`.
4. While pending, navigation is locked to challenge actions only.
5. Valid token clears pending state and resumes normal navigation.

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
