# Legacy PHP 8.2 Installation Guide

This project targets PHP 8.3+ for new deployments. If your hosting provider only offers PHP 8.2, you can still run Legend of the Green Dragon by applying the adjustments below. These changes loosen the version guard hile keeping the codebase otherwise identical, so you can upgrade to PHP 8.3 later without reinstalling the game.

## Before you begin
- Ensure your PHP 8.2 environment includes the required extensions: `mysqli`, `pdo_mysql`, `mbstring`, `json`, `intl`, `zip`, and `curl`.
- Back up your database and any customized files before making changes.
- Run all Composer commands with the same PHP 8.2 CLI that will power the site.

## Step-by-step changes
1. **Adjust Composer's platform setting**  
   Open `composer.json` and set the platform constraint:
   ```json
   "config": {
       "platform": {
           "php": "8.2.0"
       }
   }
   ```
   This tells Composer to resolve dependencies compatible with PHP 8.2.

2. **Refresh the lock file under PHP 8.2**  
   Run the following commands to reinstall dependencies with PHP 8.2 compatibility:
   ```bash
   rm -rf vendor/
   composer update --lock
   composer install
   ```
   The first command clears any vendor directory built with PHP 8.3 so Composer can rebuild it for PHP 8.2.

3. **Relax the installer check**  
   In `installer.php`, find the PHP version guard:
   ```php
   if (version_compare(PHP_VERSION, '8.3.0', '<')) {
   ```
   Change it to allow PHP 8.2:
   ```php
   if (version_compare(PHP_VERSION, '8.2.0', '<')) {
   ```
   Update the accompanying error message so administrators understand PHP 8.2 is the minimum in this mode.

4. **Document the legacy runtime**  
   To help other maintainers, note the temporary PHP 8.2 requirement in your internal documentation or server provisioning scripts.

## Post-install checklist
- Run `composer test` to confirm the downgraded environment passes automated checks.
- Browse through the installer and first login to ensure no warnings mention the PHP version.
- Plan your upgrade back to PHP 8.3 or newer before PHP 8.2 reaches end-of-life.

## Reverting the changes
When PHP 8.3 becomes available for you, revert the edits above by restoring the original files (or checking out the latest upstream release), then run `composer install` with PHP 8.3 to return to the default, fully supported configuration.
