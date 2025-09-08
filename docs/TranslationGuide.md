# Translation System Guide

## Introduction
The Legend of the Green Dragon (LotGD) engine ships with an extensible translation system.  Text is stored in the database and retrieved at runtime so the game can be fully localised.  Namespaces isolate groups of phrases and the engine automatically falls back to the English source when a translation is missing.

## Quickstart for New Translators
1. **Collect new strings** – enable translation collection in the superuser settings and browse the game.  Untranslated phrases are written to the `untranslated` table.
2. **translatorlounge.php** – visit `translatorlounge.php` to see the overview of languages and grant yourself translator access.
3. **translatortool.php** – open `translatortool.php` to translate strings in the selected namespace.
4. **untranslated.php** – use `untranslated.php` to review and prune entries that were collected but should not be translated.

Example workflow:
```bash
# Log in as a translator and browse the game to collect text
# Then open the translation tool in your browser
https://example.com/translatortool.php
```

## Administrator Guide
- **Configuration** – adjust translation options in `config/configuration.php` such as the default language and whether untranslated phrases are collected.
- **Permissions** – assign the "Translator" flag on user accounts via the superuser editor or through `translatorlounge.php`.
- **Database backups** – regularly export the `translations` table:
  ```bash
  mysqldump -u lotgd -p lotgd translations > translations.sql
  ```

## Developer Guide
Use the helper functions to integrate translations into code:

```php
$greeting = translate('hello', 'my-module');
output("`@%s`0", $greeting);

tlschema('my-module');
output("A namespaced line");
tlschema();
```

Namespaces should match the module or page name.  The optional `translationwizard` module can be installed to aid module developers by scaffolding namespace files and simplifying string exports.

Sample setup steps:
```bash
# From the modules repository
cp modules/translationwizard.php modules/
# Enable the module in the game
```

## Best Practices
- **Naming conventions** – use lowercase namespaces separated by hyphens (e.g. `cities-market`).
- **Collaboration** – coordinate via version control or the translator lounge before editing shared strings.
- **Database maintenance** – periodically remove unused namespaces and back up the `translations` and `untranslated` tables.
