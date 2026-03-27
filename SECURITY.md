# Security Policy

## Reporting a Vulnerability

The preferred channel for confidential reports is GitHub’s **Private vulnerability reporting** feature. Open a private advisory from the repository’s Security tab and include:

- Affected version or commit SHA
- Environment details (PHP version, database, browser, etc.)
- Reproduction steps and expected vs. actual behavior
- Impact assessment and any proof-of-concept
- Whether details are already public and any disclosure timing requests

If the advisory system is unavailable, please fall back to the alternate contact listed in the Security settings.

## Response Expectations

- We aim to acknowledge new reports within **7 calendar days**.
- Status updates are provided at least every **30 days** while an issue is under investigation.
- Once confirmed, we work to deliver a fix or mitigation as quickly as possible, prioritizing critical issues. Coordinated disclosure timing will be agreed with the reporter before public release.

These timelines reflect a volunteer-run project; we’ll communicate sooner whenever we can.

## Supported Versions

| Version | Supported |
|---------|-----------|
| Latest release (see [CHANGELOG](CHANGELOG.md)) | ✅ |
| Older releases | ❌ — may receive critical security patches at our discretion |

## Disclosure Policy and Safe Harbor

Good-faith security research is welcome. Please avoid impacting production players, respect rate limits, and do not access other users’ data. We will not pursue legal action for vulnerability testing performed within these bounds. Coordinate public disclosure with us so we can prepare a fix and notify the community.

## Recognition

With your permission, verified reporters are thanked in the release notes. The project does not operate a bug bounty or provide monetary rewards.

## Runtime Hardening Defaults

The application now applies a single runtime hardening bootstrap in `common.php` before `session_start()` to set secure session-cookie parameters and central HTTP response headers for HTML pages.

### Session cookie defaults

- `path=/`
- `HttpOnly=true`
- `Secure` automatically enabled when HTTPS is detected (can be forced)
- `SameSite=Lax` by default (`Strict` is also supported)

### Session fixation controls

- Session IDs are regenerated after successful authentication (`login.php`).
- Session IDs are also regenerated when superuser privileges increase during an active session (privilege elevation path).

### Default HTML headers

- `X-Frame-Options: SAMEORIGIN` (or optional CSP `frame-ancestors`)
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Strict-Transport-Security` only when HTTPS is detected and explicitly enabled.

### Operator compatibility switches (in `dbconnect.php`)

These keys are optional and allow phased rollout:

- `SESSION_COOKIE_PATH` (default `/`)
- `SESSION_COOKIE_DOMAIN` (default empty)
- `SESSION_COOKIE_SAMESITE` (`Lax`, `Strict`, or `None`; default `Lax`)
- `SESSION_COOKIE_SECURE_AUTO` (default `true`)
- `SESSION_COOKIE_SECURE_FORCE` (default `false`)
- `SECURITY_HEADERS_ENABLED` (default `true`)
- `SECURITY_FRAME_OPTIONS` (default `SAMEORIGIN`)
- `SECURITY_USE_CSP_FRAME_ANCESTORS` (default `false`)
- `SECURITY_CSP_FRAME_ANCESTORS` (default `'self'`)
- `SECURITY_REFERRER_POLICY` (default `strict-origin-when-cross-origin`)
- `SECURITY_HSTS_ENABLED` (default `false`)
- `SECURITY_HSTS_MAX_AGE` (default `31536000`)
- `SECURITY_HSTS_INCLUDE_SUBDOMAINS` (default `false`)
- `SECURITY_HSTS_PRELOAD` (default `false`)

### Deployment notes

- If you run behind a reverse proxy/load balancer, ensure it forwards `X-Forwarded-Proto` correctly so HTTPS detection is accurate.
- Do not enable `SameSite=None` unless TLS is enforced and `Secure` is enabled.
- Roll out HSTS carefully (start with low `max-age`) and enable preload only after confirming all subdomains are HTTPS-ready.
