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

- Custom Ajax endpoints not using Jaxon  
  - Status: Deprecated  
  - Replacement: Jaxon-based async calls under `async/`  
  - Migration: Wrap Ajax actions with Jaxon controllers; adhere to rate limiting.

### Upgrade Guidance (1.3.x → 2.0)

See `UPGRADING.md` for the full process. Key points:
- Require PHP 8.3+, install via Composer, run legacy upgrade then Doctrine migrations.
- Enable data cache with a writable `DB_DATACACHEPATH`; Twig caches to `<path>/twig` when writable.
- zlib output compression defaults on when the extension is present.

### Contact
Open an issue if you need a longer grace period or migration examples for a specific API.


