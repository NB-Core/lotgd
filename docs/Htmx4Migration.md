# htmx 2 → htmx 4: migration plan

Status: **plan, nothing changed yet.** Branch `claude/htmx4-migration`, from master `14270fe5`. The release
2.0.9 ships with htmx 2.0.11; this migration is meant for the release after it.

Sources:

- the npm package `htmx.org@4.0.1` (published 2026-11-01; 4.0.0 on 2026-08-28);
- its shipped upgrade guide `dist/skills/htmx-upgrade-from-htmx2.md` and `dist/skills/htmx-guidance.md`;
- its source, `dist/htmx.js`, for the details below.

four.htmx.org could not be reached from the build environment. The package's guide is the same text as the
site's migration guide.

---

## 1. Where we use htmx today

htmx is loaded only for a player whose partial client is active (`PartialMode::clientActive()`), by
`Footer` (`Output::requireVendorAsset('htmx', 'js')`, then `lotgd-partial`). Nothing else in the core, the
shipped skins, NB-Core/modules or the Shinobi modules uses htmx.

| Where | What |
|---|---|
| `assets/js/lotgd.partial.js` | everything: config, boosting the regions, all event handlers, `htmx.process()` |
| `assets/js/lotgd.notices.js` | `htmx:afterSettle`, `htmx:afterRequest` (reads `event.detail.xhr`) |
| `assets/js/lotgd.showform.js` | `htmx:afterSettle`, `htmx:beforeCleanupElement` |
| `src/Lotgd/Page/PartialRenderer.php` | answer: `<title>`, the content region, and one `<div hx-swap-oob="innerHTML:[data-lotgd-region=…]">` per other region |
| `src/Lotgd/Page/PartialMode.php`, `Footer.php`, `Redirect.php` | read `HX-Request`, `HX-History-Restore-Request`; send `HX-Redirect`, `Vary` |
| `tools/e2e/*.js` | `window.htmx`, `htmx.process()`, `htmx.ajax()`, the `hx-request` header |
| templates (Twig and `.htm`) | **no** `hx-*` attributes and **no** `.htmx-*` CSS; only the `data-lotgd-region` markup |

What `lotgd.partial.js` relies on, by feature:

- **Config:**
  - `allowScriptTags = false`, `allowEval = false`, `selfRequestsOnly = true`;
  - `historyCacheSize = 0`, `refreshOnHistoryMiss = false`;
  - `defaultSwapStyle = 'innerHTML'`, `scrollIntoViewOnBoost = false`.
- **Boosting:** `hx-boost` + `hx-target` + `hx-swap` set on the region containers, inherited by every link
  and form inside. `hx-boost="false"` on ordinary links (lightbox, `target`, non-`.php`) and on forms that
  are not on the page's form list.
- **Events:**
  - `htmx:configRequest`: adds `X-Lotgd-Client`, `X-Lotgd-Head` and `X-Lotgd-Page-Revision`;
  - `htmx:beforeRequest`: one request at a time, loading class;
  - `htmx:afterRequest`: removes the loading class;
  - `htmx:beforeSwap`: resubmit, 409, "not a partial answer → recover", revision check, form list;
  - `htmx:afterSettle`: async state, region versions, region init, autofocus, title, offcanvas;
  - `htmx:sendError` and `htmx:timeout`: recover;
  - `htmx:historyCacheMiss`: back/forward → `badnav.php`;
  - `htmx:beforeProcessNode`: unboost ordinary links.

---

## 2. Core issues (what htmx 4 changes for us)

Each row: what changes, what it means here, and what we do.

### 2.1 Configuration

| htmx 2 (ours) | htmx 4 | Impact here | Action |
|---|---|---|---|
| `allowScriptTags = false` | **removed** – scripts in swapped content always run (`#processScripts` on every swap) | We lose a client-side safety net. The server already refuses any region with `<script` (`PartialRenderer::refusal()` → late fallback), so a partial answer never carries one today. | Keep the server refusal as the guarantee; add a test that no partial answer contains `<script`; document it. |
| `allowEval = false` | **removed** – `hx-on:*`, `js:` values and trigger filters are always compiled with `Function` | Only matters if hx-attributes from player text reach the page. Player text is escaped (`Escape::gameText()`, `commentSanitize()`), and `tools/e2e/xss.js` checks it. | Keep the xss suite as gate; optional later: a CSP header with `hx-csp`. |
| `selfRequestsOnly = true` | `mode: 'same-origin'` (the default) | Same protection, via fetch. | Set `mode` explicitly. |
| `historyCacheSize = 0`, `refreshOnHistoryMiss = false` | removed; history is no longer kept in localStorage. Back/forward fires `htmx:before:history:restore` and then **fetches the URL again** with `HX-History-Restore-Request: true` | Running the URL again is exactly what forced navigation forbids (a fight round, a purchase). | Cancel `htmx:before:history:restore` and call `recover()` (→ `badnav.php`), as today. Fallback: `history: false` plus our own `popstate` handler. |
| `defaultSwapStyle` | `defaultSwap` | rename | rename |
| `scrollIntoViewOnBoost = false` | removed; scrolling is per swap (`show:`/`scroll:`), `defaultFocusScroll: false` | Must be checked in the browser: today the page does not jump on a swap. | Test; add `show:none` to our `hx-swap` if it scrolls. |
| `timeout` (0 = none) | `defaultTimeout`, **default 60000 ms** | A page slower than 60 s is aborted on the client after the server has run it. | Set `defaultTimeout: 0` (as today) – or keep 60 s; `htmx:error` → `recover()` shows the stored page either way. **Decision.** |
| (none) | `includeIndicatorCSS: true` injects a `<style>` | Harmless, but changes the page for skins that use `.htmx-indicator`. | Set `false`. |
| (none) | `transitions: false` | same as today | – |

