# Upgrading Guide

This document explains how to upgrade from **LotGD 1.3.x** to the new **2.0.x** branch.  
The 2.0 line introduces major architectural changes (Composer, PSR-4, Doctrine, Twig, Async), so please follow these steps carefully.

---

## 1. Requirements

- **PHP 8.3** (minimum)
  Earlier PHP versions are not supported.
- Database: MySQL 8.x or MariaDB 10.6+ recommended.  
- Composer installed (`composer --version`).
- A writable cache directory (for Twig templates and async operations).
- CLI access to run Doctrine migrations.

---

## 2. Backup First

Before doing anything:

- Dump your database:  
  ```bash
  mysqldump -u user -p dbname > backup.sql
  ```
- Copy your `public/` (or `htdocs/`) files and `config` directory.
- Ensure you can roll back if something goes wrong.

---

## 3. Update Codebase

1. Replace your old code with the new release (download or `git pull`).  
2. Keep your `config` folder (but update as noted below).  
3. Run `composer install` to pull required dependencies.

---

## 4. Run Legacy Upgrade (1.x → 2.x bridge)

If you are coming directly from **1.3.2** (or earlier 1.x):

1. Visit `/installer/` in the browser.
2. Run the **legacy upgrade** script.  
   - This brings your schema in line with the pre-Doctrine format.  
   - It also fixes historical issues (prefixes, indexes, null defaults).
3. Confirm the installer reports success.

---

## 5. Run Doctrine Migrations

Once the legacy upgrade is complete:

1. In your project root, run:
    ```bash
    php bin/doctrine migrations:migrate
    ```
2. The command reads `src/Lotgd/Config/migrations.php` and
   `src/Lotgd/Config/migrations-db.php` by default. If you use custom paths,
   supply `--configuration` and `--db-configuration` flags with the appropriate
   locations.
3. This will apply all new **2.x schema changes**.
4. Watch for any DB prefix issues — these are now supported and should migrate correctly.

### Advanced admins: CLI-only upgrade

If you prefer to skip the browser installer after copying a new **2.x** release,
you can complete the upgrade purely from the command line:

1. Copy the new release files over your existing installation (preserving the
   `config/` directory).
2. Run Composer to refresh vendor dependencies:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Execute the Doctrine migrations:
   ```bash
   php bin/doctrine migrations:migrate
   ```
4. Update the `settings` table so the `installer_version` value matches the
   `$logd_version` defined near the top of `common.php`. You can read the target
   version string directly from that file whenever a new release ships.

Example SQL for updating the installer version:

```sql
UPDATE settings
SET value = '2.x.y'
WHERE setting = 'installer_version';
```

Replace `2.x.y` with the exact value of `$logd_version` from your current
`common.php`. Skipping the browser installer is **only** safe when you already
have an upgraded **2.x** database; fresh installs and legacy bridge upgrades
still need the web installer to seed legacy SQL data and verify required
modules.

---

## 6. Configuration Changes

- **Async (Ajax)**  
  - Config is in `config/async.settings.php`.  
  - Default rate limit: ~1 request/second.  
  - Requests beyond this return HTTP 429. Adjust if needed.

- **Mail**  
  - Uses **PHPMailer** via Composer.  
  - Check your SMTP settings in `config/config.php`.  
  - `mail()` fallback is no longer recommended.

- **Templates**  
  - **Twig** is now the default.  
  - Templates live under `templates_twig/<skin>/`.  
  - Each skin requires a `config.json` and core files (`page.twig`, `popup.twig`).  
  - Old `.htm` templates still work but are deprecated.
  - Head rendering now supports `headscript_pre` and `headscript_mid` hook buckets, and base layouts emit head assets in the order: `headscript_pre`, Bootstrap assets, `headscript_mid`, `templates/common/colors.css`, template-specific styles, then `headscript`/`script`.

- **Doctrine Mapping**  
  - Entity mappings now rely on PHP attributes; annotation-based mappings are no longer supported.  
  - Ensure custom entities use attributes and remove any legacy annotation tooling dependencies.

- **Performance Defaults**  
  - Output compression via zlib is enabled by default when the `zlib` extension is present. Disable at the PHP level if undesired.  
  - Data cache requires a writable directory: set `DB_USEDATACACHE=1` and `DB_DATACACHEPATH=/path/to/cache` in `dbconnect.php`. The app will warn admins if the path is missing or not writable, even if a temporary fallback directory is used for resilience; those warnings are intentional and should be addressed by setting a stable, writable path.  
  - Twig will cache compiled templates under `<datacachepath>/twig` when writable; otherwise it runs without caching.

---

## 7. Breaking Changes

- **Namespaces**: Core code moved to `Lotgd\...`. Custom modules calling internal functions may need refactoring.
- **Twig**: Default rendering pipeline. Legacy template hooks may not work without updates.
- **Doctrine**: Direct SQL hacks should be migrated to repositories or services.
- **Doctrine ORM 3**:
  - The ORM dependency now targets 3.x and requires Doctrine DBAL 4.0+.
  - Bootstraps must replace `EntityManager::create()` with `DriverManager::getConnection()` plus the `EntityManager` constructor.
  - Attribute metadata should enable the `reportFieldsWhereDeclared` mode to align with ORM 3 validation.
  - Event listeners must use the dedicated event args classes instead of the deprecated `LifecycleEventArgs`.
  - Custom repositories must continue to extend `EntityRepository` and should be fetched via `getRepository(Fully\Qualified\Entity::class)`; string shorthand is no longer supported.
  - Table prefix subscribers need to prefix both primary tables and join tables using the new mapping object structures.
