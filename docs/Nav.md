# Navigation Helpers

This page documents helper methods in `\Lotgd\Nav`.

## addColoredHeadline

```php
addColoredHeadline(string $text, bool $collapse = true): void
```

Adds a navigation headline that supports LOTGD colour codes. Any open colour span is automatically closed by appending `` `0`` before the headline is rendered.

Use it when you want section titles with coloured text:

```php
\Lotgd\Nav::addColoredHeadline('`!Important Section');
```

The example renders the header using the `!` colour and ends with the default colour.
