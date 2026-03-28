## Deprecations Policy (2.x)

This project aims to preserve legacy compatibility while moving to a modern stack. Deprecations are communicated here and via `CHANGELOG.md`.

### Policy
- Deprecated APIs remain available for at least one minor release after deprecation is announced.
- Calls to deprecated APIs may trigger PHP notices in debug mode or be logged.
- Removal occurs in the next major release (e.g., deprecated in 2.1 → removed in 3.0), unless a critical security or maintenance constraint requires earlier removal.

### Current Deprecations

- Legacy template system (`templates/*.htm`)  
  - Status: Deprecated, still supported in 2.x  
  - Replacement: Twig templates under `templates_twig/<skin>/`  
  - Migration: Port HTML chunks to Twig views; see skin structure in README and sample template config.

- Global helper includes under `lib/*.php`  
  - Status: Deprecated shims  
  - Replacement: Namespaced classes under `Lotgd\...`  
  - Migration: Replace direct `require` usage with Composer autoloaded classes.

- Raw SQL access patterns  
  - Status: Deprecated where Doctrine is available  
  - Replacement: Doctrine ORM/DBAL repositories and migrations  
  - Migration: Move writes/reads into services or repositories; create migrations instead of ad‑hoc SQL.

- Legacy database wrapper (`Lotgd\MySQL\Database::query()` / `Database::fetchAssoc()` loops)  
  - Status: Legacy compatibility (modules), discouraged for new core code  
  - Replacement: `Database::getDoctrineConnection()` with `Result::fetchAssociative()` / `fetchAllAssociative()`  
  - Migration: Replace wrapper loops with DBAL results and explicit parameter typing.

- Legacy SQL string concatenation in core paths
  - Status: Deprecated milestone **2026-03-27** (core paths only)
  - 2.x compatibility: Existing module wrappers and legacy module code paths remain supported in 2.x for backward compatibility.
  - Replacement: Doctrine DBAL prepared statements via `Database::getDoctrineConnection()` (or equivalent Doctrine abstractions).
  - Removal target: Next major release (3.0) for core/refactored paths; module maintainers should migrate before upgrading.

- Custom Ajax endpoints not using Jaxon
  - Status: Deprecated
  - Replacement: Jaxon-based async calls under `async/`
  - Migration: Wrap Ajax actions with Jaxon controllers; adhere to rate limiting.
- `Lotgd\UserLookup::lookup()`
  - Status: Deprecated in 2.9.0
  - Replacement: `Lotgd\PlayerSearch::legacyLookup()` and other `PlayerSearch` helpers
  - Migration: Inject or instantiate `PlayerSearch` directly and call `legacyLookup()` (or a more specific finder), removing usage of the legacy array/SQL wrapper.

- Legacy HTTP wrappers `httpget()` / `httppost()` in core/refactored paths
  - Status: Deprecated in 2.x for core and refactored modules (legacy compatibility only)
  - Policy: `Lotgd\Http` is the only allowed HTTP API in core/refactored code; legacy wrappers are reserved for legacy/module compatibility paths.
  - Behaviour note: `lib/http.php` wrappers intentionally preserve escaped legacy semantics for compatibility, while `Lotgd\Http` returns raw request values for typed and parameterized handling.
  - Migration: Replace legacy helper calls with `Lotgd\Http::get()` / `Lotgd\Http::post()` and use bound DBAL parameters instead of SQL string concatenation.
  - QA enforcement: `composer static` now runs a policy gate that fails when new wrapper usage appears in core/refactored paths.

### Security Guidelines for Input and SQL (Core Paths)

- **No global pre-escaping of superglobals**: do not rely on `addslashes()` wrappers around `$_GET`, `$_POST`, or compatibility helpers as an SQL safety boundary.
- **Validate/cast at input boundaries**: normalize user input as it enters a feature (for example `int`, `bool`, constrained enum/string), and keep those typed values through the call chain.
- **Parameterized SQL is mandatory for new/updated code**: use Doctrine DBAL `executeQuery()` / `executeStatement()` with explicit parameter arrays and types at the query sink.
- **Legacy wrapper escape semantics are compatibility-only**: `lib/http.php` retained behavior is for legacy module compatibility and must not be used as justification for SQL string concatenation in core/refactored paths.

### Upgrade Guidance (1.3.x → 2.0)

See `UPGRADING.md` for the full process. Key points:
- Require PHP 8.3+, install via Composer, run legacy upgrade then Doctrine migrations.
- Enable data cache with a writable `DB_DATACACHEPATH`; Twig caches to `<path>/twig` when writable.
- zlib output compression defaults on when the extension is present.

### Contact
Open an issue if you need a longer grace period or migration examples for a specific API.