- **PHP 8.3**: Old PHP 7.x-style code (e.g., deprecated array/string operations) will break.
- **Async**: All Ajax endpoints rewritten to use Jaxon.

### DBAL 4 Migration Inventory (2.x)

The 2.x branch is already aligned with Doctrine DBAL 4 result APIs and parameter typing, but legacy wrappers still exist for module compatibility. The list below inventories current DBAL usage and maps it to DBAL 4 migration guide sections so you can evaluate upgrades or custom modules consistently.

**Legacy layer usage (DBAL calls via compatibility wrappers):**
- `src/Lotgd/MySQL/Database.php` → `Connection::executeQuery/executeStatement`, `Result::fetchAssociative/fetchAllAssociative`, `Result::rowCount`, `Connection::quote`.
- `src/Lotgd/MySQL/DbMysqli.php` → legacy `mysqli` driver implementation (no DBAL APIs, kept for modules).
- `src/Lotgd/MySQL/TableDescriptor.php` → schema discovery and DDL via `Database::query()` plus selective `Connection::executeStatement()` calls.

**Direct DBAL usage in core (`src/Lotgd/*`):**
- `src/Lotgd/Mail.php` (DBAL fetch/execute + parameter typing).
- `src/Lotgd/PlayerSearch.php` (executeQuery → `fetchAllAssociative`).
- `src/Lotgd/AddNews.php`, `src/Lotgd/Settings.php`, `src/Lotgd/GameLog.php`, `src/Lotgd/ModuleManager.php` (executeStatement).
- `src/Lotgd/RefererLogger.php`, `src/Lotgd/Translator.php`, `src/Lotgd/Motd.php`, `src/Lotgd/Newday.php` (fetchAssociative/fetchAllAssociative).
- `src/Lotgd/Async/Handler/Commentary.php`, `src/Lotgd/Async/Handler/Bans.php` (fetchAllAssociative + typed params).

**DBAL 4 migration guide mapping (what to watch for):**
- **Result handling changes**: `Result::fetchAssociative()` / `fetchAllAssociative()` usage replaces legacy fetch loops.
- **Statement execution**: `executeQuery()` returns `Result` for reads; `executeStatement()` returns affected row count for writes.
- **Parameter typing changes**: `ParameterType` / `ArrayParameterType` are used for explicit binding (string/int/array parameters).
- **Removed connection fetch helpers**: avoid `fetchAssoc`/`fetchArray`/`fetchColumn` in favor of typed `fetchAssociative()` or `fetchOne()`.

If you maintain custom modules, update any legacy calls to `Database::fetchAssoc()` loops by switching to the DBAL `Result` APIs and named parameters as shown in the refactoring examples above.

### Refactoring Legacy SQL to Prepared Statements

Legacy database calls often used `Lotgd\MySQL\Database::query()` together with manual escaping via `addslashes`. When upgrading, migrate those calls to Doctrine DBAL prepared statements obtained through `Lotgd\MySQL\Database::getDoctrineConnection()`. The following example shows how to convert a legacy lookup:

```php
// Before: manual escaping and direct query execution.
$db = Lotgd\MySQL\Database::getInstance();
$login = addslashes($login);
$sql = "SELECT acctid, name FROM accounts WHERE login='{$login}'";
$result = $db->query($sql);
```

Replace the manual escaping and `query()` call with a prepared statement that binds parameters. Doctrine handles quoting and typing, so `addslashes` (or similar functions) become unnecessary once the parameter is bound:

```php
$db = Lotgd\MySQL\Database::getInstance();
$conn = $db->getDoctrineConnection();

$sql = 'SELECT acctid, name FROM accounts WHERE login = :login';
$stmt = $conn->prepare($sql);
$stmt->bindValue('login', $login);
$result = $stmt->executeQuery();
```

You can also execute inline without calling `prepare()` explicitly when no cursor reuse is required:

```php
$result = $conn->executeQuery($sql, ['login' => $login]);
```

`executeQuery()` returns a `Result` object for `SELECT` statements, while `executeStatement()` returns the affected row count for `INSERT`, `UPDATE`, or `DELETE` queries. See [docs/Doctrine.md#prepared-statements](docs/Doctrine.md#prepared-statements) and the official [Doctrine DBAL prepared statement guide](https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/data-retrieval-and-manipulation.html#prepared-statements) for more details.

---

## 8. After Upgrade

- Test **installer logs** for warnings.  
- Check **LoGDnet integration** (settings updated).  
- Verify **email sending** works.  
- Clear and rewarm the Twig cache:  
  ```bash
  rm -rf var/cache/*
  ```
- Run your site and verify:
  - Login works  
  - Commentary updates async  
  - MOTD loads  
  - PvP mail sends correctly

---

## 9. Optional for Developers

- Run PHPUnit tests:
  ```bash
  vendor/bin/phpunit
  ```
- Explore new namespaces under `src/Lotgd/`.
- Review `CHANGELOG.md` for feature-by-feature changes.

---

## 10. Known Issues

- Modules using raw SQL or legacy template hooks may need manual updates.
- Some installer paths still produce warnings if cache is unwritable.
- If you used **custom commentary code**, review the new sanitization and pagination.

---

## Summary

- Backup → Update → Legacy Upgrade → Doctrine Migrate → Config Check → Test.  
- Expect to update **templates, modules, and custom code** for Twig, Composer/PSR-4, and Doctrine.  
- Once migrated, you’ll benefit from a modern stack: Composer dependencies, PHP 8.3 support, Twig theming, async UX, and Doctrine-managed DB schema.

---

👉 See also [CHANGELOG.md](CHANGELOG.md) for detailed release notes.
