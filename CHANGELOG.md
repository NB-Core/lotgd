# Changelog

All notable changes to this project will be documented in this file.  
This project follows semantic versioning (MAJOR.MINOR.PATCH) starting from 2.0.0.  

The last **official 1.x release** was [`v1.3.2`](https://github.com/NB-Core/lotgd/commit/2be7254fc2b68bf91e86d71361556667e826ecf1) (2025-06-02).  
Everything below reflects the path from 1.3.2 → 2.0 RCs.  

---

## [Unreleased]
### Features
- Enable zlib compression by default.
- Remove legacy settings stub and streamline cron handling.
- Expanded logging: game log entries can include account IDs, user management and module lifecycle actions, and anonymous entries show a system label.
- Account cleanup now runs inside a database transaction for safer deletions.
- Mail delivery helpers expose PHPMailer error details so admin tools can surface actionable diagnostics.
- Add "Test SMTP settings" action to `configuration.php` to send a diagnostics email.


### Bug Fixes
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


### Refactor
- Standardize top-level scripts to use `__DIR__` in `require` statements for safer path resolution.
- Remove redundant battle buff wrappers.


### Docs
- Clarify newday cron configuration and cron job setup instructions.
- Add module hook reference documentation.
- Document contributor guidelines and static analysis in maintenance docs.

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