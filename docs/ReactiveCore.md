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
| 2 | Partial rendering for display-only operations (HTMX), page revisions, HTTP contract | done (village, forest, inn; see below) |
| 3 | Guarded game actions: `Action::run()`, split `saveUser()`, optimistic locking | done (pilot: the bank; see below) |
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
  `op`): `village.php`, `forest.php` and `inn.php` without `op`, by GET.
  Commentary paging and refresh links carry no `op` and swap in too; posting
  a comment is a form and loads the page normally.
- **Early fallback**: `PartialMode::preCheck()` runs in `common.php` before
  forced navigation. A partial request for any other page gets
  `HX-Redirect` to the same URL and an empty body; the page has not run.
- **Page revision**: `accounts.page_revision` (migration
  `Version20261020000001`). `ForcedNavigation` claims it with
  `UPDATE … SET page_revision = page_revision + 1, allowednavs = '' WHERE acctid = :id AND page_revision = :loaded`
  right after accepting the navigation. Clearing the stored links in the same
  statement makes consuming them atomic: a request that read the row before
  the claim loses the update, and one that reads it afterwards finds no links
  until the winner saves its new ones. A request turned away like this was
  started from a page another request already left (second tab, second
  device): it is answered `409 {"reload":"badnav.php"}` (partial) or
  redirected to `badnav.php` (full page) and does not save the account.
  The client also sends the revision it shows (`X-Lotgd-Page-Revision`);
  one that is not the account's current revision is turned away the same
  way, so a stale tab cannot follow a link merely because the latest page
  offers the same address.
  Same-browser double clicks never reach the server twice: the client sends
  one request at a time, and PHP's session lock serialises the rest.
- **Answer**: `Lotgd\Page\PartialRenderer` – `<title>` first (htmx only
  takes a top-level title, and page content may leave elements open), then
  the content region, then every other region as an `hx-swap-oob` element
  addressed by `[data-lotgd-region=…]`. Headers `X-Lotgd-Partial: 1`,
  `X-Lotgd-Page-Revision`, `Vary: HX-Request`, and for AJAX players
  `X-Lotgd-Async-State` (below).
- **Commentary preview**: for a partial player `Forms::previewField()` renders
  the same elements and ids without the inline `previewtext<name>()` script
  and `onKeyUp` handlers; the field carries its settings (start markup, talk
  line, limits, colour map, the `/game` prefix for gamemasters) as JSON in
  `data-lotgd-preview`, and the client renders the preview from it. Classic
  players keep the inline script byte for byte.
- **AJAX players**: the polling script in `<head>` (`async/setup.php`) starts
  from four per-page values – comment section, last comment id, last unread
  mail id and count. They are left out of the head signature, and every
  partial answer carries them as ASCII JSON in `X-Lotgd-Async-State`; the
  client sets `lotgd_comment_section`, `lotgd_lastCommentId`,
  `lotgd_lastUnreadMailId` and `lotgd_lastUnreadMailCount` after the swap, so
  polling follows the page. The mail slot's copy of the same values
  (`async/maillink.php`, an inline script) is dropped for partial players.
  The timeout warning needs nothing: it is computed on the server from
  `laston`, which every partial request updates.
- **Late fallback**: the page is stored as usual and answered
  `HX-Redirect: badnav.php`, which shows it, when any region (content, nav,
  stats, a header slot) contains `<script`, when the page wants a different
  `<head>` than the one the client shows, or when the player turned the
  preference off. Full pages for partial players carry
  `<meta name="lotgd-head">`, a hash of the head markup without cache
  busters, the canonical link and the client's own meta tags; the client
  sends it back as `X-Lotgd-Head`.
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

Verified in a browser (Chromium via Playwright) against MariaDB 11.4, with
and without the AJAX preference: village → forest → village → inn → village
swapped in without a reload, both navigation copies updated, one revision
claimed per change; a page off the allow-list (the bank) loads as a page
change without running twice; the comment preview renders colour codes and
the characters-left counter on a swapped page; posting a comment loads the
page normally; for AJAX players polling follows the swapped page and a
comment posted elsewhere appears; quick key; Back to `badnav.php`; a double
click sends one request; a stale second tab ends on `badnav.php` without
running its link; without JavaScript the game is unchanged. Two connections
racing the claim from the same revision: one updates a row, the other none.

