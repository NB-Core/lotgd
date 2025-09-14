# Upgrading Guide

This document explains how to upgrade from **LotGD 1.3.x** to the new **2.0.x** branch.  
The 2.0 line introduces major architectural changes (Composer, PSR-4, Doctrine, Twig, Async), so please follow these steps carefully.

---

## 1. Requirements

- **PHP 8.4** (minimum)  
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

- **Performance Defaults**  
  - Output compression via zlib is enabled by default when the `zlib` extension is present. Disable at the PHP level if undesired.  
  - Data cache requires a writable directory: set `DB_USEDATACACHE=1` and `DB_DATACACHEPATH=/path/to/cache`. The app will warn admins if the path is missing or not writable.  
  - Twig will cache compiled templates under `<datacachepath>/twig` when writable; otherwise it runs without caching.

---

## 7. Breaking Changes

- **Namespaces**: Core code moved to `Lotgd\...`. Custom modules calling internal functions may need refactoring.
- **Twig**: Default rendering pipeline. Legacy template hooks may not work without updates.
- **Doctrine**: Direct SQL hacks should be migrated to repositories or services.
- **PHP 8.4**: Old PHP 7.x-style code (e.g., deprecated array/string operations) will break.
- **Async**: All Ajax endpoints rewritten to use Jaxon.

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
- Once migrated, you’ll benefit from a modern stack: Composer dependencies, PHP 8.4 support, Twig theming, async UX, and Doctrine-managed DB schema.

---

👉 See also [CHANGELOG.md](CHANGELOG.md) for detailed release notes.
