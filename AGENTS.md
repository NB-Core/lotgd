# AGENT Guidelines

This repository uses PHP with Composer and PHPUnit. To ensure quality and consistency, follow these policies when contributing code or running QA reviews.

## Project Goals & Scope

- Modernize the classic Legend of the Green Dragon codebase while preserving legacy gameplay and module compatibility.
- Prefer the modern stack (Composer/PSR-4, Doctrine, Twig, Async/Jaxon), but keep legacy `lib/*.php` and globals available for existing modules.
- Introduce deprecations gradually; avoid breaking changes within 2.x unless essential for security or stability.

## Testing

- Install dependencies with `composer install` before running tests.
- Execute the full test suite using `composer test`.
- New or changed features should include appropriate tests under the `tests/` directory.
- Run static analysis locally with `composer static`; fix high/medium findings before opening a PR.
- SQLite-based tests are supported; full DB integration tests are optional and can be run manually in a dev environment.

## Coding Standards

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding style (4-space indentation, braces on the next line, etc.).
- Include PHPDoc blocks for functions and classes when relevant.
- Keep functions small and focused. Avoid unrelated refactoring in a single PR.
- Prefer namespaced classes under `Lotgd\\...` for new code. Legacy wrappers in `lib/*.php` may still be used for module compatibility, but new core code should avoid adding to the legacy layer.
- Do **not** create new files inside `lib/`; that directory is reserved for legacy modules and wrappers only. Core development must extend the modern namespaced classes under `Lotgd\\...` and related services instead of adding to the legacy layer.

## Pull Request Checklist

Before opening a PR:

1. Ensure all tests pass with `composer test`.
2. Run `php -l <file>` on changed PHP files to check for syntax errors.
3. Run `composer static` (PHPStan) and address findings where feasible.
4. Update or add documentation when necessary.
5. Provide a clear description of the change and reference related issues.
6. Keep the diff minimal, focusing only on the feature or fix.
7. If behavior changes, update `UPGRADING.md` and/or `docs/Deprecations.md` accordingly.

Recommended commit convention: follow Conventional Commits (`feat:`, `fix:`, `perf:`, `docs:`, `refactor:`, `chore:`) to improve release notes.


## Security Review Checklist

For any PR touching **authentication, session handling, admin/superuser flows, async endpoints, or SQL execution**, include a short security review note in the PR description that confirms all of the following:

- Input validation boundary is defined (where untrusted input enters and where it is validated/cast).
- CSRF coverage is present for all state-changing requests. Tokens come from `Lotgd\Security\Csrf`: render with `Csrf::hiddenField()` or `Csrf::escapedToken()`, validate with `Csrf::validatePost()` or `Csrf::validatePostRequest()`, and do not add a new per-page session key. Only the issuing methods create a token — validation paths must not, so a mistyped scope fails closed and async code, where the session lock may already be released, cannot mint one it then discards.
- A page that hands its whole POST body to a settings or preference writer runs it through `Csrf::stripFrom()` first, or the token is persisted as data. This applies to *both* token fields, which is why `stripFrom()` removes both: a form built by `Forms::showForm()` adds `Csrf::FORM_FIELD` on top of any page-level `Csrf::FIELD`.
- A form built by `Forms::showForm()` or `showFormTabbed()` already carries a token — do not add a second one under the same name, because PHP keeps the last input of a given name and the page's own token would be the one lost. Validate it with the single line `Forms::validateCsrf()` in the branch that writes, and use `Forms::csrfField()` for a handwritten form that posts to the same script.
- A page that changes state carries `Forms::isUnverifiedCoreOp($op, [...])` once, at its entry, right after it reads `$op` — not a check per branch, which the next branch someone adds is the one to forget. It clears both `$op` and `$_POST`, because a delete keys off `$op` with its id in the query string. Pass a scope as the third argument only when the same script also issues a token to someone who must not reach the operation.
- **The op list is a boundary, not bookkeeping.** Modules render into core pages through hooks and may post forms of their own; `runmodule.php` is not guarded at all. An `$op` the page does not implement belongs to a module and must pass through untouched, or the release silently breaks working modules. List only what the core itself handles, and extend the list when you add an operation.
- A page whose write keys off a posted field rather than `$op` puts `Forms::isUnverifiedRequest()` at the write and names the fields, for the same reason. Same for an operation that is also a *view* (`configuration.php?op=modulesettings`, `untranslated.php?op=list`): guard the write, not the op.
- **A GET is unverified.** The guard answers "is this a POST carrying the right token", so a GET asking for a core state change is refused — that is the point, since a crafted link was the original hole. What keeps browsing working is where the question is asked, never the method. If a state-changing link would break, the link is the bug: make it a `Forms::postButton()`.
- A destructive trigger is `Forms::postButton()`, never a link and never a hand-built form. It owns the POST method, the token, the escaping and the confirmation.
- Output encoding goes through `Lotgd\Security\Escape`: `html()` for markup, `js()` for script, `confirmAttribute()` for a confirmation handler. Never `addslashes`, never `htmlentities` into JavaScript, never a raw interpolation — translated strings come from a table `SU_IS_TRANSLATOR` writes and are not constants.
- A destructive operation (deleting an account or a character, executing arbitrary SQL) is triggered by a POST carrying a token, never by a link. A `Nav::add()` entry beside the form does not make a GET safe: `ForcedNavigation` matches the URI and ignores the method, and `SameSite=Lax` sends the session cookie on a top-level GET navigation. An `onClick` confirm is not a control — it never runs on a navigation the user did not start.
- An id naming *which* record to act on comes from the session when the action is about the caller's own account. The navigation allowlist narrows which URLs are reachable; it does not bind a value, so a helper that takes an id and does not compare it to the session must not be handed a request parameter.
- Prepared statements are used for SQL writes/reads; do not introduce new `addslashes`-based SQL patterns.
- Authorization checks exist for superuser and module-privileged actions.
- Security-relevant outcomes go through `Lotgd\SecurityLog::event()` (for example: auth failures, privilege changes, denied admin actions, suspicious async activity). It writes the game log's `security` category *and* PHP's error log in one call, with a shared `diag=` correlation id, so an operator finds the event where they look for it and can match it to the container log. Do not reach for `debuglog()` — that is a character's own audit trail of gold and experience, not a record of what the server refused — and do not use a bare `error_log()`, which no administrator reading `gamelog.php` will ever see.
- For a path an unauthenticated caller can repeat at will, pass `$persist: false` so the event reaches the error log without letting one request drive one database write. Persist the outcome that matters instead: the ban, not each guess. A rate limiter is not a substitute for this — it suppresses bursts, so a caller pacing requests just under the limit still writes a row per request, forever.
- A failure is expressed by the **severity** argument, never by inventing a category for it. Categories come from the `Lotgd\GameLog::CATEGORY_*` constants and name a subsystem; `gamelog.php` filters on both, and a failure hidden in its own category cannot be found by either filter.