A page stored before a player turned partial rendering on, or before an
update changed the head signature, has another head; the first change from
it is one full page, every later one swaps.

## Phase 3 – as built

Switched on by the game setting `guarded_actions` (*Run bank actions as
guarded transactions*, off by default). The schema parts are not switched:
once migration `Version20261021000001` ran, `accounts.version` moves on with
every change to the game state, whatever the setting.

- **Schema** (migration `Version20261021000001`): `accounts.version`
  (unsigned, default 0) and the table `action_log` (`acctid`, `action_id`,
  `action_name`, `params_hash`, `request_uri`, `result`, `created`; primary
  key `(acctid, action_id)`). `Newday::dbCleanup()` deletes log rows older
  than a day.
- **`saveUser()` split** (`Lotgd\Accounts`): `persist(bool $checkVersion)`
  is the diff-and-write half, `finishRequest()` stores the page for
  `badnav.php` and reduces the session; `saveUser()` calls both, as before.
  `persist()` moves `version` on by one when it writes anything but
  bookkeeping (`laston`, `lasthit`, `allowednavs`, `page_revision`,
  `version`, `gensize`, `gentime`, `gentimecount`, `restorepage`, `lastip`,
  `uniqueid`) and moves the base on to what it wrote, so a second call writes
  nothing twice. `persist(true)` writes with DBAL,
  `UPDATE … WHERE acctid = :acctid AND version = :expected_version`, only the
  columns the `Account` entity maps, and throws `Lotgd\VersionConflict` when
  no row matched.
- **`Lotgd\Action::run(name, actionId, params, fn)`**, in this order:
  1. a malformed action id (not 32 hex digits) is rejected before anything
     happens; an open transaction or a running action is a `LogicException`;
  2. begin;
  3. `SELECT version FROM accounts … FOR UPDATE` – every action of one
     account queues here, across tabs and devices;
  4. `action_log` lookup, a plain read: the same name and parameter hash
     returns the stored result (`replayed`), another hash is `rejected`
     (`action-id-reused`). This comes before the version comparison, so a
     form already booked gets its result although its page is stale by now;
  5. the locked version against the one this request read the account with:
     different → `conflict`, nothing runs;
  6. snapshot of `$session['user']`, buffs, allowed navs, `$companions` and
     `$baseaccount`;
  7. `fn()`, returning what the page shows (JSON-serialisable);
  8. `Accounts::persist(true)` and the `action_log` row with that result;
  9. commit, then the `Action::afterCommit()` callbacks (best effort; a
     failing one is written to the security log).

  Anything thrown rolls back, restores the snapshot, clears the entity
  manager and the cached account entity, and is rethrown – except a
  `VersionConflict`, a deadlock or a lock wait timeout (DBAL
  `RetryableException`), which are a `conflict`, and
  `Lotgd\Action\ActionAborted`, which is `rejected` with its message as the
  reason. No log row is left behind, so the same form may be sent again.
  `Lotgd\Action\ActionResult` carries `status` (`done`, `replayed`,
  `conflict`, `rejected`), `data` and `reason`.
- **What "conflict" covers**: the version is compared with the one *this
  request* read, not with the one the form was shown with. A change between
  showing a form and sending it is not a conflict – the bank checks the
  amounts against the account as it is now, and the change survives. What is
  caught is a change *while the request runs*: another tab, another device or
  another player's credit landing between reading the account and booking.
  Without the guard such a page-end save writes its old balance over the
  credit; `GuardedActionMariaDbTest` shows both.
- **Why the account row is locked first and the log read is plain**: a
  locking read of the not-yet-existing log row takes a gap lock, and two
  players' first actions (whose rows fall into the same gap) then deadlock
  on each other's insert. The account lock already serialises everything of
  one account, and the plain read's snapshot is taken after it.
