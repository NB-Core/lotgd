# Twig skins: partial rendering, realtime and the overlay

Every bundled Twig skin (aurora, aurora_minimal, aurora_test, modern, puritanic, puritanic_ai) takes part in the
reactive features:
- pages change in place instead of loading again;
- mail, stats and grants update live;
- live duels, spectators and rematches work;
- mail and the message of the day open in a dialog inside the page.

A skin of your own does too, once it carries the markers below. Until then it gets whole pages and popup windows,
as before.

Also see `docs/ReactiveCore.md` (*Region contract*) and `templates/README.md` for legacy `.htm` templates.

## How it works

A skin's `page.twig` receives the page as variables: `content`, `nav`, `stats`, `title`, `motd`, `mail`,
`petition`, `petitiondisplay`, `grants` and so on. A partial answer replaces the *inner HTML* of marked elements
with the new values. Every region therefore needs an element of its own that holds its variable and nothing
else.

## Step by step

### 1. Declare the features

In the skin's `config.json`:

```json
{
    "regions": true,
    "overlay": true
}
```

- `regions`: pages change in place. Realtime and live duels need this.
- `overlay`: mail and the MoTD open in an in-page dialog instead of a window.

### 2. Content

Exactly one element, around `{{ content }}` alone:

```twig
<main class="col main-content" data-lotgd-region="content">{{ content|raw }}</main>
```

If something else shares that element (ads, `petitiondisplay`), add an inner `<div data-lotgd-region="content">`
around `{{ content|raw }}` only. puritanic and modern do this.

### 3. Navigation and stats

Any number of elements (for example the sidebar and the mobile menu), each around its variable alone:

```twig
<div class="nav-container" data-lotgd-region="nav">{{ nav|raw }}</div>
<div data-lotgd-region="stats">{{ stats|raw }}</div>
```

### 4. Header slots

One element per slot, with both attributes:

```twig
<span data-lotgd-region="header" data-lotgd-slot="motd">{{ motd|raw }}</span>
<span data-lotgd-region="header" data-lotgd-slot="mail">{{ mail|raw }}</span>
<span data-lotgd-region="header" data-lotgd-slot="petition">{{ petition|raw }}</span>
<span data-lotgd-region="header" data-lotgd-slot="grants">{{ grants|raw }}</span>
<div data-lotgd-region="header" data-lotgd-slot="petitiondisplay">{{ petitiondisplay|raw }}</div>
```

### 5. Optional markers

- `data-lotgd-title` on an element that shows the page title in the layout: its text follows the new `<title>`.
- `data-lotgd-close-on-swap` on a mobile menu (a Bootstrap offcanvas): an open one closes after a page change.

### 6. Dialog colours

In `assets/style.css`:

```css
:root {
    --lotgd-overlay-bg: #352d20;
    --lotgd-overlay-fg: #cccccc;
    --lotgd-overlay-border: #433828;
    --lotgd-overlay-scheme: dark;
}
```

## Keep these

- `{{ headscript_pre|raw }}`, `{{ headscript_mid|raw }}`, `{{ headscript|raw }}` and `{{ script|raw }}` in
  `<head>`: they load the partial client and its `<meta>` tags.
- No inline `<script>` inside a region. A page whose regions contain a script is always loaded whole.

## Check it

`tests/Templates/RegionContractTest.php` renders every `templates_twig/*/page.twig` with a marker in each
variable and checks:
- the flags in `config.json`;
- exactly one content region;
- every nav and stats region holds only its variable;
- every header slot appears once;
- no ad, `paypal` or `copyright` ends up inside a region;
- the dialog colours are set.

Add your skin to `templates_twig/` and run `composer test`.
