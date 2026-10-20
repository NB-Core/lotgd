# Reactive Core – Roadmap

Goal: a partially rendered, realtime-capable game (live displays and
notifications first; simultaneous combat needs its own game rules and is out
of scope) **without** breaking the module API: page scripts keep calling
`Header::pageHeader()`, `output()`, `Nav::add()` and `page_footer()`, and the
navigation allow-list (`$session['allowednavs']`) stays authoritative.

Work happens on the long-lived integration branch `feature/reactive-core`.
Each phase is one pull request against that branch, never against `master`;
`master` is merged in regularly.

The current state of the code paths involved is recorded in
[ReactiveCore-Inventory.md](ReactiveCore-Inventory.md).

## Three counters, never interchangeable

| Counter | Belongs to | Purpose | Bumped by |
|---|---|---|---|
| `accounts.version` | account | detect concurrent changes | every gameplay change to the account (not `laston`) |
| `pageRevision` | account | protect the displayed navigation | every page-nav response that resets `allowednavs` |
| event cursor (`seq`) | client subscription | acknowledge received events | the relay (delivery sequence) |

Chat refresh, stats poll and event poll bump neither of the first two.

## Phases

| Phase | Content | Status |
|---|---|---|
| 0 | Inventory (`composer qa:reactive-inventory`, [inventory](ReactiveCore-Inventory.md)) | done |
| 1 | `PageState`: the footer assembles one typed state object; renderers only turn it into HTML. Output unchanged. | done |
| 2 | Partial rendering for display-only operations (HTMX), page revisions, HTTP contract | done (the forest; see below) |
| 3 | Guarded game actions: `Action::run()`, split `saveUser()`, optimistic locking | planned |
| 4 | Transactional outbox, relay, polling transport | planned |
| 5 | Grants: server-issued one-shot navigation for other players | planned |
| 6 | SSE / Mercure transport | planned |

## Phase 1 – PageState

`Lotgd\Page\PageState` is the typed form of what `PageParts::$twigVars`
carried: title, content, built navigation, stats, header slots, quick keys,
the template `header`/`footer` strings and the remaining template variables.
`Footer::pageFooter()` and `Footer::popupFooter()` build it exactly once, with
all side effects (nav registration in `allowednavs`, footer and stats hooks,
the MOTD check) happening during that build. `Lotgd\Page\PageRenderer`
renders a state to HTML and runs no hooks. Phase 2 adds a second renderer for
fragments on top of the same state.

### Region contract for Twig skins (used from Phase 2)

A skin that wants partial rendering marks its regions with
`data-lotgd-region="<name>"` on the element that wraps the corresponding
variable, and declares `"regions": true` in its `config.json`:

| Region | Twig variable(s) | Markup |
|---|---|---|
| `content` | `content` | exactly one element; the swap target |
| `nav` | `nav` | any number of elements (Aurora has the sidebar and the mobile menu) |
| `stats` | `stats` | any number of elements |
| `header` | `motd`, `mail`, `petition`, `petitiondisplay` | one element per slot, with `data-lotgd-slot="<variable>"` |

A skin without the declaration, and every legacy `.htm` template, always gets
the full page. Aurora is the first skin that declares regions.

## Phase 2 – as built

Switched on by the game setting `partial_render` (off by default) **and** the
player's preference `partialrender`. Everything below only applies to such a
player on a skin with regions; everyone else gets exactly the game of before.

- **Allow-list** (`Lotgd\Page\PartialMode::ALLOWLIST`, script → method →
  `op`): only `forest.php` without `op`, by GET. The village and the inn are
  left off because their commentary block emits an inline preview script;
  they would always take the late fallback. They join once the commentary
  registers a region lifecycle.
- **Early fallback**: `PartialMode::preCheck()` runs in `common.php` before
  forced navigation. A partial request for any other page gets
  `HX-Redirect` to the same URL and an empty body; the page has not run.
- **Page revision**: `accounts.page_revision` (migration
  `Version20261020000001`). `ForcedNavigation` claims it with
  `UPDATE … SET page_revision = page_revision + 1 WHERE acctid = :id AND page_revision = :loaded`
  right after accepting the navigation. A request that loses the claim was
  started from a page another request already left (second tab, second
  device): it is answered `409 {"reload":"badnav.php"}` (partial) or
  redirected to `badnav.php` (full page) and does not save the account.
  Same-browser double clicks never reach the server twice: the client sends
  one request at a time, and PHP's session lock serialises the rest.
- **Answer**: `Lotgd\Page\PartialRenderer` – the content region as body,
  every other region as an `hx-swap-oob` element addressed by
  `[data-lotgd-region=…]`, plus `<title>`. Headers `X-Lotgd-Partial: 1`,
  `X-Lotgd-Page-Revision`, `Vary: HX-Request`.
- **Late fallback**: a page whose content contains `<script`, or a player who
  turned the preference off, is stored as usual and answered
  `HX-Redirect: badnav.php`, which shows the stored page.
- **Redirects** (`Redirect::redirect()`, e.g. the new day or a login timeout)
  inside a partial request are `HX-Redirect` page changes.