### 2.2 Responses

- **4xx/5xx are swapped by default** (htmx 2 did not): our 409 `{"reload": …}`, 400, 403 and 500 answers
  would be put into the content region. Our `before:swap` handler cancels every answer that is not
  `200` + `X-Lotgd-Partial: 1`, so this is covered. We still set `noSwap: [204, 304, "4xx", "5xx"]` so the
  rule does not depend on one handler.
- **OOB:** `hx-swap-oob="innerHTML:[data-lotgd-region='nav']"` is still parsed (`style:selector`). The
  order changes: the main swap runs first, then the OOB swaps (htmx 2: OOB first). Nothing of ours depends
  on the order.
  - A response with only OOB elements no longer swaps an empty main content (`allowEmptySwapAfterOOB:
    false`). A page with an empty content region would keep the old content. Set
    `allowEmptySwapAfterOOB: true`, or `swapEmpty:true` in our `hx-swap`.
  - Optional: htmx 4's `<hx-partial hx-target=… hx-swap=…>` is the clearer form for our regions.
    `PartialRenderer` could emit it instead of OOB `<div>`s. Not needed; **decision**.
- **`<title>`** in the answer still sets `document.title`.
- **`HX-Redirect`** is unchanged (`location.href = …`). `HX-Request` is still sent.
  `HX-History-Restore-Request` is only sent on a history restore, which we cancel.
- **New request headers:** `HX-Request-Type` (`partial`/`full`), `HX-Current-URL`, `HX-Source`. We do not
  need them; `PartialMode::VARY` stays as it is.

### 2.3 Events (all handlers move; no config escape hatch)

| Ours today | htmx 4 | Detail changes |
|---|---|---|
| `htmx:configRequest` | `htmx:config:request` | headers at `event.detail.ctx.request.headers` |
| `htmx:beforeRequest` | `htmx:before:request` | still cancelable (`preventDefault`) |
| `htmx:afterRequest` | `htmx:after:request` | **fires before the swap** now (htmx 2: after). Move the "loading off" to the end of the swap, keep the in-flight flag until then. `lotgd.notices.js` reads the status and the `X-Lotgd-Partial` header from `ctx.response`. |
| `htmx:beforeSwap` | `htmx:before:swap` | no `detail.xhr`: status `ctx.response.status`, headers `ctx.response.headers.get()`, body `ctx.text`, form `ctx.sourceElement`. Cancel with `preventDefault()` instead of `shouldSwap = false`. |
| `htmx:afterSettle` | `htmx:after:settle` | **fires once per target**: content plus every OOB region. Our handler (region init, autofocus, title, offcanvas, async state) must run once per answer → run it after the last task, or guard it with the request context. **Verify in the browser.** |
| `htmx:sendError`, `htmx:timeout` | `htmx:error` | one event; timeouts arrive here too |
| `htmx:historyCacheMiss` | `htmx:before:history:restore` | see history above |
| `htmx:beforeProcessNode` | `htmx:before:init` | boosting a link fires it and can be cancelled: cancel it for ordinary links instead of writing `hx-boost="false"` |
| `htmx:beforeCleanupElement` | `htmx:before:cleanup` | – |
| (n/a) | `htmx:response:error` | for ≥ 400; we handle errors in `before:swap` already |

### 2.4 Attributes

- **Inheritance is explicit.** Our region containers carry `hx-boost`, `hx-target` and `hx-swap` for their
  links and forms. They become `hx-boost:inherited`, `hx-target:inherited` and `hx-swap:inherited` (one
  line in `boostRegions()`). The alternative, `implicitInheritance: true`, is only a migration bridge.
- `hx-boost="false"` on single links and forms is set on the element itself: unchanged.
- A boosted element without an inherited target would target `document.body`. This is a regression check
  for the step above.
