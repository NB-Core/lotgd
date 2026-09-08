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
- `SECURITY_TRUST_FORWARDED_PROTO` (default `false`)
- `SECURITY_TRUSTED_PROXIES` (comma-separated allowlist of literal IP addresses and/or CIDR blocks, default empty)

### Deployment notes

- If you run behind a reverse proxy/load balancer, enable `SECURITY_TRUST_FORWARDED_PROTO` and set `SECURITY_TRUSTED_PROXIES` so only trusted peers can influence HTTPS detection. With the list left empty, forwarded protocol headers are accepted only from loopback and private network ranges (`127.0.0.0/8`, `10/8`, `172.16/12`, `192.168/16`, `::1`, `fc00::/7`) and ignored from public addresses — enough for a proxy on the same host or container network, but set the list explicitly if your proxy reaches the application from a public address. An explicit list replaces that default instead of extending it, so include every peer that terminates TLS.
- Forwarded-header trust decides whether session cookies are issued with the `Secure` flag and whether HSTS is emitted, so a client that can spoof it can influence its own cookie protection. That is why an unlisted public peer is never believed.
- Do not enable `SameSite=None` unless TLS is enforced and `Secure` is enabled.
- Roll out HSTS carefully (start with low `max-age`) and enable preload only after confirming all subdomains are HTTPS-ready.

## Navigation allowlist (`allownav`)

The game validates that a request corresponds to a link it actually offered.
This is not only a usability or anti-cheat feature — it is a security control,
and several code paths depend on it, so its exact reach matters when reviewing
them.

### How it works

`Nav::add()` records every rendered link in `$session['allowednavs']`, keyed by
the link **including its query string**. On the next request,
`ForcedNavigation::doForcedNav()` — called from `common.php` for every logged-in
request — looks up `$_SERVER['REQUEST_URI']` in that set. A URI that is not
present sends the player to `badnav.php`.

The practical effect is that a player cannot invent a URL. Parameter values are
limited to the ones the server itself put into a link, which is why crafted
query strings do not reach most page code — and why the same mechanism makes
value tampering hard.

### What it does not cover

Treat the allowlist as a second line of defence, never as the reason a value may
be trusted. It does not apply to:

- **Request bodies.** The key is the URI. Anything in a POST body is
  unconstrained, so `Http::post()` values reach page code regardless of the
  allowlist. Validate and bind them like any other untrusted input.
- **Pages that opt out.** The check sits inside the logged-in branch, and pages
  defining `OVERRIDE_FORCED_NAV` skip it. `ALLOW_ANONYMOUS` pages are reached
  without it entirely.
- **Async endpoints.** `async/common/bootstrap.php` runs the same check, but a
  Jaxon call carries its arguments in the request body, so the note about
  request bodies applies there too. `async/process.php` enforces its own
  callable allowlist; that is what constrains async, not the nav list.
- **Values the application itself puts into a link.** The allowlist grows from
  rendered navs, so a request-derived value that is interpolated into a
  `Nav::add()` URL widens it. Normalize such values (cast, `rawurlencode()`, or
  a sanitizer) before they reach a link, as `healer.php` and `user.php` already
  do.

### What this means for review

A query built from `Http::get()` is not safe because the allowlist would stop a
crafted URL. Bind the parameter. The allowlist reduces the reachability of a
mistake; it does not make the mistake harmless, and the exemptions above are
exactly where reachability comes back.

## Secure coding baseline

When changing security-sensitive code paths, align implementation and review notes with these project references:

- Doctrine prepared statements baseline: [docs/Doctrine.md#prepared-statements](docs/Doctrine.md#prepared-statements)
- Async authentication and rate-limit guidance: [AGENTS.md#async--jaxon](AGENTS.md#async--jaxon) and [docs/PasskeyService.md#async-boundary](docs/PasskeyService.md#async-boundary)
- Session, header, and cookie expectations: [docs/PasskeyService.md#security-model-and-boundaries](docs/PasskeyService.md#security-model-and-boundaries), [UPGRADING.md#6-configuration-changes](UPGRADING.md#6-configuration-changes), and [UPGRADING.md#8-after-upgrade](UPGRADING.md#8-after-upgrade)
