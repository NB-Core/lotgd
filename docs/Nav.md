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

## addHeader

```php
addHeader(string|array $text, bool $collapse = true, bool $translate = true): void
```

Starts a new navigation section. When `$collapse` is `true` the links added after the call
can be collapsed in the UI. Set it to `false` to keep the section always expanded.

## add

```php
add(string|array $text, string|false $link = false, bool $priv = false, bool $pop = false, string $popsize = '500x300'): void
```

Adds a navigation link. When `$link` is `false` the call behaves like `addHeader()`.
If `$link` is an empty string an inactive/help line is inserted.
The optional `$priv` flag is forwarded to `appoencode()` to control HTML escaping.
When `$pop` is `true` the target URL opens in a popup sized by `$popsize`.

Example:

```php
\Lotgd\Nav::addHeader('Forest');
\Lotgd\Nav::add('Explore', 'forest.php');
\Lotgd\Nav::add('You are too tired', '');
\Lotgd\Nav::add('Help', 'help.php', false, true, '400x200');
```

## addNotl

Behaves like `add()` but never translates the text. Useful when links already
contain colour codes or are generated dynamically.

## blockNav / unblockNav

```php
blockNav(string $link, bool $partial = false): void
unblockNav(string $link, bool $partial = false): void
```

Blocks or unblocks a navigation link. If `$partial` is `true`, every link that
starts with the given prefix is affected.