- We use neither `hx-disable` nor `hx-disabled-elt`, so the rename does not apply.

### 2.5 JavaScript API and requests

- `htmx.process()` and `htmx.ajax()` still exist; `tools/e2e/staletab.js` uses `htmx.ajax`, so check its
  options.
- Requests use `fetch` with `FormData`. A boosted POST must still carry the submit button's `name=value`:
  the bank and the forms with several buttons rely on it (`tools/e2e/forms.js` checks it).
- The realtime region swapper (`setRegionSwapper`) sets `innerHTML` and calls `htmx.process()`.
  `process()` does not run scripts, so it is unchanged.
- The mail/MoTD overlay (`lotgd.overlay.js`) uses its own `fetch`, not htmx: unchanged.

### 2.6 Server (PHP)

Nothing is required. `PartialMode`, `PartialRenderer`, `Footer` and `Redirect` keep their contract
(`HX-Request` + `X-Lotgd-Client`, `HX-Redirect`, OOB markup). Optional: `<hx-partial>` (see 2.2).

### 2.7 Browsers

htmx 4 is ES2022 (private class fields and methods): Chrome/Edge 84+, Firefox 90+, Safari 15+. On an older
browser the script fails to load, `window.htmx` stays undefined, `lotgd.partial.js` stops at its first line,
and the player gets full pages, as with partial rendering off. Nobody is locked out.

---

## 3. Template and skin issues

### Shipped skins (6 Twig, 4 legacy `.htm`)

They contain no `hx-*` attributes, no `hx-on`, and no `.htmx-*` CSS. The region contract
(`data-lotgd-region`, `data-lotgd-slot`, `data-lotgd-close-on-swap`, `data-lotgd-title`) does not change,
so **no template change is expected**. `RegionContractTest` stays as it is. Check in the browser on all 10
skins anyway (`tools/e2e/run.sh --skins all town`).

### Third-party skins (operators' own templates)

