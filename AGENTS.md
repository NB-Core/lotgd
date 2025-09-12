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

## Pull Request Checklist

Before opening a PR:

1. Ensure all tests pass with `composer test`.
2. Run `php -l <file>` on changed PHP files to check for syntax errors.
3. Run `composer static` (PHPStan) and address findings where feasible.
3. Update or add documentation when necessary.
4. Provide a clear description of the change and reference related issues.
5. Keep the diff minimal, focusing only on the feature or fix.
6. If behavior changes, update `UPGRADING.md` and/or `docs/Deprecations.md` accordingly.

Recommended commit convention: follow Conventional Commits (`feat:`, `fix:`, `perf:`, `docs:`, `refactor:`, `chore:`) to improve release notes.

These rules apply to all directories unless a more specific file overrides them.

## Compatibility & Deprecations

- Legacy template `.htm` files and `lib/*.php` wrappers are still supported in 2.x to keep existing modules working.
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