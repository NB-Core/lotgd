# Translation System Guide

This guide explains how to work with the Legend of the Green Dragon (LotGD) translation engine.
It provides detailed instructions for translators, administrators, and developers.
Newcomers should start with the quickstart and then explore the specialised chapters.

## Introduction

LotGD stores nearly all player-facing text in the database so it can be translated at runtime.
Every translatable phrase belongs to a *namespace* (also called a schema).
When outputting text the engine searches the `translations` table for the phrase in the
current language and namespace.
If no match exists the original English text is shown and, when collection is enabled,
the text is written to the `untranslated` table for later review.

### Core Components

- **Namespaces** – isolate phrases by page or module. Use `tlschema('page-name')` to switch.
- **translate()** – looks up a phrase in the current namespace.
- **translatorlounge.php** – dashboard for translators and a way to grant translator privileges.
- **translatortool.php** – edit existing translations in a namespace.
- **untranslated.php** – list of phrases still lacking a translation.

## Quickstart for New Translators

1. **Enable collection**
   - Log in as a superuser and set "Collect untranslated phrases" to *Yes* in game settings.
   - Browse the site; missing strings are stored in the `untranslated` table.
2. **Gain translator access**
   - Visit `translatorlounge.php` and grant your account the *Translator* flag if necessary.
3. **Translate strings**
   - Open `translatortool.php` and choose a namespace. The tool shows the English text and an
     input box to save a translation.
4. **Review unknown entries**
   - `untranslated.php` displays phrases that were collected but remain untranslated. Remove
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
  collected automatically. The engine can disable collection during heavy load to reduce DB writes.
  `translatorCheckCollectTexts()` evaluates these settings periodically.
- Enable caching if the game serves large numbers of requests. Translations are cached per
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

### Optional Bulk Tools
- Administrators can install the `translationwizard` module for mass updates.
  It adds search+edit and search+replace utilities and surfaces untranslated
  entries without needing a raw SQL dump. See
  [Translation Wizard Module](#translation-wizard-module) for details.

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
- Enable text collection and exercise all code paths. Check `untranslated.php` to verify that
  all phrases appear with the expected namespace.

### Module Internationalisation Checklist
- Choose a unique namespace prefix like `module-myfeature`.
- Wrap user-facing text with `translate()` or `output()` after calling `tlschema()`.
- Use `@` colour codes inside strings; translators can adjust colours if needed.
- Provide default English text that is concise and context aware.
- If your module adds superuser pages, ensure they also use namespaces so translators can
  localise the admin interface.

## Translation Wizard Module

The optional `translationwizard` module is an admin-only helper for mass
translation. It is ideal when translators want an in-game interface instead of
working with SQL dumps.

### Features
- **Search + edit** existing translations across namespaces.
- **Search + replace** to update terminology in bulk.
- Review unresolved entries from the `untranslated` table and other
  housekeeping tools.

### Workflow
1. **Install** – copy `translationwizard.php` into the `modules/` directory.
2. **Activate** – enable it from the superuser module editor.
3. **Use the wizard** – choose a language and perform search/replace,
   edit strings, or step through untranslated phrases.
4. **Cleanup** – deactivate or remove the module after completing the batch.

## Database Reference

Two tables drive the translation system:

- `translations` – stores `language`, `namespace`, `original`, `translation`, `author` and
  the game `version` when the entry was added.
- `untranslated` – temporary holding area for collected strings. Rows contain the original
  text, namespace and timestamp. Translating a phrase removes it from this table.

### Exporting and Importing
- Export a single language to SQL for offline work:
  ```bash
  mysqldump --skip-add-drop-table --compact lotgd translations \
    --where="language='de_DE'" > de_DE.sql
  ```
- To import back into a different server use `mysql` or the database GUI:
  ```bash
  mysql lotgd < de_DE.sql
  ```
- When sharing translations with others consider committing the SQL file to version control.

## Best Practices

- **Use placeholders** – replace character names or dynamic values with `%s` or `%d` and supply
  them at runtime. This keeps translations reusable.
- **Keep namespaces small** – group related phrases together but avoid mixing unrelated pages.
- **Coordinate changes** – use version control or the translator lounge to avoid overwriting
  each other's work.
- **Review old entries** – periodically prune unused namespaces and clear stale entries in the
  `untranslated` table.
- **Test thoroughly** – after translating, browse the game in that language to ensure context
  still makes sense and text fits UI limits.
- **Consistent terminology** – maintain a glossary so that common game terms are translated the
  same way across modules.
- **Prefer UTF‑8** – store and edit files in UTF‑8 to avoid garbled characters.
- **Avoid hard coded HTML** – let translators adjust formatting by using colour codes or
  placeholder tokens instead of tags.

## Glossary

- **Namespace** – grouping of related phrases. Similar to a "module" name in other systems.
- **Original text** – the English source string shown when no translation is found.
- **Translation** – the localised version saved in the database.
- **Collector** – process that writes missing phrases to the `untranslated` table.
- **Author** – the account name stored with each translated string.

## Frequently Asked Questions

### Why do my translations not show up?
Check that:
- The language selected in your account settings matches the translated language.
- The phrase is stored in the `translations` table with the correct namespace.
- Output caching is cleared; use the "Clear Cache" option in the superuser grotto.

### How do I handle plural forms?
The core engine does not provide automatic plural handling. Write both forms and choose with
basic PHP logic:
```php
$gold = 3;
$phrase = ($gold == 1)
    ? translate('You gain %s gold.','forest')
    : translate('You gain %s gold pieces.','forest');
output($phrase, $gold);
```

### Can I translate modules installed via Composer?
Yes. Third-party modules use their own namespaces just like core modules. After installing,
visit `untranslated.php` to collect and translate their strings.

### How do I revert a translation?
Open `translatortool.php`, load the namespace, locate the phrase, and submit the English text
again. You can also delete the row from the `translations` table to fall back to English.

## Troubleshooting

- **Collecttexts keeps turning off** – the engine disables collection when many players are
  online. Adjust the threshold in game settings or lower server load.
- **Encoding issues** – ensure the MySQL connection uses UTF‑8 (`utf8mb4`). Set the collation
  in `config/configuration.php` and verify that your terminal/editor uses the same encoding.
- **Cached old text** – clear `data/cache` or use the superuser cache clearing tool after
  editing translations directly in the database.
- **Missing translator flag** – only accounts with the Translator flag see the tools. Grant it
  via the superuser editor.

## Advanced Workflows

### Batch Editing in Spreadsheets
1. Export a language with `mysqldump` as shown above.
2. Import the SQL into a spreadsheet program that supports UTF‑8.
3. Edit translations, then export back to SQL and import into the database.

### Programmatic Access
Developers can manipulate translations via Doctrine entities under `Lotgd\Entity\Translation`.
This is useful for building custom importers or syncing with external services.

### Deploying Translation Updates
Include SQL dumps of the `translations` table in your deployment pipeline. Run them after
code updates so new phrases are available immediately.

## Appendix: Sample Module Skeleton

```php
<?php
function mymod_getmoduleinfo()
{
    return [
        'name' => 'My Mod',
        'version' => '1.0',
        'author' => 'You',
        'category' => 'Example',
    ];
}

function mymod_run()
{
    tlschema('module-mymod');
    output(translate('Hello from my module!'));
    tlschema();
}
?>
```
This skeleton demonstrates the minimum structure required for a translatable module.

## Further Reading

- Original discussion forum: <https://dragonprime.net>
- Community translation packs and tools: <https://github.com/NB-Core/>
- For troubleshooting help join the Discord server listed in the main README.