These rules apply to all directories unless a more specific file overrides them.

## Compatibility & Deprecations

- Legacy template `.htm` files and `lib/*.php` wrappers are still supported in 2.x to keep existing modules working, but no new core files should be added to `lib/`.
- New code should use Twig and namespaced APIs; legacy hooks should not be expanded further.
- Deprecations are tracked in `docs/Deprecations.md`; removals target the next major version.

### Instances and Globals

- `Output` and `Settings` are singletons. Prefer `Output::getInstance()` / `Settings::getInstance()` and assign to a local variable if used multiple times in a scope for readability and performance.
- `Translator` uses static methods and properties; `getInstance()` exists but is not a true singleton. Prefer static calls: `Translator::translate()`, `Translator::sprintfTranslate()`, etc.
- Frequently-used legacy globals (e.g., `$badguy`) remain available for module compatibility and are not slated for refactor in 2.x.
- Modules may continue to rely on `lib/*.php` wrappers; new core code should prefer namespaced classes under `Lotgd\\...`.

## Performance Defaults

- zlib output compression is enabled by default when the `zlib` extension is present.
- Data cache and Twig cache require a writable `datacachepath`. Admins are warned in-game if the path is invalid.

## Release Process (summary)

- When ready: bump version in `common.php`, tag `vX.Y.Z`, push the tag. GitHub Actions builds release artifacts.
- Ensure `README.md` and `UPGRADING.md` reflect notable changes.

## Additional Guidance

### Error Handling

- Register `Lotgd\ErrorHandler::register()` early in entry points.
- Avoid suppressing errors with `@`. Catch exceptions only when meaningful recovery/logging occurs; never silently discard.
- Prefer explicit guards and early returns over broad try/catch.

### Translation

- For new strings, use `Translator` with appropriate schemas/namespaces. Avoid hardcoded user-facing text in core code.
- Use positional placeholders compatible with `sprintf` to support localisation (`%s`, `%d`).
- Keep module text within module-specific namespaces to prevent collisions.

### Async / Jaxon

- New Ajax features should use Jaxon. Respect configured rate limits (HTTP 429 on excess).
- Validate session/auth for async endpoints; avoid exposing privileged actions via unauthenticated calls.
- The endpoint carries a CSRF token (`Csrf::SCOPE_ASYNC`), issued by `async/setup.php` and sent as an `X-LotGD-Csrf` header by `async/js/lotgd.jaxon.js`. Request-handling code checks it and must never issue it: the session lock may already be released, so a token minted there is lost. `csrf_mode` in `config/async.settings.php` selects `off`/`log`/`enforce`.
- Authentication is not authorization. A handler is reached directly, so the `SuAccess::check()` on the page that renders its trigger never runs. A handler that needs rights needs an entry in `lotgd_async_required_superuser_bits()` in `async/process.php`. Do not call `SuAccess::check()` from a handler — it renders a page and kills the character on failure.
- Prefer small payloads and incremental updates (mail/commentary patterns) over full-page refreshes.

### Data Access

- Core: Prefer Doctrine DBAL/ORM for new features. Write schema changes as migrations.
- Modules: Raw SQL and legacy helpers are allowed; ensure types are correct (watch for prefs like `increment_module_pref`).
- Avoid coupling to specific MySQL quirks when DBAL/ORM can abstract them.

### Navigation Ordering

To keep menus consistent across core pages, modules, and events, follow this order when building navigation with `addnav()` (wrapper in `lib/addnav.php`; modern code may use `AddNav::add`):

- **Top headline**: usually "Navigation"
- **Back to Village/Forest/etc.**: include when applicable (`Lotgd\Nav\SuperuserNav::render()`, `Lotgd\Nav\VillageNav::render()` etc.)
- **Primary navigation options**: all general links for the current context
- **Top headline for actions**: usually "Actions"
- **Everything else**: author/module discretion

This convention preserves a predictable structure and improves usability across the game.