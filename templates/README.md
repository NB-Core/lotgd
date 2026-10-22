# Legacy templates (`.htm`): partial rendering, realtime and the overlay

The bundled legacy templates (`jade.htm`, `modern.htm`, `yarbrough.htm` and `puritanic_ai.htm`) take part in the
reactive features in the same way as the Twig skins in `templates_twig/`:
- pages change in place instead of loading again;
- mail, stats and grants update live;
- live duels, spectators and rematches work;
- mail and the message of the day open in a dialog inside the page.

A template of your own does too, once it carries the markers below. Until then it keeps working exactly as
before: whole pages, popup windows. Players on it see a notice on the duelling ground saying why live duels are
not available to them.

Also see `docs/ReactiveCore.md` (*Region contract*) and `templates_twig/README.md` for Twig skins.

## How a legacy page is put together

A template is cut into sections by `<!--!name-->` comments (`Template::loadTemplate()`). A page is the `header`
section, then the page's content, then the `footer` section. Placeholders such as `{nav}` or `{stats}` are
replaced with their values in both sections.

A partial answer replaces the *inner HTML* of marked elements. Every region therefore needs an element of its
own that holds its placeholder and nothing else.

## Step by step

### 1. Declare the features

Add a `config` section, anywhere in the file (the bundled templates put it at the end):

```
<!--!config-->
{"regions": true, "overlay": true}
```

- `regions`: pages change in place. Realtime and live duels need this.
- `overlay`: mail and the MoTD open in an in-page dialog instead of a window.

The game reads this section only for these two flags; it is never printed.

### 2. Content

Open an element at the very end of the `header` section, after any ads, and close it at the very start of the
`footer` section:

```html
	<td valign='top'>{verticalad}{bodyad}<div data-lotgd-region="content">
<!--!footer-->
	</div>
	...
```

Exactly one content element. Keep ads (`{bodyad}`, `{verticalad}`, `{headerad}`) and `{petitiondisplay}`
outside it.

### 3. Navigation and stats

Wrap `{nav}` and `{stats}` each in an element of their own. An existing cell or `div` works, as long as it holds
the placeholder alone:

```html
<td data-lotgd-region="nav">{nav}</td>
<div data-lotgd-region="stats">{stats}</div>
```

`{navad}` and `{paypal}` stay outside. Before, jade had `{stats} <p>&nbsp;</p> {paypal}` in one cell; now
`{stats}` gets its own `<div>`.

### 4. Header slots

Each of the five slots gets an element of its own, with both attributes:

```html
<span data-lotgd-region="header" data-lotgd-slot="motd">{motd}</span> |
<span data-lotgd-region="header" data-lotgd-slot="mail">{mail}</span> |
<span data-lotgd-region="header" data-lotgd-slot="petition">{petition}</span>
<span data-lotgd-region="header" data-lotgd-slot="grants">{grants}</span>
<div data-lotgd-region="header" data-lotgd-slot="petitiondisplay">{petitiondisplay}</div>
```

- Every slot is needed exactly once. Realtime updates `mail` and `grants` without a page change.
- `{grants}` is new for legacy templates: the one-time links other players offer (a clan applicant, a duel
  challenge). Without the slot they still appear in the navigation.
- Separators such as `|` or `<br>` stay outside the slot elements.

### 5. Optional: the title in the layout

Most templates also show the title inside the page (`<span class='pagetitle'>{title}</span>`). Wrap the
placeholder so the client refreshes it after a swap:

```html
<span class='pagetitle'>&#149; <span data-lotgd-title>{title}</span></span>
```

`<title>{title}</title>` in `<head>` is updated on its own.

### 6. Optional: dialog colours

The dialog falls back to a dark scheme. Set its colours in your stylesheet:

```css
:root {
    --lotgd-overlay-bg: #003800;
    --lotgd-overlay-fg: #cccccc;
    --lotgd-overlay-border: #015d01;
    --lotgd-overlay-scheme: dark;
}
```

## Keep these

- `{headscript_pre}{headscript_mid}` and `{headscript}{script}` in `<head>`: they load the partial client and its
  `<meta>` tags.
- No `<script>` inside a region. A page whose regions contain a script is always loaded whole.

## Check it

`tests/Templates/LegacyRegionContractTest.php` takes every `templates/*.htm`, fills each placeholder with a
marker and checks:
- the `config` section is there;
- exactly one content region holds only the content;
- every nav and stats region holds only its placeholder;
- every header slot appears once;
- no ad, `{paypal}` or `{copyright}` ends up inside a region;
- the stylesheet defines the dialog colours.

Copy your template into `templates/` and run `composer test`.

In the game, choose the template in your preferences and walk from the village to the forest: the page should
change without reloading. Then open your mail; it should appear in a dialog.