1. **A skin that brings its own htmx and declares no regions** (the admin's htmx 4 case): our client is not
   loaded; nothing changes for it.
2. **A skin that declares `regions` and loads its own htmx:** after the migration the core loads htmx 4.
   - A second copy (2 or 4) breaks: two globals, double processing.
   - Rule for UPGRADING: a skin that declares `regions` does not load htmx itself; the core does.
   - Optional: the core skips loading if `window.htmx` is already a 4.x (`htmx.version`). **Decision.**
3. **`hx-*` attributes in a custom skin** (its own boosts or `hx-on`):
   - inheritance needs `:inherited`;
   - `hx-on:htmx:afterSwap` becomes `hx-on:htmx:after:swap`;
   - `hx-disable` becomes `hx-ignore`, and `hx-disabled-elt` becomes `hx-disable`.
   - Name these in UPGRADING with a short table, and point to `npx htmx.org@4.0.1 upgrade-check`.
4. **CSS on htmx classes:** `htmx-request`, `htmx-added` and `htmx-settling` still exist. `htmx-swapping`
   and the `swappingClass` config are gone. With `includeIndicatorCSS: false`, the look stays as it is.
5. **Skins with inline `<script>` in a region** fall back to full pages as before; the server-side rule does
   not change.

### Modules

None of the modules in NB-Core/modules or shinobilegends.com uses htmx. Module pages swap through the core
client (`partial` key), so there is nothing to change.

---

## 4. Advantages and disadvantages

### Advantages

- **The maintained line.** htmx 4 is where new work happens; htmx 2 keeps getting fixes for now, but a
  rewrite later would be bigger.
- **Smaller:** 37 KB min / 13.4 KB gzip, against 52 KB / 16.9 KB.
- **fetch instead of XHR:**
  - `AbortController` for timeouts and navigation;
  - one request object (`ctx`);
  - `mode: 'same-origin'` is native.
- **Explicit inheritance:** fewer surprises for skins that put `hx-*` on containers.
- **Clearer errors:** one `htmx:error`, one `htmx:response:error`.
- **One htmx for everyone:** the admin who builds skins on htmx 4 gets the same major version as the core.
- **History through the server, not localStorage:** no stale page snapshots in the browser. We cancel the
  restore anyway, but the old localStorage path is gone.
- **Optional:** `<hx-partial>` for multi-region answers; extensions for SSE, preload and CSP, if wanted
  later.

### Disadvantages

- **A rewrite of our client code.** Every event name, every `event.detail` access and the swap cancelling
  change; there is no config to keep the old events. `htmx-2-compat` exists, but it is a bridge, not
  something to ship.
- **Two safety switches gone:**
  - `allowScriptTags`: scripts in swapped content always run;
  - `allowEval`: `hx-on`/`js:` always evaluate.
  - We depend fully on the server: script refusal in regions and escaping of player text. Both are in
    place and tested, but the second line of defence is gone.
- **Behaviour changes to handle:**
  - 4xx/5xx swap by default;
  - `after:request` fires before the swap;
  - `after:settle` fires once per target;
  - default timeout 60 s;
  - an OOB-only answer does not swap empty content.
- **Newer browsers needed** (ES2022). Graceful: those players get full pages.
- **Young release:** 4.0.0 is ten weeks old, 4.0.1 one week. There are fewer field reports than for 2.0.x.
- **Third-party skins** with their own `hx-*` attributes, or their own htmx next to `regions`, need changes
  by their authors.
- **Little functional gain for us:** we use a small, stable part of htmx (boost, OOB, headers, events), and
  htmx 2.0.11 does all of it.

### Recommendation

Migrate, but not in 2.0.9:

- **Timing:** in the next minor release, as one cut-over.
  - No setting to choose 2 or 4: two client adapters are more risk than the migration.
  - Rolling back is a revert of the vendor file and `lotgd.partial.js`.
- **Before the cut-over:** the full browser regression on all skins, plus the specific checks in 5.4.

---

## 5. Work plan (on this branch)

### 5.1 Vendor

- `assets/vendor/htmx/htmx.min.js` ← `htmx.org@4.0.1/dist/htmx.min.js`; record its sha1.
- `assets/vendor/manifest.json` version `4.0.1`.
- Update the docs that name 2.0.11.
- Do not vendor the compat extension.

### 5.2 Client

1. **`lotgd.partial.js` config:**
   - `mode: 'same-origin'`, `defaultSwap: 'innerHTML'`, `history` (see 5.2.4);
   - `defaultTimeout` (decision), `noSwap: [204, 304, '4xx', '5xx']`;
   - `includeIndicatorCSS: false`, `allowEmptySwapAfterOOB: true`.
   - Drop the removed keys.
2. **A small adapter** so the handlers read one shape: `status(event)`, `header(event, name)`,
   `body(event)`, `source(event)`.
3. **Port every handler** per the table in 2.3:
   - cancel with `preventDefault()`;
   - keep the in-flight flag until the swap is done;
   - run the settle work once per answer.
4. **History:** `htmx:before:history:restore` → `preventDefault()` + `recover()`.
5. **Boost:** `:inherited` on the region containers; ordinary links cancelled in `htmx:before:init`.
6. **`lotgd.notices.js` and `lotgd.showform.js`:** new event names and `ctx.response`.
7. **`tools/e2e`:** `staletab.js` (`htmx.ajax` options) and any check on htmx internals.

### 5.3 Server

- No change needed.
- Optional (decision): `<hx-partial>` in `PartialRenderer`, with a test for both forms during the change.
- Add a PHPUnit check that no partial answer contains `<script` (it is guaranteed by `refusal()`; the test
  makes the dependency visible).

### 5.4 Tests

- `composer test`, PHPStan, PHPCS; `node --check` on the changed JS.
- `npx htmx.org@4.0.1 upgrade-check` over the repo (needs to be run by a person, or allowed in the
  session).
- Full browser regression (`tools/e2e/run.sh`, Aurora and jade), then `town` on all 10 skins.
- Specific checks:
  - **Requests:** a double click sends one request; a stale second tab is turned away; back/forward ends
    on badnav and does **not** re-run the URL.
  - **Form submits:** a POST off the list is resubmitted with its button; a 409 reloads; a timeout or
    network error shows the stored page.
  - **What the page shows:**
    - title, autofocus and the offcanvas close after a swap;
    - the async state and region versions are applied once;
    - the page does not scroll on a swap.
  - **Fallbacks and security:** the late fallback still goes to badnav with the real address; the xss
    suite passes (injected `hx-on` does nothing).
  - **Skin with its own htmx:** the test skin with its own htmx 4 and no regions gets full pages.
  - **Old browser:** a browser without ES2022 gets full pages.

### 5.5 Docs

- `docs/ReactiveCore.md`: client section, events, history, safety notes.
- `UPGRADING.md`, for skin authors: htmx 4, `:inherited`, renamed events, no second htmx next to
  `regions`, upgrade-check.
- `CHANGELOG.md`.

---

## 6. Open decisions

1. **Target release:** the next minor release after 2.0.9 (recommended), or 2.0.10.
2. **`defaultTimeout`:** 0 as today (recommended), or htmx 4's 60 s.
3. **`<hx-partial>`** instead of OOB `<div>`s in `PartialRenderer`: optional, not needed.
4. **If a skin with regions already loaded htmx 4:** reuse its copy, or always load ours (recommended:
   always ours, and document the rule).
5. **A CSP header with `hx-csp`** to get back a guard against eval and inline scripts: a separate,
   larger step; not part of this migration.