- **Client** (`assets/js/lotgd.partial.js`, after htmx 2.0.4 from
  `assets/vendor/htmx/`): boosts links in the `content` and `nav` regions;
  forms, popup links (`onclick`), `target` links and other hosts stay
  ordinary; never runs swapped-in scripts; a response without
  `X-Lotgd-Partial` or with an older revision is not swapped; 409 and errors
  go to `badnav.php`; Back/Forward go to `badnav.php`; quick keys are bound
  from the access keys in the navigation region (replacing the inline
  key handler for these players); `lotgd.regions.register(name, {init, destroy})`
  for code that has to run per swap.

Verified in a browser (Chromium via Playwright) against MariaDB 11.4:
village → forest swapped in with both navigation copies updated and one
revision claimed; forest → village as a page change without the village
running twice; quick key; Back to `badnav.php`; a double click sends one
request; a stale second tab ends on `badnav.php` without running its link;
without JavaScript the game is unchanged. Two connections racing the claim
from the same revision: one updates a row, the other none.

## Binding acceptance criteria

Each later phase is merged only when its criteria hold.

### Phase 2 – partial rendering

1. **Revision and allowednavs are stored atomically.** Every page nav, even a
   display-only one, checks and sets `pageRevision` and `allowednavs` with one
   conditional update
   (`… SET allowednavs = :new, page_revision = page_revision + 1 WHERE acctid = :id AND page_revision = :expected`).
   Zero rows → `409`. Two sessions cannot both consume the same base revision.
2. **The fallback is decided before execution.** A pre-check in `common.php`
   decides partial vs. full page from (script, `op`, method, skin regions)
   *before* the page script, new day or module events run. An early fallback
   may redirect to the same URL. A late one (an event emitted inline script)
   redirects to the read-only recovery path (`badnav.php`, showing
   `$session['output']`) and never re-executes the URL.
3. Allow-list by (script, `op`, method), starting with display-only operations.
4. HTTP contract: partial `200` + `X-Lotgd-Partial: 1`; unsupported page →
   `HX-Redirect`; stale revision → `409` + JSON, the client reloads by GET and
   never repeats a POST; expired login → `401` + `HX-Redirect`; a full HTML
   body without the partial header is never swapped into a region.
5. Navigation region is swapped as a whole first; a fine-grained diff later
   needs explicit, language-independent ids on sections and items.
6. Inline scripts are not executed after a swap; regions register
   `init`/`destroy`. Jaxon and HTMX own disjoint regions.

### Phase 3 – guarded actions

`Action::run(name, actionId, fn)`:

1. Refuse when a transaction is already open (no nesting in v1); `LOCK TABLES`
   while an action runs throws.
2. `beginTransaction`.
3. Look up `action_log (acctid, action_id)` **inside** the transaction
   (`FOR UPDATE`): same `action_name` and `params_hash` → return the stored
   result; different hash → reject. No separate "running" reservation.
4. Lock the account row (`SELECT version … FOR UPDATE`) – serialises actions
   per account across sessions and devices.
5. Snapshot session user, buffs, companions and both bases.
6. Run the action.
7. Persist account changes, outbox events, the `action_log` row with the
   result, and grant consumption – all in this transaction.
8. Commit; then advance the committed base and version; then `afterCommit()`
   callbacks (best effort only – required follow-up work uses the outbox).

On rollback: restore the snapshot, `EntityManager::clear()` **and**
`Accounts::setAccountEntity(null)`, invalidate touched cache keys. No
`action_log` row is left, so the same action id may run again.

Further rules:

- Order of checks for a request carrying an action id: authentication → CSRF
  → `action_log` lookup → only then `pageRevision`/`allowednavs`. A completed
  duplicate gets its stored result even with a stale revision.
- `persist()` diffs against the base last written *in this transaction*;
  the committed base moves only after commit.
- `laston` and pure bookkeeping fields are written without bumping `version`.
- Every write path to `accounts` bumps `version` once the migration ran,
  whether or not the UI flags are on.
- Writes to another player are conditional deltas
  (`… gold = gold - :n, version = version + 1 WHERE acctid = :id AND gold >= :n`).
- Conflicts abort with a message and a GET reload; no blanket retry.

### Phase 4 – outbox

1. Events are written in the same transaction as the state change they
   describe. `Realtime::publish()` outside `Action::run()` is refused;
   `Realtime::notify()` (independent notifications) refuses when a foreign
   transaction is open.
2. **Serialised delivery sequence**: `SKIP LOCKED` may distribute work, but
   assigning `seq` happens under `SELECT … FROM relay_sequence … FOR UPDATE`
   held until commit, together with the delivery rows and `relayed_at`.
3. Snapshot and start cursor: read `maxSeq` first, then the snapshot.
4. **Old events must not overwrite newer snapshots**: events are change
   announcements; the client fetches the current state, and every region
   state carries a `regionVersion` the client only ever increases.
5. Channels are derived server side on every poll from the database, never
   from a client-supplied name; bans, logout and clan changes apply on the
   next poll.
6. The poll endpoint releases the session lock and joins the read-only
   allow-list in `async/process.php`.

### Tests that must exist before Phases 2–4 merge

- Process abort in the middle of an action (no orphan row, retry possible).
- Same action id with different parameters (rejected).
- Retry of a completed action with an old page revision (stored result).
- Two relay workers with deliberately swapped commits (no gap, no reorder).
- An old event relayed after the snapshot (client keeps the newer state).
- Two sessions with the same base revision (exactly one succeeds).
- A late partial fallback does not execute the URL twice.

Transaction and locking tests run against real MySQL/MariaDB with separate
connections and sessions; SQLite does not reproduce implicit commits or
row locks.
