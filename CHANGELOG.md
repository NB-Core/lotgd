# Changelog

All notable changes to this project will be documented in this file.  
This project follows semantic versioning (MAJOR.MINOR.PATCH) starting from 2.0.0.  

The last **official 1.x release** was [`v1.3.2`](https://github.com/NB-Core/lotgd/commit/2be7254fc2b68bf91e86d71361556667e826ecf1) (2025-06-02).  
Everything below covers the path from 1.3.2 through the 2.0 release candidates and the 2.0.x releases.  

---

## [Unreleased]

### Changed

- Reworked mail controls and administration edit/delete rows into reusable, wrapping action bars. Modules can add mail read actions through `mail-read-actions`, and all bundled classic and Twig themes now give links, buttons, disabled controls, sidebars, and action rows consistent styling.
- Refactored combat damage, banking, character level-up, companion level-up, and companion death reporting into focused, testable services while preserving their legacy entry points.
- Improved the mail read view's action grouping, confirmation behavior, escaping, feedback, and vertical spacing; restored consistent Inbox/Write navigation and made module-provided labels safe to render.

### Fixed

- Two-factor secrets are now decoded case-insensitively, as RFC 4648 defines base32. The decoder stripped before it uppercased, so a lower-case secret was silently decoded into a different, much shorter one rather than rejected. Secrets this game generates are upper-case and decode byte-for-byte as before; the change affects only secrets that arrived from elsewhere — an import or a migration — which could never have verified.
- Corrected combat edge cases: resistance no longer amplifies ripostes, zero-damage rolls cannot loop forever or discard a hit on the final retry, regeneration uses its configured message, and damaging auras can remove companions while respecting `cannotdie`.
- Restored companion experience and level gains after training, including persistence after suspended hooks resume.
- Made warning suppression severity-aware and kept bootstrap/test diagnostics from being swallowed or overstating what they could observe.
- Updated `modules/twofactorauth.php` and `modules/TwoFactorAuth/TwoFactorAuthService.php` to restore legacy two-factor secrets when the active key cannot decrypt them, reject implausible decoded values before accepting a key, and preserve the stored encrypted blob during setup; incompatible CBC fixtures now skip with an accurate diagnostic.
- Kept mail navigation and action rendering stable for malformed hook payloads and recorded refused mail actions through the centralized security log.
- `Lotgd\SecurityLog` now makes its output valid UTF-8 instead of only detecting when it is not. A context or message value carrying a malformed byte sequence previously reached the game log's `utf8mb4` insert unchanged, so the write raised from inside the logger and the security event was lost. The output is now guaranteed to be valid UTF-8; where the mbstring extension is present the invalid sequences are marked with U+FFFD rather than dropped. Valid multi-byte text is untouched either way.
- Every legacy wrapper under `lib/` can be loaded beside every other, in any order. Four could not, all since 2.0.5: `lib/mail.php` redeclared `systemmail()`, `send_email()` and `is_email()`, so a module loading it beside `lib/systemmail.php`, `lib/sendmail.php` or `lib/is_email.php` died with "Cannot redeclare function"; it now includes those files instead, which also keeps the 1.x signatures — its own copy of `systemmail()` had rejected the translation array a module may pass as the subject. `lib/settings.class.php` and `lib/serverfunctions.class.php` declared classes extending themselves and could not load at all, and `lib/battle-functions.php` loaded only if `FightBar` happened to be loaded first; all three now provide their 1.x class names as aliases.

### Security

- Two-factor secrets are stored in an authenticated format (`aes-256-gcm`) instead of unauthenticated `aes-256-cbc`. The old format could not distinguish a wrong key from a right one, which permanently locked roughly one account in 262 out of 2FA; existing secrets are rewritten automatically the next time their owner verifies, and the old format is still read until then. See `UPGRADING.md` before rolling back to an earlier release.
- Centralized CSRF-refusal reporting in `Lotgd\Forms`: core operation and request guards now log each rejection once through `Lotgd\SecurityLog`, with the page, operation, scope, method, caller-supplied record context, severity, and a correlation diagnostic. Affected core pages no longer maintain duplicate per-page logging paths.
- Added executable CSRF coverage for guarded core pages and tightened bank request validation without blocking module-owned operations.
- Strengthened SQL-interpolation and request-normalization analysis so concatenation, helper-returned queries, comments, definitions, nullsafe calls, and token edge cases cannot hide unsafe request values.
- Added behavior-based coverage for automatic login bans without recording attacker-controlled failures as unlimited persistent log rows.

### Tests

- Replaced brittle source-text assertions with behavioral coverage for request guards, combat, banks, level-ups, buffs, navigation, error handling, and login throttling; consolidated duplicated fixtures and made randomized execution reliable.
- Isolated cron exception fixtures and bootstrap logs in per-test temporary trees, protected the checkout from concurrent or interrupted runs, and report subprocess and cleanup failures precisely.
- Improved diagnostics for flaky environment-dependent tests so skips and failures state what was actually detected rather than guessing at the cause.
- Added stylesheet scanners that verify every generated button/action class is usable in every bundled theme and correctly parse nested rules, composed classes, and both quote styles.
- The suite no longer deletes a developer's `dbconnect.php`. Eleven suites resolved the repository-root file and removed it, so an ordinary `composer test` in an installed checkout destroyed its database configuration. They now borrow it by moving it aside and back, which preserves its contents, mode and owner, and a run that is killed outright leaves it recoverable: the next run puts it back.
- `LegacyRollDamageTest`'s god-mode check no longer fails at random. It asserted over a single random round, and `BellRand` clamps at about three sigma, so a roll against any range comes back at exactly zero about once in 750 and the "hopeless" control creature occasionally won; it now counts over thirty rounds.

### Docs

- Added `docs/InstallWebspace.md`, a step-by-step guide for shared hosting with FTP and a control panel: downloading the code from GitHub (Code → Download ZIP), where the game and its data cache go, the upload (keep `vendor/`, check `.htaccess` arrived), each installer stage, deleting `installer.php`, checking in a browser that internal files answer 403, first settings including mail, and updating. README's "Install from Release Archive" section, which pointed at release downloads that are not published, now points to it.
- Corrected the README's SMTP section: the settings are edited under Game Settings, not in `config/configuration.php`; the auth key is `gamemailsmtpauth`, not `gamailsmtpauth`; and it now says that SMTP is used only when a password is set and that port and encryption apply only with SMTP Auth on. Cron is described as optional, which it is.
- Documented the combat, companion-aura, mail action hook, CSRF execution harness, and custom-theme action-row behavior introduced above.
- Corrected the deprecation version of `Lotgd\UserLookup::lookup()` from 2.9.0, a release that does not exist, to 2.0.4, where it shipped — in `docs/Deprecations.md`, its docblock, and the runtime deprecation notice modules see. Added the missing entry for `Lotgd\Translator::tlbuttonClear()`, also deprecated in 2.0.4, and core no longer calls it.

## [2.0.7] – 2026-09-09

### Changed

- Refactored full and preference-only character restoration around a shared service, with safer serialized preference handling.
- Modernized administrator and installer password updates, including migration from legacy hashes to the current password format.
- Added a megauser-only, read-only Diagnostics console with time-window and severity filters, combining runtime and schema health, a security/warning/error/failed-login timeline, and detailed game, failed-login, character-audit, and profiling views.
- Renamed `Sanitize::fullSanitize()` to `Sanitize::stripAllColorCodes()` to clarify that it removes colour codes rather than providing general-purpose escaping. The old method remains a deprecated 2.x compatibility alias, and the legacy `full_sanitize()` wrapper delegates to the replacement.
- Replaced the async `mail_debug` setting with `debug_console`; the compatibility key remains accepted without changing polling intervals.
- Updated dependencies, including Twig 3.27, and upgraded PHPStan to 2.x.

### Fixed

- Normalized PayPal template placement without requiring custom template changes, clamped news pagination, validated resurrection-turn settings, and hardened legacy source and bad-word pages.
- Rendered combat round summaries only for rounds that were actually processed.
- Corrected MoTD poll choice handling, mount grants and serialized buffs, editor confirmation/translation behavior, and Docker installer/readiness edge cases.
- Resolved Jaxon dispatch from its callable descriptor and prevented legacy request fields from overriding a present Jaxon call.

### Security

- Added shared CSRF issuance and validation, protected state-changing core forms and destructive operations with POST tokens, kept module-owned operations reachable, and rejected state-changing GET requests.
- Added same-origin async CSRF headers and enforcement, vendored the matching Jaxon client, and required callable-specific superuser permissions instead of treating authentication as authorization.
- Parameterized request-derived SQL values and added changed-line CI enforcement against unsafe SQL interpolation.
- Centralized security outcomes in `Lotgd\SecurityLog`, with consistent categories, severity, and diagnostic correlation across the game and PHP logs.
- Hardened MoTD voting, legacy item editors, mount grants, forwarded-proxy trust, Docker secrets and file permissions, the web document boundary, and installer state persistence.

### Performance

- Released the PHP session lock before read-only async polling, cached and compressed Docker-served assets, and moved the container build to a maintained prebuilt PHP runtime.

### CI

- Moved workflow actions to Node.js 24, scoped workflow token permissions, strengthened multi-platform Docker smoke/readiness checks, and added stale base-image detection.

### Tests

- Isolated async configuration fixtures, expanded Docker security and readiness coverage, and added regression coverage for SQL binding, proxy trust, CSRF, MoTD, mount, password-migration, and async authorization behavior.

### Docs

- Expanded Docker installation, proxy, credential-rotation, backup/restore, update, resource-limit, and troubleshooting guidance; documented the navigation allowlist and deployment changes.

## [2.0.6] – 2026-04-19

### Dependencies/Tooling

- Refreshed Composer dependencies and committed vendor assets, including PHPUnit, Jaxon, Symfony polyfills, and related tooling updates.

## [2.0.5] – 2026-04-10

### Features
- Add centralized runtime hardening bootstrap so deployments get safer defaults for HTTPS detection, proxy signaling, and request-surface protections out of the box.
- Improve installer security defaults and enable stronger admin guidance via the recommendations module during first-run setup.
- Extend the charrestore module with a user-prefs-only restore option so admins can overwrite preference data without replacing full character records.

### Security
- Harden HTTPS/proxy detection by validating forwarded-proto handling paths, including `HTTP_FORWARDED_PROTO` and related trusted-header guardrails.
- Strengthen payment/IPN duplicate handling with canonical paylog resolution, idempotency checks, and safer failure behavior when canonical rows cannot be resolved.
- Continue the superuser/core SQL hardening wave by parameterizing high-risk paths across create/referers/creatures/masters/modules/paylog/payment code paths.
- Enforce and tighten the SQL `addslashes` QA baseline to block regressions back to unsafe query construction patterns.

### Bug Fixes
- Fix payment/IPN edge cases around duplicate callbacks, canonical paylog selection, and retry consistency so credits are applied exactly once.
- Resolve follow-up regressions in proxy-aware HTTPS detection and runtime hardening bootstrap behavior across mixed hosting/proxy setups.
- Correct module/object preference cache invalidation and related typing issues discovered during the SQL hardening migration wave.
- Keep navigation access-key generation stable during holiday mode so keybindings no longer depend on seasonal rendering changes.
- Fix user-account config save/load handling when optional settings are empty, preventing dropped values and inconsistent persistence.
- Cast moderated commentary timestamps to integers to avoid type-related moderation issues on timestamp handling.

### Refactor
- Migrate additional legacy SQL reads/writes to Doctrine DBAL with explicit typed bindings across superuser and core maintenance/admin flows.
- Normalize query structure in multiple admin endpoints to reduce ad-hoc SQL handling and improve long-term maintainability.

### Dependencies/Tooling
- Transition CI and release workflows to Node.js 24 in GitHub Actions.
- Raise static analysis memory defaults and tighten QA tooling heuristics to keep large security migration waves reliable in CI.
- Add a manual release `workflow_dispatch` path, increase release artifact retention, and tighten CI cache-key strategy for more reliable pipeline runs.

### Docs
- Clarify canonical payment idempotency policy and duplicate-IPN test scope for operators and contributors.
- Add/expand security review and hardening checklist guidance for repository contributors.

### Tests
- Add and extend regression coverage for runtime hardening, forwarded-proto handling, payment/IPN idempotency, and superuser endpoint SQL hardening waves.
- Strengthen SQL QA baseline tests to ensure `addslashes` enforcement remains stable as additional legacy paths are migrated.

## [2.0.4] – 2026-03-10

### Features
- Add optional two-factor authentication (TOTP) with QR/manual enrollment and a staged challenge flow during login.
- Add reCAPTCHA v3 integration for pre-login verification hardening.

### Security
- Migrate password handling to bcrypt with `password_algo` tracking and align installer/login password flows with the new helper behavior.
- Harden login and failed-attempt persistence paths against SQL injection risks and schema drift edge cases.
- Restrict public error rendering and expand security coverage around error handling and authentication flows.

### Bug Fixes
- Stabilize 2FA challenge navigation/redirect handling across repeated logins, subdirectory installs, and failed token submissions.
- Keep 2FA challenge state active across retries and normalize stored allowed-navigation data for resume flows.
- Guard invalid time preference values to prevent date argument type errors in preference handling.

## [2.0.3] – 2026-01-28

### Dependencies
- Upgrade Doctrine ORM to 3.x and Doctrine DBAL to 4.x, aligning the core stack with current Doctrine releases.

### Bug Fixes
- Adjust Doctrine bootstrap configuration to use the ORM 3 attribute driver and field declaration reporting so metadata validation matches ORM 3 expectations.
- Prefix Doctrine join tables when table prefixes are enabled, preventing mismatched table names during metadata loading.
- Normalize DBAL 4 result handling and connection setup so affected-row counts and write operations behave consistently across the legacy wrappers.
- Align mail persistence queries with the DBAL 4 execution flow to keep mailbox updates stable during upgrades.

### Docs
- Document ORM 3 attribute metadata and DBAL 4 migration guidance in the upgrade notes.

## [2.0.2] – 2025-11-27

### Bug Fixes
- Guard mount buff application on new day so characters without mounts do not trigger buff application errors.
- Initialize the output instance in the mercenary camp heal navigation to avoid rendering notices during heal flows.

## [2.0.0] – 2025-11-26
### Features
- Add asynchronous ban lookups so moderators can review affected accounts inline without leaving the list views.
- Refresh the mail popup navigation with button-style quick links and theme styling hooks for modern layouts.
- Enable zlib compression by default.
- Remove legacy settings stub and streamline cron handling.
- Expanded logging: game log entries can include account IDs, user management and module lifecycle actions, and anonymous entries show a system label.
- Account cleanup now runs inside a database transaction for safer deletions.
- Mail delivery helpers expose PHPMailer error details so admin tools can surface actionable diagnostics.
- Add "Test SMTP settings" action to `configuration.php` to send a diagnostics email.
- Introduced severity metadata and filtering support for game log entries, including database migrations and automated coverage.
- Added the Aurora Minimal Twig theme with responsive light and dark styling options.
- Restyled the installer confirmation stage to better communicate upgrade paths and requirements.
- Added example modules showcasing a forest reward encounter and a village gem shop integration.


### Refactor
- Centralize admin player lookup logic on the PlayerSearch service, extending reuse across bank transfers, mail compose flows, and donor tools.
- Migrate module installation routines to Doctrine parameter binding for activation, uninstall, and reinstall paths while keeping cache invalidation intact.
- Standardize top-level scripts to use `__DIR__` in `require` statements for safer path resolution.
- Remove redundant battle buff wrappers.
- Localized mount editor dependencies and continued migrating legacy entry points toward namespaced services.


### Docs
- Clarify the repository's expectations around adding new files to `lib/` in the contributor guidelines.
- Clarify newday cron configuration and cron job setup instructions.
- Add module hook reference documentation.
- Document contributor guidelines and static analysis in maintenance docs.
- Clarified cron job configuration details in the README and admin guide.
- Highlighted DragonPrime community resources and the successor project in the README.
- Documented Docker usage for the PHP 8.3 Apache image.

### Bug Fixes
- Parameterize ban creation, search, and removal flows so moderation tools log out affected players safely and avoid injection vectors.
- Bind parameters throughout petition submissions, news inserts, and debug logging helpers to harden persistence routines against crafted input.
- Rely on Doctrine-powered mail workflows for composing, sending, and listing messages so subject/body data stays sanitized and mailbox state remains accurate.
- Parameterize system mail lookups and inserts so notification deliveries stay sanitized when addressing account IDs.
- Guard admin search helpers by routing list, mail, and donation lookups through PlayerSearch, ensuring consistent escaping and locked-account handling.
- Stabilize asynchronous polling by parameterizing commentary refreshes and surfacing a timeout banner when sessions expire during background checks.
- Guard against missing city and theme parameters and ensure `diddamage` defaults to zero.
- Use safe array access for player name lookup and tighten module migration checks.
- Preserve the `Settings` singleton when loading extended settings or templates.
- Roll back character cleanup when skipped and log deletions only after commit.
- Add unsuspend buff wrappers and seed default navigation for new characters.
- Normalize withdraw log category to lowercase.
- Show system label for anonymous gamelog entries and record account IDs in maintenance logs.
- Show "Deleted User" placeholder when reading mail from deleted accounts instead of erroring.
- Extend the template preference cookie to one year to prevent theme resets.
- Cast equipment editor and hidden field values to strings to avoid PHP type errors on listings.
- Default the game log listing to newest-first ordering and preserve chosen sort parameters between requests.
- Hardened cron bootstrap and error handler wiring to initialize notifications safely before legacy includes.
- Resolved numerous installer upgrade edge cases, including table prefix syncing, stage gating, and migration auto-detection.
- Tightened validation across preference previews, mount editors, clan removal, referral handling, and mail replies to eliminate PHP warnings and bad input.
- Corrected malformed timestamps when reviewing pending email changes.

## [2.0.0-rc12] – 2025-09-06

### Dev / Tooling Enhancements
- Integrated **PHPStan** static analysis into the development workflow. Pulling in `phpstan/phpstan`, including configuration for Doctrine and PHPUnit extensions to ensure cleaner, more maintainable code.
- Added **Psalm** alongside PHPStan for additional static type coverage and complementary error detection.
- Enhanced CI pipeline with linting, PHPStan, Psalm, and PHPUnit; enabling pre-commit and GitHub Actions support for rapid feedback and code quality enforcement.
- Configured IDE integration (e.g., PhpStorm) to run Psalm/PHPStan on the fly, enabling real-time editor warnings and fixes. :contentReference[oaicite:0]{index=0}

### Bug Fixes & Minor Improvements
- Addressed minor issues discovered via static analyzers — cleaned up undefined variable notices, type-stability warnings, and optimized function signatures.
- Fixed legacy annotation compatibility in comments (`@psalm-` and `@phpstan-`) to avoid tool conflicts. :contentReference[oaicite:1]{index=1}

### Summary
These enhancements significantly improve code quality, developer trust, and long-term maintainability—while preserving the legacy engine compatibility.


---

## [2.0.0-rc11] – 2025-08-29
**Final release candidate for 2.0.0**

### Features
- Improved Twig template engine with caching.
- Finalized async/Ajax structure with Jaxon.
- Better admin notifications for async and LoGDnet errors.

### Bugfixes
- Final installer polish.
- Commentary and mail system stability.
- Minor LoGDnet fixes.

---

## [2.0.0-rc10] – 2025-08-25
### Features
- Admin notifications for LoGDnet/async errors (requires cache).
- Favicon handling in installer.

### Bugfixes
- DB prefix handling centralized and fixed in migrations/installer.
- Safe rollback tests in installer.
- Null/credential handling improvements in install stages.
- Logging for migration errors.

---

## [2.0.0-rc9] – 2025-08-22
### Features
- Async mail auto-refresh + incremental commentary without reload.
- Configurable async rate limiting (defaults ~1 request/sec).
- Translator placeholder hardening (`sprintf` style checks).
- LoGDnet listing guards and error handling.

### Bugfixes
- Async/Jaxon bootstrap sequence corrected.
- Commentary sanitization and pagination fixes.
- PvP mail translation now supported.
- Recipient selection and reply flow fixes in mail.
- Numerous installer polish issues resolved.

---

## [2.0.0-rc8] – 2025-08-05
### Features
- **Doctrine ORM + Migrations** integration.
- **Doctrine DBAL** used across core.
- Added `migrations/` tree with upgrade paths.
- PHP **8.3 baseline** documented.

### Refactor
- Broad PSR-4 namespacing (`Lotgd\...`).
- Composer autoload integration for game + modules.
- Strict typing introduced in many subsystems.
- Expanded `src/Lotgd/Config` structure.

---

## [2.0.0-rc6 → rc7] – July 2025
### Features
- Twig templating becomes default (classic HTML templates still supported).
- MOTD preview and UI options.
- Canonical link support.

### Bugfixes
- Commentary quote rendering tests.
- Forest fight XP calculation with floats.
- DK reset defaults corrected.
- Debug log sanitization.

---

## [1.3.2] – 2025-06-02
### Summary
- **Final 1.x line release.**
- Numerous small bugfixes across installer, gameplay, and UI.
- PHP 8 compatibility patches.
- Early Composer wiring for PHPMailer.
- Security patch for `motd.php` injection.

---

# Categories Overview

### Features
- Twig template engine with skin folders and caching.
- Async UX improvements (mail refresh, commentary updates, Jaxon).
- LoGDnet enhancements with safer listing and error logging.
- Mailing via PHPMailer (Composer-managed).
- Translation overhaul with positional placeholders and checks.
- SEO canonical links.

### Refactor / Architecture
- Composer-first, PSR-4 namespaces (`Lotgd\...`).
- Doctrine DBAL + ORM + migrations.
- PHP 8.3 baseline.
- New config structure (`src/Lotgd/Config`).
- Async code moved to dedicated directories.

### Bugfixes
- Installer hardened (stages, DB prefixes, rollback, favicon).
- Mail recipient/reply bugs resolved.
- Commentary sanitization and pagination.
- Forest XP/DK/biography/defeat translations fixed.
- Debug log and counter increments corrected.

### Security
- Safe unserialize for sessions.
- Auth guards on Ajax endpoints.
- LogdNet listing hardened.
- Ajax requests rate-limited (HTTP 429 on abuse).
- PHPMailer kept current via Composer.

### Developer Experience
- Extensive PHPUnit coverage for installer, async, translator, Doctrine.
- Migration logging and DB prefix tests.
- Improved error messages in upgrade/migrate paths.

### Breaking Changes
- Twig is default; template hooks updated.
- Namespacing and strict typing throughout.
- Doctrine migrations required; legacy upgrade first.
- Async endpoints rewritten for Jaxon.
- PHP 8.3 minimum version.

---

*👉 For step-by-step instructions, see [UPGRADING.md](UPGRADING.md).*
