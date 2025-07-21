# Navigation Helpers

This page documents helper methods in `\Lotgd\Nav`.

## NavigationItem

```php
new \Lotgd\Nav\NavigationItem(string|array $text, string $link, bool $priv = false, bool $popup = false, string $popupSize = '500x300', bool $translate = true)
```

Represents a single navigation link. Use `render()` to produce the HTML snippet for the item.

## NavigationSection

```php
new \Lotgd\Nav\NavigationSection(string|array $headline, bool $collapse = true, bool $colored = false)
```

Holds a headline and a set of `NavigationItem` objects. The `$collapse` flag controls whether the section can be collapsed and `$colored` indicates a coloured headline.

## NavigationSubSection

```php
new \Lotgd\Nav\NavigationSubSection(string|array $headline, bool $translate = true)
```

Represents a sub headline within a section. It contains its own list of `NavigationItem` objects.

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

## addSubHeader

```php
addSubHeader(string|array $text, bool $translate = true): void
```

Begins a sub section under the current headline. Links added afterwards are grouped under this subheader until another subheader or header is set. Pass an empty string to stop adding items to a subheader.

## addColoredSubHeader

```php
addColoredSubHeader(string $text, bool $translate = true): void
```

Works like `addSubHeader()` but the text may contain LOTGD colour codes. The
subheader automatically appends `` `0`` so colours do not bleed into
following items.

Example:

```php
\Lotgd\Nav::addHeader('Main');
\Lotgd\Nav::addColoredSubHeader('`!Special');
\Lotgd\Nav::add('Link', 'foo.php');
```

## add

```php
add(string|array $text, string|false $link = false, bool $priv = false, bool $pop = false, string $popsize = '500x300'): void
```

Adds a navigation link. When `$link` is `false` the call behaves like `addHeader()`.
If `$link` is an empty string an inactive/help line is inserted.
The optional `$priv` flag is forwarded to `appoencode()` to control HTML escaping.
When `$pop` is `true` the target URL opens in a popup sized by `$popsize`.
When a subheader is active the link is stored inside that subsection.

Example:

```php
\Lotgd\Nav::addHeader('Forest');
\Lotgd\Nav::add('Explore', 'forest.php');
\Lotgd\Nav::add('You are too tired', '');
\Lotgd\Nav::add('Help', 'help.php', false, true, '400x200');
```

## addNotl

Behaves like `add()` but never translates the text. Useful when links already
contain colour codes or are generated dynamically. Links are also placed in the current subheader when one is active.

## blockNav / unblockNav

```php
blockNav(string $link, bool $partial = false): void
unblockNav(string $link, bool $partial = false): void
```

Blocks or unblocks a navigation link. If `$partial` is `true`, every link that
starts with the given prefix is affected.

## navsort

```php
navsort(string $sectionOrder = 'asc', string $subOrder = 'asc', string $itemOrder = 'asc'): void
```

Sorts navigation links alphabetically. `$sectionOrder` controls the order of
headlines, `$subOrder` the order of sub-headlines and `$itemOrder` the order of
items within sections and subsections. Each argument may be `'asc'`, `'desc'` or
`'off'` to keep the original order.

User preferences `sortedmenus`, `navsort_headers` and `navsort_subheaders` store
the chosen values. `buildNavs()` reads these preferences and automatically sorts
the navigation.



## Building the Final Menu

After links have been added with the helper methods above, call `Lotgd\Nav::buildNavs()` to produce the HTML navigation list.  The method automatically applies user preferences such as sorting order and collapsible sections.

```php
Lotgd\Nav::addHeader('Main');
Lotgd\Nav::add('Return', 'village.php');
$html = Lotgd\Nav::buildNavs();
```

`buildNavs()` clears the internal cache so links for the next page must be added again.

Developers can reset access keys or start fresh output with `Lotgd\Nav::resetAccessKeys()` and `Lotgd\Nav::clearOutput()` respectively.
