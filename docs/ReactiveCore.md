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
| 4 | Transactional outbox, relay, polling transport | done (pilot: new mail and incoming transfers; see below) |
| 5 | Grants: server-issued one-shot navigation for other players | done (pilot: accepting a clan applicant; see below) |
| 6 | One async transport and popups as in-page overlay | 6a done: one async transport (Jaxon removed); 6b done: popups as in-page overlay (mail, MOTD); 6c done: partial rendering for every display page; live commentary done; see below |
| 7 | SSE transport, presence, live encounters, live duels | done, opt-in; see below |

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
| `nav` | `nav` | any number of elements (the sidebar and the mobile menu) |
| `stats` | `stats` | any number of elements |
| `header` | `motd`, `mail`, `petition`, `petitiondisplay`, `grants` | one element per slot, with `data-lotgd-slot="<variable>"` |

Each element holds its variable and nothing else: a partial answer replaces
its inner HTML, so an ad or a label inside it would be lost on the first swap
(puritanic and modern wrap `content` in a `<div>` of its own for that reason).
Two optional markers:

- `data-lotgd-title` on an element that shows the page title in the layout:
  the client sets its text to the new `<title>` after a swap.
- `data-lotgd-close-on-swap` on a mobile menu (a Bootstrap offcanvas): an open
  one is closed after a swap, as a page change would have left it.

A skin without the declaration, and every legacy `.htm` template, always gets
the full page. Every bundled Twig skin declares regions and the overlay
(aurora, aurora_minimal, aurora_test, modern, puritanic, puritanic_ai);
`tests/Templates/RegionContractTest.php` renders each one and checks every
region holds its variable alone, so a skin of your own can be added there.

## Phase 2 – as built

Switched on by the game setting `partial_render` (off by default) **and** the
player's preference `partialrender`. Everything below only applies to such a
player on a skin with regions; everyone else gets exactly the game of before.

- **Allow-list** (`Lotgd\Page\PartialMode::ALLOWLIST`, script → method →
  `op`): `village.php`, `forest.php` and `inn.php` without `op`, by GET.
  Phase 6c added the other places that only display: the gardens, the rock,
  the shades, the graveyard, the lodge, the gypsy, the hall of fame (its
  lists), the list of warriors, bios, the news, about, referrals, the
  stables (and examining a mount), the weapon and armour shops, the
  mercenary camp, the healer, the trainer (and his question), the clan halls
  and clan list, the player list for PvP and the bank with its forms (every
  booking is a POST). `PartialMode::FORBIDDEN_PARAMETERS` names a parameter
  that turns an allowed page into an action -- `pvp.php?act=attack` -- and
  such a request is a page change before it runs. The bank's focus scripts
  are `autofocus` now, which the partial client applies after a swap; the
  clan detail editor folds with `<details>`.
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
  (`async/maillink.php`, an inline script, removed in Phase 6a) is dropped for partial players.
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
guarded transactions*, off by default), and only when `accounts`,
`action_log` and `mail` are in a transactional engine
(`Lotgd\MySQL\StorageEngine`, checked once per request): a MyISAM table
left from an old release would keep its writes through a rollback, so the
game runs its actions as before until it is converted. The schema parts are not switched:
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
  5. the locked version against the one this request read the account with
     and the one the form was shown with: either different → `conflict`,
     nothing runs;
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
- **What "conflict" covers**: two versions must still hold when the account
  is locked – the one this request read the account with (a change while the
  request runs) and the one the form was shown with (`Action::idField()`
  also writes `action_version`). So a change between showing a form and
  sending it – another tab booked, a transfer arrived, a PvP fight took
  gold – is a conflict: nothing is booked, and an old "withdraw all" cannot
  take gold that arrived after it was shown. A form without the field (an
  older page) is checked against the request's version only. A form already
  booked is still a replay: the log is read before the versions are
  compared. Without the guard a page-end save of a stale page writes its old
  balance over a credit; `GuardedActionMariaDbTest` shows both.
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
form shown before the account changed elsewhere books nothing and the change
survives; a
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

Every other gameplay write to `accounts` in the core moves the version on
too: PvP (flag, loss, win), clan membership and leadership, the user editor
and its "fix navigation", bio blocks, donation points, mount refunds, the
referrer award and the dragon point reset. `tests/QA/AccountsVersionBumpTest.php`
fails for a new `UPDATE accounts` without `version = version + 1` unless it
is listed there as bookkeeping (login, `laston`, `lasthit`, the stored
navigation, the page revision claim, expiry notices, logging banned players
out).

Not yet covered, and open for the next steps: module writes to `accounts`
(see the [inventory](ReactiveCore-Inventory.md)); data cache keys are not
invalidated on rollback (the bank touches none); outbox events (Phase 4) and
grant consumption (Phase 5) do not exist yet.

## Phase 4 – as built

