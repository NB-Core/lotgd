# Translation System Guide

This guide explains how to work with the Legend of the Green Dragon (LotGD) translation engine.
It is split into sections for translators, administrators and developers.  Newcomers should
start with the quickstart below and then explore the more specialised chapters.

## Introduction

LotGD stores nearly all player-facing text in the database so it can be translated at runtime.
Every translatable phrase belongs to a *namespace* (also called a schema).  When outputting
text the engine searches the `translations` table for the phrase in the current language and
namespace.  If no match exists the original English text is shown and, when collection is
enabled, the text is written to the `untranslated` table for later review.

### Core Components

- **Namespaces** – isolate phrases by page or module. Use `tlschema('page-name')` to switch.
- **translate()** – looks up a phrase in the current namespace.
- **translatorlounge.php** – dashboard for translators and a way to grant translator privileges.
- **translatortool.php** – edit existing translations in a namespace.
- **untranslated.php** – list of phrases still lacking a translation.

## Quickstart for New Translators

1. **Enable collection**
   - Log in as a superuser and set “Collect untranslated phrases” to *Yes* in game settings.
   - Browse the site; missing strings are stored in the `untranslated` table.
2. **Gain translator access**
   - Visit `translatorlounge.php` and grant your account the *Translator* flag if necessary.
3. **Translate strings**
   - Open `translatortool.php` and choose a namespace.  The tool shows the English text and an
     input box to save a translation.
4. **Review unknown entries**
   - `untranslated.php` displays phrases that were collected but remain untranslated.  Remove
     unwanted rows or provide a translation to move them into the `translations` table.

Example workflow:
```bash
# collect text by browsing the game
# then translate a namespace
https://example.com/translatortool.php
```

## Administrator Guide

Administrators control global translation behaviour and manage translators.

### Configuration
- Edit `config/configuration.php` to set the default language and whether untranslated text is
  collected automatically.  The engine can disable collection during heavy load to reduce DB
  writes.  `translatorCheckCollectTexts()` evaluates these settings periodically.
- Enable caching if the game serves large numbers of requests.  Translations are cached per
  page to reduce database queries.

### Managing Translators
- Grant the *Translator* superuser flag via the user editor or `translatorlounge.php`.
- Restrict namespaces through in-game module settings if certain areas should only be modified
  by trusted translators.

### Database Maintenance
- Back up translation tables regularly:
  ```bash
  mysqldump -u lotgd -p lotgd translations untranslated > translations_backup.sql
  ```
- Remove orphaned namespaces or obsolete languages to keep lookups fast.
- When restoring from backup ensure the character set matches the game configuration
  (UTF‑8 is recommended).

## Developer Guide

Developers integrate the translation engine directly in PHP code.

### Basic API
```php
// Switch to the "inn" namespace
tlschema('inn');
// Lookup a phrase in the current language
$greeting = translate('Welcome, traveller!');
// Output the translated phrase
output("`@%s`0", $greeting);
// Return to the default namespace
tlschema();
```
- Use `translate($string, $namespace)` when you do not want to change the global namespace.
- Place variables with `%s` or `%d` and pass them through `sprintf` or `output()` to keep word
  order flexible.

### Namespaces
- Use lowercase identifiers separated by hyphens such as `forest-combat` or `module-market`.
- Each module should keep its own namespace to avoid collisions with core pages.
- Wrap blocks of output with `tlschema()` when changing namespace temporarily.

### Collecting Strings During Development
- Enable text collection and exercise all code paths.  Check `untranslated.php` to verify that
  all phrases appear with the expected namespace.

## Translation Wizard Module

The optional `translationwizard` module streamlines large translation efforts and is useful
when bootstrapping a new language.

1. **Install** – copy `translationwizard.php` into the `modules/` directory.
2. **Activate** – enable it from the superuser module editor.
3. **Run the wizard** – the module scans modules and pages for namespaces and walks through
   each phrase, offering quick links into `translatortool.php`.
4. **Cleanup** – deactivate or remove the module once all strings are translated.

## Database Reference

Two tables drive the translation system:

- `translations` – stores `language`, `namespace`, `original`, `translation`, `author` and
  the game `version` when the entry was added.
- `untranslated` – temporary holding area for collected strings.  Rows contain the original
  text, namespace and timestamp.  Translating a phrase removes it from this table.

## Best Practices

- **Use placeholders** – replace character names or dynamic values with `%s` or `%d` and supply
  them at runtime.  This keeps translations reusable.
- **Keep namespaces small** – group related phrases together but avoid mixing unrelated pages.
- **Coordinate changes** – use version control or the translator lounge to avoid overwriting
  each other’s work.
- **Review old entries** – periodically prune unused namespaces and clear stale entries in the
  `untranslated` table.
- **Test thoroughly** – after translating, browse the game in that language to ensure context
  still makes sense and text fits UI limits.