- **A form sent again after its page moved on**: the booking gave out new
  links, so the form's address is no longer an allowed navigation and would
  end on `badnav.php` – the player who lost the answer would never learn
  whether the money moved. `ForcedNavigation` asks `Action::isReplayOf()`
  first: a POST whose action id is already logged for this account *and
  this very address* goes through to its page, where the page's CSRF check
  runs and `Action::run()` answers from the log. Nothing can run again – the
  log row that lets the request in is the row that makes it a replay.
- **Guards**: `Modules::inject()` (module refresh), `Modules::semAcquire()`,
  the game-day rollover in `newday.php` and `Newday::dbCleanup()` call
  `Action::guardImplicitCommit()` and throw instead of committing a running
  action through `LOCK TABLES`/`OPTIMIZE TABLE`.
- **The bank** (`bank.php`) is the pilot. The deposit, withdraw, loan and
  transfer-confirmation forms carry `Action::idField()`; `depositfinish`,
  `withdrawfinish` and `transfer3` run as `bank.deposit`, `bank.withdraw` and
  `bank.transfer`. Each booking returns what the page shows, so a replay
  shows the first booking ("This one is already written down") instead of
  booking twice. The transfer credits the recipient with
  `… version = version + 1 WHERE acctid = :acctid`, guarded also
  `AND locked = 0 AND transferredtoday < :received`; no matching row aborts
  the action and the player sees the accounting-error message. Debug log
  lines and the recipient's mail are after-commit callbacks. With the setting
  off the bank renders and books exactly as before; the only difference is
  `version = version + 1` on the credit, as for every write to an account.
- **Metadata cache**: the Doctrine metadata cache namespace now includes a
  fingerprint of the entity files (`Lotgd\Doctrine\EntityFingerprint`).
  Without `DB_DATACACHEPATH` that cache lives in the system's temporary
  directory and was never cleared, so an update mapping a new column was
  served the old mapping – the page end would have silently left
  `version` alone.

Verified against MariaDB 11.4 (`tests/Integration/GuardedActionMariaDbTest.php`,
opt-in, see below): two players crediting one recipient at the same time both
arrive; two players crediting each other at the same time deadlock for real,
one books and the other gets a conflict, no gold appears or vanishes; a
recipient's page read before a credit cannot book over it (and without the
guard it does); a process killed inside its transaction leaves no booking and
no log row, its retry books once and a second retry is a replay; the same
form from two connections at once books once (`done` + `replayed`). In a
browser (Chromium via Playwright): a deposit sent again shows the first
booking and books nothing; Back to a booked form ends on `badnav.php`; a
transfer credits the recipient once, mails them once, and sent again books
nothing; with the setting off the forms carry no action id and the bank
behaves as before.

The opt-in suite runs against an installed copy of this tree whose
`dbconnect.php` names a disposable database:

```bash
LOTGD_INTEGRATION_ROOT=/path/to/installed/game composer test -- tests/Integration
```

It creates its own accounts and removes them; without the variable it is
skipped.

Not yet covered, and open for the next steps: the other writes to `accounts`
listed in the [inventory](ReactiveCore-Inventory.md) (PvP, clans, admin
editors) do not move `version` yet; data cache keys are not invalidated on
rollback (the bank touches none); outbox events (Phase 4) and grant
consumption (Phase 5) do not exist yet.

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

`Action::run(name, actionId, params, fn)`:

1. Refuse when a transaction is already open (no nesting in v1); `LOCK TABLES`
   while an action runs throws.
2. `beginTransaction`.
3. Lock the account row (`SELECT version … FOR UPDATE`) – serialises actions
   per account across sessions and devices. *As built this comes before the
   log lookup: a locking read of the missing log row would take a gap lock,
   and two players' first actions deadlock on each other's insert (found
   against MariaDB).*
4. Look up `action_log (acctid, action_id)` **inside** the transaction
   (a plain read after the account lock): same `action_name` and
   `params_hash` → return the stored result; different hash → reject. No
   separate "running" reservation.
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
  duplicate gets its stored result even with a stale revision. *As built,
  `ForcedNavigation` lets a logged POST for the same address through before
  the page's own CSRF check runs; the page then refuses a bad token as for
  any request, and only a logged action id can get in this way.*
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