Switched on by the game setting `realtime_enabled` (*Show new mail and
incoming transfers without a page change*, off by default), and only when
`events`, `event_deliveries`, `relay_sequence` and `mail` are transactional
(the relay's numbering rests on a row lock held until commit). A player's pages
poll when it is on **and** the partial client runs for them (Phase 2), since
that client is what swaps the announced regions. Without it, nothing writes
or reads the new tables.

- **Schema** (migration `Version20261022000001`): `events` (`id`
  auto-increment, `event_id` for de-duplication, `channel`, `type`,
  `payload`, `created`, `relayed_at`), `event_deliveries` (`seq` primary key,
  copied event, `KEY (channel, seq)`), `relay_sequence` (one row, `next_seq`).
  `Newday::commentCleanup()` drops deliveries and relayed events older than
  `realtime_retention_hours` (24).
- **Writing events** (`Lotgd\Realtime`):
  - `publish()` only inside `Action::run()` (otherwise `LogicException`): the
    event is written in the action's transaction, and the relay is queued as
    an after-commit callback, so a rollback leaves neither the event nor a
    relay run.
  - `notify()` for an independent notification: refused while somebody
    else's transaction is open, otherwise written and relayed at once.
  - `announce()` for a change that already has a transaction, whichever it
    is: the running action's (then `publish()`), another open one's (the event
    commits or rolls back with it, the next relay or `cron.php` hands it out),
    or none (written and relayed). `Mail::systemMail()` uses it.
  - Kept short by design: an event names a region and its new version
    (`{"region": "mail", "version": <message id>}`), never content.
- **Relay** (`Lotgd\Realtime\Relay::run()`), its own transaction and never
  inside an action: `SELECT next_seq … FOR UPDATE` first, then a **plain**
  read of the unrelayed events, one `event_deliveries` row per event under the
  next number, `relayed_at`, the new `next_seq`, commit. The sequence lock
  queues relays one after another until commit: no number twice, none
  skipped. The plain read does not wait for an action still running; its
  events go to a later run, so delivery order is relay order, not commit
  order. A deadlock or lock timeout leaves the events for the next run.
  It runs after each commit that wrote events, and `cron.php` bit 16
  (`CRON_REALTIME`) drains whatever is left.
- **Sources (pilot)**:
  - new mail: `Mail::systemMail()` announces `mail` for the recipient (version =
    the new message id). The e-mail, which cannot be taken back, now goes out
    through `Action::afterCommit()` (at once outside an action, as before).
  - incoming transfer: with `guarded_actions` on, the bank writes the
    recipient's mail inside the transfer's transaction (Phase 3 sent it after
    the commit) and announces `stats` with the recipient's new account version.
- **Poll endpoint** `async/poll.php` (`Lotgd\Realtime\Poll`; folded into `async/process.php` as the handler `Realtime` in Phase 6a, see "Phase 6a – as built"; the text below is the Phase 4 state):
  - GET only, header `X-Lotgd-Poll: 1` required (a custom header forces a CORS
    preflight, which is never answered, so another site cannot read it). `401`
    when logged out (`ForcedNavigation` re-reads the account on every poll, so
    a ban or logout applies at once), `404` when realtime is off for the
    player. The session is released before the answer is sent.
  - The channels come from the account row just read: `user-<acctid>`; a
    channel in the request is ignored.
  - `?cursor=n`: the deliveries of those channels after `n`, at most 100, and
    the next cursor. A cursor below the oldest kept delivery, or above the
    latest number, answers `resync`: the page fetches its regions again.
    Rate limited by `ajax_rate_limit_seconds` from the async settings
    (`config/async.settings.php`, loaded like `async/process.php` does).
  - `?region=mail|stats`: `{region, version, html}`, the current content as
    the page renders it (`PageParts::mailLink()`, `PageParts::charStats()`)
    and its version (highest message id, account version). Each region has an
    allowance of its own under the same limit, so the fetches that follow a
    poll pass and a client asking again and again does not.
- **Snapshot and cursor**: `ForcedNavigation` notes the highest delivery number
  **before** it reads the account, which the regions show; the footer computes
  the region versions before it builds the regions. The page carries
  `<meta name="lotgd-realtime">` (poll URL and interval),
  `lotgd-event-cursor` and `lotgd-region-versions`. An event that lands in
  between therefore arrives again, and never not at all. `badnav.php` keeps a
  stored page's cursor and versions, which belong to the regions it shows, and
  drops the three tags when realtime is off for the player now.
- **Client** (`assets/js/lotgd.partial.js`): polls every
  `realtime_poll_seconds` (10) while the tab is visible, with exponential
  back-off on errors and a stop on `401`/`404`. It de-duplicates by event id
  and only moves the cursor forward. For an announcement whose version is
  higher than the one shown, it fetches the region and replaces every element
  of it only when the answer's version is still higher. A failed fetch is
  retried with the next poll; an announcement that arrives while a fetch of
  the same region is on its way is fetched right after it.
- **Commentary poll** (Jaxon until Phase 6a): `Commentary::pollUpdates()` leaves the mail link out for players
  the realtime poll runs for, so the two never write the same region.

Verified against MariaDB 11.4 (`tests/Integration/RealtimeMariaDbTest.php`,
opt-in like Phase 3):
- an event written first but committed last is still delivered, after the
  event that committed first, with consecutive numbers; the relay run in
  between did not wait for the open transaction;
- three relays draining 60 events at once hand out 60 consecutive numbers,
  each event once;
- a relay killed with the sequence row locked delivers nothing; the next one
  hands out the same number;
- an event after a page read its cursor comes after the cursor.

In a browser (two players, Chromium against MariaDB): A transfers to B, B's
mail link and stats change on B's open page without a reload, with one poll
and one fetch per region; with the setting off, a page shows no realtime tags
and sends no polls. The Phase 2 and Phase 3 browser runs pass unchanged with
realtime on.

Deviation from the criteria: the poll endpoint is a page of its own
(`async/poll.php`) rather than a Jaxon handler (Phase 6a folded it into the `Realtime` handler of `async/process.php`), so it did not join the
read-only allow-list in `async/process.php`; it releases the session itself.
Commentary sections got channels of their own later (see *Live commentary*);
not yet covered: clan and global channels, and module sources.

## Phase 5 – as built

Switched on by the game setting `grants_enabled` (*Offer clan officers a
one-time link to accept a new applicant*, off by default). Grants are used
inside guarded actions, so they also need `guarded_actions` and a
transactional `granted_navs` table; without all three nothing writes or reads
the table and clan applications work as before (a mail to every officer).

- **Schema** (migration `Version20261023000001`): `granted_navs` (`id`,
  `acctid` the player it is for, `granted_by`, `action`, `target` (JSON),
  `target_key` the group, `text`, `link` the exact view URI, `method`,
  `created`, `expires_at`, `used_at`, `used_by_action_id`, `changed_us` the
  microsecond of the last change). `Newday::commentCleanup()` drops used and
  expired grants after seven days. `grant_ttl_hours` (168) is how long one
  stays open.
- **Issuing** (`Nav::grant()` → `Lotgd\Grant::issue()`): one row per player,
  the view link with `&grant=<id>` appended, and a `grants` announcement on
  the player's channel (Phase 4), in whatever transaction holds the change.
- **Using** (`Grant::claim()`, only inside `Action::run()`): read the grant's
  `target_key`, lock **the whole group** with `… WHERE target_key = :key ORDER
  BY id FOR UPDATE`, check the own row (this player's, open, not expired; else
  `ActionAborted` `grant-missing` | `grant-used` | `grant-expired`), then close
  every open grant of the group with the action id and announce the change to
  each of their players. Two players using grants of one group queue on the
  same locks in the same order instead of deadlocking, and the second finds
  the group closed. The close commits with the action: an action that fails
  afterwards rolls it back, and the grants are open again.
- **Voiding** (`Grant::voidTarget()`): the thing offered went away another way
  (the applicant withdrew, applied elsewhere, was accepted or removed through
  the membership page): the group's open grants close, their players hear it.
- **Display**: the footer adds the open grants of the player as a navigation
  section *Waiting for you* on every skin (before the navigation is built,
  so the links are allowed navigations). Aurora also has a header slot
  `grants` (`data-lotgd-region="header" data-lotgd-slot="grants"`) that the
  realtime client swaps: `Poll::REGIONS` gains `grants` (content: the open
  grants as links, version: the highest `changed_us` of the player's grants),
  and `lotgd-region-versions` carries its version.
- **Navigation**: a link swapped into the slot after the page was built is
  not in `allowednavs`. `ForcedNavigation` lets a **GET** through when it is
  exactly the view link of an open grant of this player
  (`Grant::isOfferedTo()`, the session counter `&c=…` removed). That request
  only displays; the POST that decides is registered by the page as usual,
  checked for CSRF, and consumes the grant inside its action. A used,
  voided or expired grant's link ends on `badnav.php`.

### Pilot: accepting a clan applicant

- `pages/clan/applicant_apply.php`: after the officers' mail, every officer
  gets a grant `clan.accept` with target `{applicant, clan}` and group
  `clan-applicant-<acctid>` (`Lotgd\Clan\Application::offer()`), after the
  player's earlier offers are voided.
- `clan.php?op=grant&grant=<id>` (`pages/clan/clan_grant.php`): the GET shows
  the application and a form with the CSRF token and the action id; the POST
  runs `Action::run('clan.accept', …)` → `Application::accept()`: claim the
  grant; the accepting player must still be an officer of that clan;
  `UPDATE accounts SET clanrank = <member>, clanjoindate, version = version + 1
  WHERE acctid = :applicant AND clanid = :clan AND clanrank = <applicant>`
  (no row → `applicant-gone`, rolled back); the applicant's mail in the same
  transaction. Replayed, conflicting and refused results each have a text.
- The applicant withdrawing, and an officer changing the applicant's rank or
  removing them on the membership page, void the group.
- Fixed with it: the membership page's rank change and removal looked the
  player up and wrote them by account id alone; an officer could post any
  account id and change ranks in another clan. Lookup and writes now also
  require `clanid` = the officer's clan.

Verified against MariaDB 11.4 (`tests/Integration/GrantMariaDbTest.php`,
opt-in like Phase 3):
- two officers accepting the same applicant at the same moment: one `done`,
  the other `rejected` (`grant-used`), the applicant a member once, one mail,
  no deadlock;
- an acceptance that throws after claiming leaves both grants open and the
  applicant an applicant; the same officer can then accept;
- an expired grant is refused; an applicant who withdrew is not accepted and
  the grant stays open (rolled back).

In a browser (three players, Chromium against MariaDB): the applicant applies,
both officers' grants slot shows *Accept Nina into the clan* without a reload;
one officer opens the slot link (not in their allowed navigations) and
accepts; the applicant is a member and has the mail; the other officer's slot
empties without a reload, the stale link in their navigation shows *already
been decided*; the used view link ends on `badnav.php`. The Phase 2–4 browser
runs pass unchanged.

Deviation from the concept: `Nav::grant()` takes the group (`target_key`) as
a parameter of its own and no `method` (every grant is used by a POST). The
grant's text is stored in the language of the player who started it.

## Phase 6a – as built

No setting: it replaces the transport for everything async and changes no
game rule.

- **Endpoint**: `async/process.php` only. `async/poll.php`, `Poll::handle()`,
  `async/maillink.php`, Jaxon (`jaxon-php/jaxon-core` and dependencies,
  `async/common/jaxon.php`, `async/js/lotgd.jaxon.js`, `async/js/vendor/jaxon`,
  `tests/Async/check-jaxon-assets.sh`) are removed.
- **Request**: POST with one field `call` = JSON `{class, method, args}`;
  headers `X-Lotgd-Async: 1` (required, else `400`; a custom header forces a
  CORS preflight whatever the CSRF mode) and `X-LotGD-Csrf`.
- **Policies kept**: authentication, the unauthenticated allow-list, superuser
  bits, CSRF (`log`/`enforce`), the denied-request throttle, the read-only
  session release, the JSON error format with megauser diagnostics. Rate limits
  are per call in `$_SESSION['lastrequest'][class::method]`, per region for
  `Realtime.region`. Log lines are
  `Async csrf`, `Async rate limit hit`, `Async authorization:`,
  `Async bad request` (were `Jaxon …`).
- **Dispatcher** (`Lotgd\Async\Dispatcher`): the class is a registered name,
  never a PHP class from the request. Core: `Bans`, `Commentary`, `Mail`,
  `Realtime`, `Timeout`, `TwoFactorAuthPasskey`; modules add handlers through
  the hook `async-handlers` (`['Name' => FQCN]`). Only public methods the
  class declares itself; `EXCLUDED` keeps PHP-only methods out
  (`Commentary::test`, `Timeout::getInstance`, the passkey setters).
- **Response** (`Lotgd\Async\Response`): commands `assign`, `append`, `title`
  and `call` (only functions the client allows) plus `data`; JSON
  `{status, commands, data}`.
- **Realtime handler**: `Realtime.poll` (announcements after the cursor, for
  realtime-only players) and `Realtime.region(name)` (the region fetch of
  Phase 4) with the semantics of Phase 4 (`resync`, region allowance, session
  released).
- **Single poll**: one loop per interval. `Commentary.pollUpdates(section,
  lastId, cursor)` carries the realtime cursor and answers announcements with
  the comments; realtime-only players call `Realtime.poll` instead. Region
  fetches are `Realtime.region(name)` on the same endpoint.
- **Client** (`assets/js/lotgd.async.js`): page configuration is in
  `<meta name="lotgd-async">` written by `async/setup.php` (no inline
  scripts); `<meta name="lotgd-realtime">` now holds only the interval in
  seconds.
- **Shim** (deprecated, removal next major): `window.getJaxonHandlers()`,
  `window.Lotgd.Async.Handler.<Class>.<method>(...)` and
  `window.jaxon.config.requestURI` keep old module code working (the
  `twofactorauth` passkey bridge and its callback
  `twofactorauthHandleJaxonResponse`).

Verification: unit suites `tests/Async`, `tests/Ajax` and `tests/Realtime`
pass. In a browser, as a classic AJAX player and as a partial + realtime
player: a comment posted from elsewhere appears, the mail link updates, only
`process.php` is called, there is one poll per interval, a region fetch uses
the same endpoint, a superuser `Bans` call works, and a request without
`X-Lotgd-Async` is answered `400`. The Phase 2 to 5 browser suites pass; two
of their checks read the old transport (a Jaxon global, `poll.php` URLs) and
now read `lotgd.async.state()` and the `process.php` calls.

SSE / Mercure moved to Phase 7.

## Phase 6b – as built

On by skin, not by setting: a Twig skin that declares `"overlay": true` in
its `config.json` (Aurora) shows popup pages -- pages that end with
`Footer::popupFooter()`, so far mail and the MOTD -- in a dialog inside the
page. A skin without the declaration, every legacy `.htm` template, and a
browser without JavaScript keep the window. Every bundled Twig skin declares
it. The dialog's colours come from the skin (`--lotgd-overlay-bg`, `-fg`,
`-border`, `-scheme` in its `style.css`): aurora, aurora_test and modern are
always dark, aurora_minimal, puritanic and puritanic_ai follow the system's
dark mode through their own palette variables. Bootstrap's `--bs-body-bg` is
not used, since no skin switches Bootstrap to its dark theme and it is white.

- **Server** (`Lotgd\Page\Overlay`): a request with `X-Lotgd-Overlay: 1` to a
  popup page is answered with its content alone, the title percent-encoded in
  `X-Lotgd-Overlay-Title`, `X-Lotgd-Overlay-Close: 1` when the page is done
  (mail's "Send and Close", which used to run `window.close()`). The page runs
  exactly as in a window; mail and the MOTD override forced navigation, so the
  page behind the dialog keeps its links and page revision.
- **Links**: `Overlay::attribute()` adds `data-lotgd-overlay` to the links that
  open popups (`PageParts::mailLink()`, `motdLink()`, "write mail" on bios,
  the player list, petitions). The `href` and the window-opening `onclick`
  stay, as the fallback.
- **MOTD**: with `forcedmotdpopup`, an unread MOTD is opened by
  `<meta name="lotgd-overlay-open">` instead of `window.open`; `badnav.php`
  drops that tag from a stored page once the MOTD has been read. This covers
  what the module `motd_js_popup` did.
- **Client** (`assets/js/lotgd.overlay.js`, `assets/css/lotgd.overlay.css`):
  a native `<dialog>`; a plain click on an overlay link (before its own
  onclick) fetches the page into it; links and forms inside stay inside
  (forms with their submitter, redirects followed); an answer that is not an
  overlay answer opens in a new window; Escape, the close button or the
  backdrop close it, and the mail region of the header is refreshed
  (`lotgd.async.refresh('mail')`). Content never runs scripts.
- **Mail pages without inline scripts** in the overlay (the window path is
  unchanged byte for byte): inbox "Check all" and "Check by name"
  (`data-lotgd-check-all`, `data-lotgd-check-by`), the composer's resize
  buttons, character counter and superuser warning (`data-lotgd-resize`,
  `data-lotgd-count`, `data-lotgd-su-warning`), the address book focus
  (`autofocus`).

Verified in a browser (Chromium against MariaDB): on Aurora the inbox opens
in a dialog and no window; reading, replying (counter, resize), "Send and
Close" (dialog closes, mail sent, header link updated), check all and delete
(redirect followed inside), Escape; the page behind keeps its links; a new
forced MOTD opens by itself once and not again after it was read; on a legacy
skin the link has no overlay attribute and opens a window. The earlier
browser suites pass.

## Live commentary – as built

New comments reach the players reading a section without a page change. It
needs nothing new: with `realtime_enabled` on, a player for whom realtime
runs (partial rendering on, a skin with regions) gets them announced; a player
with only the AJAX preference keeps polling for them as before.

**Who reads a section.** A section is read outside its page only if a page
showed it to the player in this session -- the page is what decides who
reads it (`superuser.php`, `viewpetition.php`, `clan.php`, the hook
`blockcommentarea`) -- and the account read for the request still passes the
page's own check for the sections that belong to someone: `clan-<id>` for a
member of that clan, `superuser…` for a superuser who is not a translator
alone, `pet-<id>` with `SU_EDIT_PETITIONS`. `Commentary::viewCommentary()`
remembers the last eight sections it rendered (`$session['commentary_sections']`,
the final name after hooks such as `sectionedchats`), and
`Commentary::mayRead()` checks both. `commentaryText`, `commentaryRefresh`,
`pollUpdates` and `Realtime.poll` answer nothing for any other section.
Before, they took the section from the request, so any logged-in player could
read a clan hall, the Grotto or a petition by naming it.

**What a page asks for.** The async state names the section the page shows
(`Commentary::shownSection()`), or `''` when it shows none; a page without
commentary no longer polls the last section it showed.

**Announcement.** `Commentary::injectRawComment()`, the one way comments are
written, announces `{region: commentary, version: <comment id>}` on the
channel `section-<section>` (`Realtime::sectionChannel()`), on its own
transaction like any `announce()`. `Realtime.poll(cursor, section)` adds that
channel when `mayRead()` allows it; the request names the section, the server
decides whether its channel is read. A realtime poll does not look for
comments itself: an announcement newer than the last comment shown makes the
client fetch the lines after it with `Commentary.commentaryRefresh`, once,
and again only if another arrived meanwhile; a `resync` fetches too. An
answer the 50-line limit cut short says `more`, and the client fetches the
rest after a pause of 1.5 s (the rate limit); a fetch that fails is tried
again with the next poll.

**Rendering.** The fetched lines are filtered and hooked as on the page:
comments of locked accounts are left out, and every line passes the hook
`viewcommentary`. At most 50 lines per fetch (`more` when cut short). The [Del] buttons are drawn by
the page only (they need its navigation and token).

Not live: deletions and moderation (they show on the next page change, as
before), comments a module writes with its own SQL instead of
`injectrawcomment()`, and a moderator's undelete in `moderate.php`.

Verified in a browser (three players, Chromium against MariaDB): A writes in
the village; B (realtime) sees it without a reload after one poll and one
fetch, and B's polls look for no comments; C (AJAX only) sees it by polling;
60 comments at once all reach B (two fetches); a fetch that fails is
retried with the next poll; A writes in the clan hall; B, not a member, gets nothing by naming `clan-14`
to `commentaryRefresh` or `pollUpdates`; in the forest, B's poll names no
section. The earlier browser suites pass.

## Phase 7 – server-sent events, as built

With the game setting *How pages receive realtime events*
(`realtime_transport`) at `sse`, a realtime page reads its announcements from
an EventSource on `async/stream.php` instead of polling for them: within about
a second of the relay rather than within `realtime_poll_seconds`. The default
stays `poll`, which runs on any host; the stream holds one PHP worker per
open page, which shared webspace does not offer. The page still carries the
poll's settings, and polls whenever the stream cannot run.

**What the stream sends.** The poll's own answer (`Poll::events()`):
`{cursor, events, resync?}`, one message per batch, with the cursor as the
message id. A browser that reconnects sends it back as `Last-Event-ID`, so
nothing between two streams is missed; announcements the page already has
come again and are dropped by their event id, as with the poll.
`Lotgd\Realtime\Stream` looks at `relay_sequence.next_seq` (one primary-key
row) every second and reads the deliveries only when it moved. It ends after
`realtime_stream_seconds` (default 50, below the 90-second execution limit)
with `retry: 2000`; the browser reconnects by itself.

**Who may open it.** `async/stream.php` is a GET, and an EventSource sends no
custom header: the async CSRF token travels in the query and is always
checked, whatever `csrf_mode` is, and a browser that sends
`Sec-Fetch-Site` other than `same-origin` is refused. Logged out → 401, the
setting at `poll` or realtime off for the player → 404, a second stream
within `ajax_rate_limit_seconds` → 429. The session is released before the
stream starts; the rate-limit stamp is the last thing written to it.

**What it checks while it runs.** Every second: that it is still the
player's one stream (table `realtime_streams`, one row per account holding
the token of the latest stream). A new stream (another tab, a reload, the
next page) takes the row over, and the one before ends with `event: replaced`
within a second, so no player holds more than one worker for longer. Every 15
seconds, with a `: ping` comment: the account, read again -- `loggedin`
cleared (logout, ban), `laston` older than `LOGINTIMEOUT`, or the setting
turned off ends the stream with `event: end` -- and the channels, derived
again from that account: a clan hall the player left drops out, as with the
poll. The stream does not move `laston`; the timeout warning is polled as
before.

**The client** (`assets/js/lotgd.async.js`) opens the stream when the page
names it (`<meta name="lotgd-realtime-stream">`) and the browser has
EventSource, and hands each message to the same `announcements()` as a poll
answer. While it streams, the poll leaves the realtime part out (players with
the AJAX preference still poll their mail, timeout and commentary updates)
and only retries a region or comment fetch that failed. It polls instead
after `replaced` or `end`, or after three failed connects in a row without a
message in between; a refused connect (a stream opened right after the last
page's, within the rate limit) is tried again after two seconds first. A
swap to a page with another commentary section reopens the stream for it.

**Stored pages.** badnav.php shows the page stored for the account, which
another session of the player may have stored, with that session's async
token. It now sets this session's token in `<meta name="lotgd-async">`
(`Lotgd\Page\StoredPage`): the stream refuses any other, and so does every
async call with `csrf_mode` enforced. The stream meta is left out of a stored
page while the setting is `poll`.

**Hosting.** Each streaming page holds a PHP worker: size Apache's
`MaxRequestWorkers` or PHP-FPM's `pm.max_children` for the players online at
once, plus page requests. A proxy must pass the stream unbuffered: the
endpoint sends `X-Accel-Buffering: no` (nginx), Caddy flushes event streams
by itself, and `async/stream.php` turns PHP's output compression off for
itself. With the PHP built-in server, set `PHP_CLI_SERVER_WORKERS`, or the
stream blocks every other request.

Verified in a browser (Chromium against MariaDB, setting `sse`): a transfer
from A reaches B's mail link 0.1--1.4 s after A sent it, and B makes no
realtime poll; a second tab of B takes the stream and the first falls back to
polling; a page whose stream cannot connect polls; after its lifetime the
stream reconnects; a swap to the forest reopens it without a section; with
`poll` no page names the stream. The earlier browser suites pass with
`poll`, and the commentary suite with `sse` too, apart from its checks of
the poll's arguments.

## Presence and live encounters – as built

The groundwork for live player-to-player interaction, live PvP above all:
who is online and where, and a challenge one player sends another that is
answered on the spot and opens a session the two share. The duel itself --
its rounds over the encounter's channel -- is the next step.

**Presence** (`Lotgd\Presence`, table `presence`, with `realtime_enabled`).
The page footer notes a coarse place for the script of every page
(`Presence::PLACES`: village, inn, gardens, forest, clan, shades, arena;
anything else is `elsewhere`; the hook `presence-place` lets a module name
its own). It writes the row only when the place changed or the last write is
a minute old, and announces only a change of place, coming back after a
timeout, and a logout, on the channel `presence` (`{region: presence,
version: changed_us}`). A logout keeps the row, marked gone, so the region's
version still grows. A player who times out without a request is left out of
the next list; the list is not announced for it. Every realtime page reads
the channel; only a page that shows the region `presence` fetches it.

**Encounters** (`Lotgd\Encounter`, tables `encounters` and
`encounter_slots`, game setting `live_encounters`, off by default; needs
`realtime_enabled`, `grants_enabled`, `guarded_actions` and InnoDB). The
duelling ground (`encounter.php`, a link in the village) lists who is around,
in a region `presence` inside one challenge form, with a button for each
player who may be challenged: in the village, the inn, the gardens or at the
duelling ground (`Presence::PEACEFUL`) -- never someone in a fight, among the
shades, or on a page of their own. Every step is a POST inside
`Action::run()`:

- *challenge*: the opponent's place is checked again on the row read now;
  both players take their slot in `encounter_slots` in the order of their
  ids (its primary key keeps a player in one open encounter; two players
  challenging each other at once queue on the lower id's row, and the second
  finds it taken -- no deadlock); the opponent is offered a grant
  (`encounter-<id>`, valid `encounter_challenge_seconds`, default 120) that
  shows in their navigation and header without a reload. A stale slot is
  freed by its own row, never by a range: a range delete would lock the gaps
  the inserts write into.
- *answer* (the opponent): uses the grant up, then accepts (the encounter is
  open for an hour and runs the hook `encounter-accepted` after the
  commit) or declines (both slots free).
- *withdraw* (the challenger, while unanswered): the grant closes.
- *leave* (either player, once accepted): the encounter ends.

Each change is published to both players' `user-*` channels and to the
encounter's own channel `encounter-<id>`, which the poll and the stream read
for every open encounter of the player (`Poll::channels()`, shared by
both). The region `encounter` says where the player's latest encounter
stands; it links to it only when the player's page registered that link
(the key with its session counter, from `allowednavs` read now), so a
region swap never offers a link that badnav.php would refuse. The
opponent's view link is the grant's, which `Grant::isOfferedTo()` allows.

Verified against MariaDB (`tests/Integration/EncounterMariaDbTest.php`):
two players challenging each other at once open one encounter; two
challengers for one opponent open one, and the other is told the opponent
is busy; an answer racing a withdrawal decides once. In a browser (two
players, Chromium against MariaDB, with `sse` and with `poll`): Dora's
button disappears from Admin's list when she walks into the forest and
returns when she is back, without a reload; Admin's challenge appears in
Dora's header without a reload; she accepts, and Admin's page says so
without a reload; leaving ends it on both pages; a withdrawn challenge
disappears from Dora's header by itself; a declined one shows on Admin's
page; a button used after its player left for the forest is refused; Dora
leaves the list when she logs out. The earlier browser suites pass.

## Live duels – as built

An accepted challenge is a duel in rounds, fought on the duelling ground with
both pages updating without a reload. It runs on any host -- the rounds are
seconds long, so SSE and polling both carry it.

**Stakes.** The challenger picks them, and the opponent sees them in the
offer before accepting (`encounters.stakes`):

- *friendly*: nothing changes on either account. The result is in the
  encounter and the news.
- *serious*: what PvP pays and costs (`Lotgd\Pvp\Payout`, the formulas of
  `Pvp::victory()` / `Pvp::defeat()` without their output), with the
  challenger as the attacker. The loser dies; gold on hand and a share of
  the experience go as in a player fight; both get a mail.
  - Allowed only when `pvp` is on, both are within `pvprange` levels of each
    other, neither is protected as a new player (`pvpimmunity`,
    `pvpminexp`), and the challenger has a player fight left today
    (`Encounter::seriousRefusal()`).
  - This is checked when challenging and again when accepting, and the
    acceptance spends the fight.

**Rounds** (`Lotgd\Encounter\RoundDuel`, `Lotgd\Encounter\Duel`):

- **Choices.** Both players choose at once and in secret: *attack*, *guard*
  (half the attack, half again the defense), or *give up*. They choose with
  buttons in the encounter region, which `assets/js/lotgd.duel.js` posts to
  the async handler `Duel.choose`.
- **The exchange.** A round is one exchange of the core's PvP damage roll
  (`Battle::computePlayerDamage()` with `isPvp`). It uses only the values the
  duel began with: `PlayerFunctions::getPlayerAttack/Defense()` and the
  hitpoints when the challenge was accepted, with no buffs and no
  companions.
- **When a round is resolved.** The second choice resolves the round at
  once. Otherwise, once `duel_round_seconds` (default 20) have passed, each
  page asks `Duel.resolve` and the server resolves it once. A player who did
  not choose attacks.
- **How the duel ends:**
  - *knockout*: when both are down, whoever is less far below wins;
  - *give up*: leaving the duel page with its button gives up too;
  - *rounds*: after `duel_max_rounds` (default 30), the larger share of
    hitpoints left wins;
  - *abandoned*: two rounds in a row without any choice from either player
    end it as a draw, with no stakes.

**Storage and locking.** The state lives in `encounters.data`, and there is
one `encounter_rounds` row per round with the two hidden choices. Every move
runs inside `Action::run()`:

1. the player's account is locked;
2. then the encounter row;
3. then the round's row.

A choice racing the deadline, or both pages resolving at once, therefore
resolve the round once. The action id is derived from the move, so a call
repeated after a lost answer gets the first answer. A deadlock between two
resolving pages comes back as a conflict, and the page fetches the region
again. Announcements say *that* a player chose, never *what*.

**Settlement** (`Encounter::settle()`) does not care who fought. It takes an
`Lotgd\Encounter\Outcome` (winner, loser or draw, reason, rounds) and writes
the stakes:

- the acting player's own account through the session, under the action's
  version check;
- the other player's row read `FOR UPDATE` and written whole, with
  `version = version + 1`;
- the mails inside the transaction;
- the news and the hook `duel-finished` after the commit.

The hooks `pvpwin`/`pvploss` do not run: their arguments describe the
offline defender of pvp.php.

**Later: an arena server.** A real-time game, Bomberman-like, needs a process
of its own that ticks many times a second and keeps its state in memory, on a
host that can run one. It would sit behind the same two seams:

- `Lotgd\Encounter\Engine::start()` gives it the players' values. Handed
  out as a ticket the core signs (HMAC over encounter, players, values and
  expiry), these let the arena trust where they came from.
- Its result comes back as an `Outcome`, signed the same way, to an endpoint
  that calls `Encounter::settle()` inside a guarded action.

Neither the ticket nor the endpoint is built yet.

Verified:

- **Unit tests:** `tests/Encounter/RoundDuelTest.php` (scripted dice),
  `DuelTest.php` (stub connection), `DuelHandlerTest.php`,
  `tests/Pvp/PayoutTest.php`.
- **Against MariaDB** (`tests/Integration/EncounterMariaDbTest.php`): two
  choices at once resolve the round once and open one next round; a choice
  racing the deadline is resolved once; giving up while the other page
  resolves pays a serious duel at most once; a serious challenge outside the
  level range is refused.
- **In a browser** (two players, Chromium against MariaDB, with `sse` and
  with `poll`):
  - Dora sees that Admin chose, but not what;
  - both see the round resolved without a reload;
  - a round nobody plays resolves when its time is up, both attacking;
  - giving up ends the duel on both pages;
  - a friendly duel leaves both accounts as they were;
  - a serious one kills the loser, moves the gold and experience as PvP
    does, and mails both.

### From the PvP list

`pvp.php` lists, below the sleeping targets, the players online in a peaceful place
(`Encounter::liveTargetsHtml()`):
- a serious duel is offered where `Encounter::seriousRefusal()` allows it, a friendly one always;
- every button is its own form posting to `encounter.php?op=challenge`, with that page's token
  (`Forms::csrfField('encounter.php?op=challenge')`) and an action id;
- the challenge then runs exactly as one made on the duelling ground. Only the opponent's place counts, so
  challenging from the fields is fine.

### Rematch, spectators, record

- **Rematch.** `Encounter::rematch()` runs inside `Action::run()`:
  - only a player of a duel that is over may ask;
  - it challenges the other at the same stakes through `challenge()`, so every check applies again.
  - `encounter.php` renders the button in a controls block for state `ended`. That block is hidden while the duel is
    pending or fought; `lotgd.duel.js` shows it once the region reaches `ended`, without a reload.
- **Spectators.** `encounter.php?op=watch&id=N` shows a duel, fought or just over, in the region `duelwatch`:
  - `Duel::watchHtml()`: both bars, who has chosen (not what), the log in the third person;
  - no buttons and no `data-lotgd-duel`, so a spectator never chooses or resolves.
  - The page keeps the id in `$session['watching']`. `Poll::channels()` adds `encounter-<id>` after checking the row
    (`Encounter::watching()`). `Encounter::announce()` also publishes `duelwatch` on that channel.
  - The footer ends the watching on any page that does not show it (`Encounter::endWatchUnlessShown()`).
  - The watch page is a whole page, not partially swapped, so the stream it opens reads that session.
  - The duelling ground lists the duels being fought (`Encounter::running()`).
  - With `duel_announce_inn` the inn gets a system comment when a duel begins.
- **Record.** `Encounter::settle()` writes `encounters.winner` and `encounters.loser` (none in a draw).
  `Encounter::record()` counts them for the bio line; hof.php's `duels` ranks them. Both are shown only with live
  encounters on.

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
   `init`/`destroy`. The async client (Jaxon before Phase 6a) and HTMX own disjoint regions.

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
