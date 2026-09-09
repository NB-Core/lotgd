# Changelog

All notable changes to this project will be documented in this file.  
This project follows semantic versioning (MAJOR.MINOR.PATCH) starting from 2.0.0.  

The last **official 1.x release** was [`v1.3.2`](https://github.com/NB-Core/lotgd/commit/2be7254fc2b68bf91e86d71361556667e826ecf1) (2025-06-02).  
Everything below reflects the path from 1.3.2 → 2.0 RCs.  

---

## [Unreleased]

### Security

- Give every state-changing page the same CSRF guard, in one shape. `Forms::isUnverifiedPost()` sits at each page's entry, after it reads `$op`, and turns a POST without this page's form token into a plain page view by clearing `$op` and the body. At the entry rather than in each branch: a per-branch check is one somebody can forget — which is how a dozen editors came to write with no token at all — and a delete keys off `$op` with its id in the query string, so emptying the body alone would not stop it. An editor whose token must not be interchangeable passes its own scope, `Forms::isUnverifiedPost(Csrf::SCOPE_MOUNT_EDITOR)`. Fourteen pages that had no check gain one: `badword.php`, `bans.php`, `clan.php`, `deathmessages.php`, `donators.php`, `mail.php`, `masters.php`, `moderate.php`, `modules.php`, `taunt.php`, `translatortool.php`, `untranslated.php`, `viewpetition.php` and the clan and ban sub-pages.
- Turn every remaining destructive link into a POST button carrying a token. Deleting a creature, taunt, title, death message, master, ban, mail or module — and removing or demoting a clan member — was `<a href='…?op=del&id=N'>` with a JavaScript confirm beside it. Neither half held: `SameSite=Lax` sends the session cookie on a top-level GET navigation, and the confirm never runs on a navigation the user did not start. All of them render through `Forms::postButton()` now, including the two this project had already converted by hand.
- Put output encoding in one class. `Lotgd\Security\Escape` answers the only two questions that matter — `html()` for markup, `js()` for script — and `confirmAttribute()` builds the whole handler. It replaces four competing recipes: `json_encode` with two flags, `addslashes(htmlentities())` in `src/Lotgd/Moderate.php`, raw interpolation, and a local `mountEditorActionForm()` in `mounts.php` that rebuilt the entire form. Three of the four were wrong. The messages come from the translations table, which `SU_IS_TRANSLATOR` writes, so an apostrophe closed the attribute and a double quote closed the JS string on pages every player opens.
- Serve the Jaxon browser runtime from the tree instead of `cdn.jsdelivr.net`. `async/js/vendor/jaxon` now holds the files jaxon-core would otherwise pull from a CDN, and `js.lib.uri` points at them. A third party shipping executable code into every player's browser is a dependency like any other and belongs where a change shows up in a diff — and here it carries a security control, since the async CSRF header depends on how that runtime builds its request. `tests/Async/check-jaxon-assets.sh` verifies the files against recorded checksums and reports when upstream moves on, which nothing else would: they are not a declared dependency, so Dependabot cannot see them.
- Stop the browser from discarding the async CSRF header. Jaxon defaults `httpRequestOptions.mode` to `no-cors`, under which the "request-no-cors" header guard drops every header that is not CORS-safelisted — including `X-LotGD-Csrf`, and including on same-origin requests. The check shipped in the previous release could therefore never have received a token; it was in log-only mode, which is why nobody noticed anything but a log line. Confirmed in a real browser against the vendored runtime: with `no-cors` the header does not arrive, with `same-origin` it does. `async/js/lotgd.jaxon.js` now sets `same-origin`, which is also the honest description of a client whose transport URI is pinned to this origin.
- Keep `csrf_mode` on `log` for this release, and give a client rendered after the upgrade a way to recover: a refusal stops the polling loop and reloads the page once, guarded in `sessionStorage` so a failure that persists across reloads cannot become a reload loop. The original reason for `log` — a transport that could not be verified — is gone, but a second one is not: the client is inlined into each page, so a tab opened before an upgrade keeps a client with neither the `same-origin` fix nor that recovery, and enforcing at upgrade time would strand it. Promotion is the operator's call once tabs have aged out.
- Require a CSRF token for the creature editor's module preferences. Correcting the form action turned a branch that could never be submitted into a working POST that writes every posted field through `set_module_objpref()`; it had no token, because until now nothing could reach it.
- Check the pre-login passkey callables instead of skipping them. `beginAuthentication` and `verifyAuthentication` were exempt on the assumption that no session-scoped token exists before a login; that is not so, because every page reaching them loads `async/setup.php`, which issues one. The result is now recorded, but still never refused: refusing would trade defence in depth on a path that already validates its own token for the risk that nobody completes two-factor login.
- Record an async CSRF failure only for logged-in callers, plus that observe-only passkey pair. An unauthenticated request is refused on authentication whatever its token says, so its token is not evidence about the transport, and logging it would keep the signal `UPGRADING.md` tells an operator to watch from ever falling quiet: a tab whose session timed out keeps polling with the token inlined into the page it was rendered from, and a bare POST to the endpoint carries none at all — free log noise for anyone who cares to send one.
- Require a POST and the user editor's token to delete an account. `user.php?op=del&userid=N` was a plain `<a href>` rendered in the user list, and the row registered the bare URL as a navigation entry, so an admin who followed a crafted link removed the account — `SameSite=Lax` does send the session cookie on a top-level GET navigation, and the `onClick` confirm never runs on a navigation the admin did not start. Same shape as the MoTD deletion closed in the previous release, one impact class higher.
- Require a POST and a token to delete your own character, and take the account from the session rather than the URL. `prefs.php` rendered a POST form but also registered the same URL as a navigation entry and never checked the request method, so a GET was enough. The account it deleted came from `userid` in the query string, and `PlayerFunctions::charCleanup()` does not compare its argument to the session — the navigation allowlist happening to contain only the player's own id was the sole thing preventing deletion of somebody else's character. The request parameter is no longer consulted, and the delete is bound rather than interpolated.
- Require a token before `rawsql.php` executes anything. It runs whatever SQL or PHP it is handed under `SU_RAW_SQL`, which makes it the most valuable target in the tree and the cheapest to protect. Each destructive operation gets its own scope, so a token issued by an item editor cannot open it.
- Escape the self-delete button's texts for where they land. `prefs.php` put the confirmation text raw into a double-quoted `confirm()` argument and the label raw into a single-quoted attribute, so an apostrophe closed the attribute and a double quote closed the JS string. Both come from `Translator::translateInline()`, which reads the translations table — written under `SU_IS_TRANSLATOR`, so they are not constants, and this rendered on a page every player opens. `json_encode()` with `JSON_HEX_APOS | JSON_HEX_QUOT` and `htmlspecialchars()`, matching what the account-deletion and MoTD buttons already do.
- Emit the CSRF token from `Forms::showForm()` and `showFormTabbed()`, and validate it in one line at each save branch. Seven editors built on `showForm` wrote without a token — `configuration.php` persists **every** posted key as a game setting, including the security switches documented in `SECURITY.md`. Emission is automatic because a caller who has to remember it will not; validation stays explicit, because it has to run where the page decides to write, which is not somewhere `showForm()` reaches. The scope follows the entry script, so a token from the title editor does not open the configuration.
- Give the form token its own field name (`form_csrf_token`). `creatures.php`, `mounts.php` and `companions.php` wrap `module_objpref_edit()` — which reaches `Forms::showForm()` — inside a form that already carries an editor token. Two inputs of the same name in one form is legal HTML and PHP keeps the last, so a shared name would have silently replaced the page's token and made those editors refuse every save. `Csrf::stripFrom()` now removes both fields.
- Guard the remaining `showForm`-backed writes: the three `configuration.php` save branches, `prefs.php`, `titleedit.php`, and the `save`, `savemodule` and `special` branches of the user editor. The `special` form is handwritten rather than built by `showForm`, so it renders `Forms::csrfField()` directly and shares the same check.

### Fixed

- Stop `mounts.php` writing the CSRF token as a mount module preference. Its `subop=module` save iterated every posted key into `set_module_objpref()` and unset only `showFormTabIndex`, while the form above it carries the mount editor's token — so a preference named `csrf_token` was stored on every save. `companions.php` and `creatures.php` already filtered; this was the one place the rule in `AGENTS.md` had been missed.
- Run the megauser check before anything is removed when deleting a user. `pages/user/user_del.php` called `PlayerFunctions::charCleanup()` first and only then refused if the target held superuser powers and the caller was not a megauser — so the refusal left the account stripped of its comments, output cache and clan membership while reporting that nothing had happened. The guard destroyed exactly what it existed to protect, precisely when it fired.
- Report the real row count after deleting a user. The count came from `Database::affectedRows()`, which reflects what `Database::query()` last recorded and is not updated by a Doctrine statement, so the number shown to the admin came from an unrelated earlier query.
- Point the creature editor's module-preferences form at `creatures.php`. It posted to `mounts.php`, which reads `id` rather than `creatureid` and is not in the navigation allowlist for that URL, so the submit ended at `badnav.php`. The navigation entry beside it already registered the correct target.
- Encode the module name in the remaining `mounts.php` navigation entry, which interpolated it raw while the form action two lines above already encoded it.

### Security

- Give the async endpoint a CSRF token. It had none, and it is the one entry point the navigation allowlist does not reach: `async/process.php` defines `OVERRIDE_FORCED_NAV`, which makes both branches of `ForcedNavigation::doForcedNav()` no-ops, so a request was authenticated by the session cookie alone. `async/setup.php` issues the token during page render, `async/js/lotgd.jaxon.js` attaches it as an `X-LotGD-Csrf` header, and `lotgd_async_csrf_state()` checks it. Jaxon's own CSRF support is deliberately unused: its behaviour lives in a runtime loaded from a CDN rather than vendored, and a security control should not depend on an asset that cannot be reviewed here. Ships in log-only mode (`csrf_mode` in `config/async.settings.php`) because that transport cannot be verified in CI and polling runs every few seconds for every Ajax player. This is defence in depth rather than a plugged hole — `SameSite=Lax` already stops a cross-site POST from carrying the cookie, unless an operator sets `SESSION_COOKIE_SAMESITE` to `None`. The unauthenticated passkey pair stays exempt and keeps its own handler-side check.
- Require a POST and an editing token for the MoTD write operations. `op=del` was a plain `<a href>`, so an admin who followed a crafted link deleted the entry — `SameSite=Lax` does send the session cookie on a top-level GET navigation, and the navigation allowlist only narrowed the window rather than closing it. `op=save` and `op=savenew` wrote with no token at all. The editing token uses its own scope, separate from the poll-vote token, which is rendered for every logged-in player who sees a poll and therefore has no business authorising an admin operation. Edit stays a link because it only renders a form.
- Encode the `module` request value before it reaches a URL in `companions.php` and `creatures.php`. It was interpolated raw into a single-quoted `<form action='…'>` attribute, where an apostrophe broke out of the attribute, and into `Nav::add()` URLs, where it widened `$session['allowednavs']` — a blind spot `SECURITY.md` documents. The module lookup still receives the unencoded name; `rawurlencode()` leaves real module names untouched.

### CI

- Move every workflow action onto its Node 24 release: `actions/checkout` v4/v5 → v7, `actions/cache` v5 → v6, `actions/upload-artifact` v4 → v7, `docker/setup-buildx-action` v3 → v4 and `docker/build-push-action` v6 → v7. These are not cosmetic: the runner already reported `actions/checkout@v4`, `docker/build-push-action@v6` and `docker/setup-buildx-action@v3` as targeting the deprecated Node 20 and being forced onto Node 24. The breaking changes do not reach this repository — checkout v7 blocks fork checkouts under `pull_request_target`/`workflow_run`, which no workflow here uses; setup-buildx v4 removes deprecated inputs, and the step passes none; build-push v7 removes `DOCKER_BUILD_NO_SUMMARY` and `DOCKER_BUILD_EXPORT_RETENTION_DAYS`, neither of which is set. `actions/checkout` was also inconsistent, pinned at v4 in CI and v5 in the release workflow.
- Drop `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24` from every job. It papered over the same Node 20 deprecation, and the runner now reports it is "running with Node 24 by default" regardless, so it no longer decides anything once the actions themselves target Node 24.
- Group Dependabot's GitHub Actions updates into a single pull request. Action majors move in lockstep — the whole ecosystem migrated to Node 24 at once — so five separate PRs cost five CI runs to learn one thing. The docker ecosystem stays ungrouped on purpose: a base image bump changes what the application runs on and deserves its own review.

### Security
- Move CSRF tokens into `Lotgd\Security\Csrf`. Seven places had grown their own copy of the same recipe — a lazily generated token in the session, `hash_equals()` on the way in, `htmlspecialchars()` on the way out — and each had drifted: the 2FA module used 16 bytes where the others used 32, `mounts.php` omitted the empty-string check, and the MoTD poll token was generated in `src/Lotgd/Motd.php` but validated in `motd.php`. None of them was wrong; the risk was the eighth copy. The class validates and nothing else — it returns booleans and never logs, sets a status code or outputs — so every caller keeps its existing failure behaviour and the migration changes nothing a player or admin sees. Only the token-issuing methods write to the session; every inspection and validation path leaves it untouched, which makes a mistyped scope fail closed instead of minting a token that matches itself, and keeps a validator running after `session_write_close()` from comparing against a token that is discarded at the end of the request. Tokens stored under the old per-page session keys are still honoured and migrate on the next render, so open forms survive the deploy.
- Drop the second CSRF validator in `src/Lotgd/Async/Handler/TwoFactorAuthPasskey.php`. It branched on whether the module's token helper happened to be loaded, with a raw session read as the fallback, because `async/process.php` does not load module functions. A core class is reachable from both paths.
- Require superuser rights per async callable, not just a valid login. `async/process.php` gave every class except `TwoFactorAuthPasskey` an unconditional pass through its callable allowlist, and the policy behind it only asked whether the caller was logged in. `Bans::affectedUsers()` carries no rights check of its own, so any account could ask which players a ban rule covers; `bans.php` gates itself with `SuAccess::check(SU_EDIT_BANS)`, but the async path never reaches that page. Requirements now live in `lotgd_async_required_superuser_bits()` and are checked after authentication, so an anonymous caller still gets 401 rather than a privilege-shaped 403 that would reveal which callables are privileged. The check compares the bitmask directly rather than calling `SuAccess::check()`, which renders a page and, on failure, zeroes the character's gold and hitpoints — reaching that from a JSON endpoint would have turned an unauthorized call into character destruction.
- Stop exporting `Commentary::test()`, a debug echo with no caller, to the async callable directory. It is excluded from the Jaxon registration and refused again in `async/process.php`, so the refusal does not depend on the registration options staying correct.
- Bind the clan rename parameters in `pages/clan/detail.php`. `clanname` and `clanshort` arrive from the request body and are passed only through `Sanitize::fullSanitize()`, which strips LotGD colour codes and escapes nothing, so both reached an `UPDATE` inside single quotes. The navigation allowlist does not constrain this: it keys on the request URI, and a POST body is not part of it. Reachable with `SU_EDIT_COMMENTS`, a moderation flag rather than full superuser. The blocked-description text in the same file is bound too, since translations are editable and may legitimately contain an apostrophe.
- Cast the integer ids that reached interpolated queries unvalidated: `weapons.php`, `mercenarycamp.php`, `train.php` and `titleedit.php` (where every other use in the file already cast, and only the one query did not). `clan.php` normalizes `detail` at its source, because it flows into a cached query, a cache key and generated URLs.
- Bind `txnid` and the hook-supplied point total in `donators.php`. Neighbouring values were carefully validated with `filter_var()`, which is precisely why the one unvalidated parameter was easy to miss.
- Harden the `motd.php` month filter that was exploited in the past. The values are bound now; the length cut and the pattern are no longer load bearing on their own, and the pattern is anchored so it cannot match a substring.
- Stop trusting forwarded-protocol headers from arbitrary peers. `X-Forwarded-Proto` and its siblings decide whether a request counts as HTTPS, and therefore whether session cookies carry the `Secure` flag and whether HSTS is emitted — but an empty `SECURITY_TRUSTED_PROXIES` list meant *every* source was trusted, so any visitor could assert the header for their own request. `ServerFunctions::isHttpsRequest()` made this reachable by default, since it enables forwarded-header trust unless `LOTGD_TRUST_FORWARDED_HEADERS` says otherwise and passes no allowlist. With no explicit list the headers are now honoured only from loopback and private network ranges — which is exactly where a reverse proxy sits in the Docker deployment — and ignored from public addresses.
- Accept CIDR blocks in `SECURITY_TRUSTED_PROXIES` alongside literal addresses, so a proxy on a container network can be named by its subnet instead of an address that changes whenever the network is recreated. Entries are matched numerically, so equivalent spellings of the same address also match, and an IPv4 peer is never matched against an IPv6 block.
- Deny every path in the document root that is not web content. The legacy layout keeps Composer dependencies, application classes, page fragments, migrations, maintenance scripts, logs, and the image's own build files next to the entry points, and only `lib/`, `modules/`, and `install/` were protected. `docker/apache/lotgd.conf` and the root `.htaccess` now deny `bin/`, `config/`, `docker/`, `docs/`, `logs/`, `migrations/`, `scripts/`, `src/`, `tests/`, and `vendor/` wholesale, PHP files under `pages/` and `async/common/`, dotfiles, `*.bak`, and source/metadata extensions (`.dist`, `.htm`, `.ini`, `.lock`, `.log`, `.neon`, `.sh`, `.sql`, `.twig`, `.yml`, `.md`, `composer.json`, `Dockerfile`, `phpunit.xml`, `phpcs.xml`). JavaScript under `src/` stays reachable because `EDom::includeScript()` and legacy modules load `src/Lotgd/e_dom.js` and `src/Lotgd/md5.js` by those URLs. This closes web access to `logs/bootstrap.log`, whose PHP exception messages and filesystem paths were served verbatim, and to the copy of the readiness probe under `docker/health/`, which answered database-connectivity questions from any client because the `Require local` restriction only covered the aliased `/_health/ready` path. `LICENSE.txt` stays readable because the installer verifies its checksum.
- Deny `cron.php` over HTTP by default in both the Docker virtual host and the root `.htaccess`. Documentation asked every administrator to add this rule by hand, and the instructions pointed at `.htaccess` — which Docker deployments never read, because the virtual host sets `AllowOverride None`. With `register_argc_argv` enabled, a web request can pass the execution bitmask through the query string and trigger a newday or database-cleanup run without authenticating.
- Stop handing the MySQL administrative password to the web container. The service imported the whole `.env` through `env_file` and also declared `MYSQL_ROOT_PASSWORD` explicitly, so the credential was readable through `getenv()` from any PHP code path, although the game only ever authenticates with the unprivileged `MYSQL_USER` account. Every value the application needs is now listed individually in `docker-compose.yml`, the entrypoint treats the root password as optional (and still rejects published example values when a hand-rolled deployment supplies it), and both the smoke test and the Compose model test assert its absence.
- Add server-wide Apache hardening (`docker/apache/hardening.conf`): `ServerTokens Prod` and `ServerSignature Off` replace the base image's default, which advertised the exact Apache build and distribution in every response, and `TraceEnable Off` disables the TRACE method. Static files and error documents, which never pass through the application's runtime hardening bootstrap, now also carry `X-Content-Type-Options: nosniff`.
- Make the `.htaccess` installer guard work when the game is installed in a subdirectory. It required `REQUEST_URI` to start with `/install/`, which is only true at the document root; the rewrite rule alone is relative and correct in both layouts.
- Resolve the async authorization target from Jaxon's canonical `jxncall` descriptor instead of separate legacy class/method fields. A Jaxon 5 client never sends those legacy fields, so `async/process.php` evaluated every production request against an empty callable context: the passkey method restriction (`callable_not_allowed`) never engaged, and the unauthenticated allowlist could not match either. Whenever a `jxncall` field is present it now decides the callable on its own; if it cannot be used (malformed JSON, a non-class descriptor, missing name/method) the request is treated as an unknown callable rather than falling back to the legacy fields. Those fields are still read when a payload carries no descriptor at all — a shape Jaxon does not dispatch — where they only feed diagnostics. A request can therefore no longer describe one callable to the policy layer and a different one to Jaxon.

### Changed
- Add `SqlValueInterpolationCheck`, replacing the dormant `InterpolatedDatabaseQueryCheck`. The old rule required `Database::query()` to receive a string literal; 193 of 194 call sites pass a previously assembled `$sql` variable, so it could never be enabled and in fact never ran anywhere — no runner script, not in `composer static`, and its tests only exercised synthetic fixtures. The new rule follows the value rather than the shape of the call: interpolation into a value position is reported, identifier interpolation is not, an `(int)` cast at the query or at the assignment is accepted, and a `(string)` cast is not. In CI it examines only the lines a change adds, which takes about a tenth of a second and leaves the 122 historical findings alone; `composer qa:sql-interpolation` audits the full tree on demand.
- Check the freshness of the pinned base images on a schedule. `tests/Docker/check-image-pins.sh` reads the pins out of `Dockerfile` and `docker-compose.yml`, asks Docker Hub when each tag was last rebuilt, and fails when a tag has been quiet for more than 120 days. Digest drift is only reported, because Dependabot already proposes those; staleness is the case no bot can report, since an abandoned line stops moving and the digest a bot would propose is the one already pinned. Against the runtime used until this month the check prints `pin is current` **and** `STALE: not rebuilt for 455 days` — the whole problem in two lines. The `Image pin freshness` workflow runs it weekly, on demand, and on pull requests that touch the pins.
- Ship the optional resource and logging limits as `docker-compose.limits.yml` instead of a snippet in the documentation, so they can be layered onto the base stack and are checked by `docker compose config` rather than retyped from prose.
- Rename `Sanitize::fullSanitize()` to `Sanitize::stripAllColorCodes()`. The old name promised general purpose sanitization; the method removes colour markup and nothing else, and a value that had passed through it reached a query. `fullSanitize()` stays as a deprecated alias so modules keep working, and the legacy global `full_sanitize()` in `lib/sanitize.php` keeps its name and delegates to the new method.
- Move the container runtime from `thecodingmachine/php:8.3-v4-apache` to `8.4-v5-apache`. The `v4` line stopped being rebuilt on 2025-06-09 and had accumulated over a year of unpatched PHP and distribution updates; `v5` is rebuilt monthly. This was not a digest swap: `v5` is built on Ubuntu with the ondrej packages rather than the official Debian PHP images, so PHP's scan directory moved to `/etc/php/${PHP_VERSION}/apache2/conf.d`, `TEMPLATE_PHP_INI` had to be pinned to `production` (it defaults to `development`), `APACHE_EXTENSION_HEADERS=1` is now required because the runtime re-runs `a2dismod` from its own default list on every start and would silently drop `mod_headers` — and with it the `no-store` and `nosniff` headers — and `DOCKER_USER` is set so the entrypoint stops probing the read-only document root with a scratch directory. The production ini is installed through a path resolved from the image's own `PHP_VERSION`, so a future layout change fails the build instead of silently discarding the settings. `docs/Docker.md` records each of these assumptions.
- Refresh the `composer:2` build-stage digest to the current image (Composer 2.10.x). The build stage only resolves `vendor/`, so nothing from it reaches the runtime; all three pinned images are current again.
- Serve PHP 8.4 in the container while keeping the application's supported floor at 8.3. CI now runs the unit tests and static analysis on both versions, so non-Docker installations on 8.3 stay covered.
- Use `curl` rather than `php` for both health checks. On this runtime `/usr/bin/php` is a wrapper script that sudo-chowns a cache file and can regenerate the PHP configuration before running anything, which is unwanted work every 15 seconds and would report a wrapper failure as an unhealthy application.
- Mount the development PHP settings at `/etc/lotgd/php-development.ini` and let the entrypoint link them into PHP's scan directory, so `docker-compose.dev.yml` no longer has to name the runtime's PHP version.
- Extend the Docker image's `HEALTHCHECK` into the image itself instead of only the Compose file, so a plain `docker run` reports readiness rather than mere liveness, and exclude documentation and QA tooling from the build context.
- Enable Dependabot for the `github-actions` ecosystem, which had no update path at all.
- Rename the async setting `mail_debug` to `debug_console` and give it the behaviour its name implies. The old flag overrode `check_mail_timeout_seconds` with 500 — a poll interval in *seconds* — so switching it on throttled polling from every 10 seconds to roughly every eight minutes, which is hard to tell apart from a broken async layer. `debug_console` only enables verbose browser-console logging (prefixed `[LotGD async]`) and leaves every interval untouched; informational client logging is now gated behind it, while error output stays unconditional. The legacy key is still honoured when a configuration file has not been migrated, but no longer changes timing.

### Performance
- Release the PHP session lock before dispatching read-only async callables (commentary/mail/timeout polling and ban lookups). The polling loop previously held the session file lock for the entire request, serialising every concurrent page load of the same player behind it. Callables that must persist session state, including the passkey ceremonies and any module-supplied handler, keep the lock and are unaffected.

### Removed
- Delete the unused `async/js/ajax_polling.js`. The polling client has been emitted inline by `async/setup.php` for some time and the file was no longer loaded by anything; keeping it around risked a second, duplicate polling loop in custom templates that still referenced it.

### Tests
- Cover the new SQL guard with fixture cases for both directions: quoted, bare, braced, `LIMIT` and `IN` value interpolation and a `(string)` cast are rejected; bound parameters, identifier interpolation after `FROM`/`UPDATE`/`INSERT INTO`, an inline or assignment-level `(int)` cast, an integer literal, and HTML that merely contains the word "select" are accepted. A variable that is cast in one assignment and written freely in another is not trusted.
- Pin the sanitizer contract: `fullSanitize()` must keep delegating to `stripAllColorCodes()` rather than drifting into a second implementation, and a test asserts that the method does **not** escape quotes — the misunderstanding that gave it its old name.
- Cover the forwarded-proxy trust rules: private and public peers with and without an allowlist, literal and CIDR entries, IPv4/IPv6 separation, and malformed entries. The existing forwarded-header test fixtures now carry a peer address, because a forwarded protocol without one is no longer believed.
- Assert in the Docker smoke test that `mod_headers` survived the runtime's module handling (`no-store` on a game page, `nosniff` on a static file), that `ServerTokens Prod` is in effect, and that the production ini really landed in the Apache scan directory — each of which the v5 migration could have broken without failing anything.
- Assert the HTTP access boundary in `tests/Docker/smoke.sh`: every include-only tree, CLI entry point, dependency directory, and metadata file must answer `403` against a running container, while ordinary entry points and template assets must not, and the web container must not carry `MYSQL_ROOT_PASSWORD`. The Compose model test additionally rejects an `env_file` on the web service.
- Load async settings test fixtures from temporary files via `LOTGD_ASYNC_SETTINGS_FILE`, clean them up completely, preserve the developer's real `config/async.settings.php`, and fail cleanly when a fixture cannot be created.

### Docs
- Document the navigation allowlist (`allownav`) in `SECURITY.md` as the security control it is: how `Nav::add()` seeds `$session['allowednavs']` and `ForcedNavigation` checks the full request URI against it, and — more importantly — what it does not cover. Request bodies are not keyed, `OVERRIDE_FORCED_NAV`/`ALLOW_ANONYMOUS` pages opt out, and a request-derived value interpolated into a nav URL widens the allowlist itself. The mechanism reduces the reachability of a mistake; it does not make the mistake harmless.
- Document the runtime migration for operators in `UPGRADING.md`: what a rebuild changes, what to check in custom modules, and the ini path that moved. Record the forwarded-proxy behaviour change there too, including the one case that needs action (a TLS terminator reaching the application from a public address).
- Record the state of the pinned base images in `docs/Docker.md`, including a procedure for checking a pin without pulling it. `mysql:8.4` is current and `composer:2` is a routine digest refresh, but `thecodingmachine/php:8.3-v4-apache` sits on an upstream line that has not been rebuilt since 2025-06-09: monthly Dependabot runs cannot detect this, because the digest it would propose is the digest already pinned. A step-by-step migration to the maintained `8.4-v5-apache` line is documented.
- Add operational sections that the Docker guide was missing: backup and restore commands for the database and the state volume, an update procedure, a minimal Caddy reverse-proxy configuration, and the `dbconnect.php` keys that make the application aware of that proxy (including the fact that `SECURITY_TRUSTED_PROXIES` matches literal addresses and is skipped entirely when left empty). Optional resource, PID, and log-rotation limits are offered as an override file rather than imposed as defaults.
- Document the HTTP access boundary and which container receives which secret, and correct several stale instructions: `.env.example` ships with empty rather than sample passwords, SMTP credentials live in the in-game settings editor and not in `config/configuration.php`, the Raspberry Pi guide cloned a repository URL that does not exist, and the README's minimum database version predated the supported MySQL/MariaDB releases. The README's table of contents also listed four sections that no longer exist while omitting eight that do.

## [2.0.5] – 2026-04-10

### Features
- Add centralized runtime hardening bootstrap so deployments get safer defaults for HTTPS detection, proxy signaling, and request-surface protections out of the box.
- Improve installer security defaults and enable stronger admin guidance via the recommendations module during first-run setup.
- Extend the charrestore module with a user-prefs-only restore option so admins can overwrite preference data without replacing full character records.

### Security
- Harden HTTPS/proxy detection by validating forwarded-proto handling paths, including `HTTP_FORWARDED_PROTO` and related trusted-header guardrails.
- Strengthen payment/IPN duplicate handling with canonical paylog resolution, idempotency checks, and safer failure behavior when canonical rows cannot be resolved.
- Continue the superuser/core SQL hardening wave by parameterizing high-risk paths across create/referers/creatures/masters/modules/paylog/payment code paths.
- Enforce and tighten the SQL `addslashes` QA baseline to block regressions back to unsafe query construction patterns.

### Bug Fixes
- Fix payment/IPN edge cases around duplicate callbacks, canonical paylog selection, and retry consistency so credits are applied exactly once.
- Resolve follow-up regressions in proxy-aware HTTPS detection and runtime hardening bootstrap behavior across mixed hosting/proxy setups.
- Correct module/object preference cache invalidation and related typing issues discovered during the SQL hardening migration wave.
- Keep navigation access-key generation stable during holiday mode so keybindings no longer depend on seasonal rendering changes.
- Fix user-account config save/load handling when optional settings are empty, preventing dropped values and inconsistent persistence.
- Cast moderated commentary timestamps to integers to avoid type-related moderation issues on timestamp handling.

### Refactor
- Migrate additional legacy SQL reads/writes to Doctrine DBAL with explicit typed bindings across superuser and core maintenance/admin flows.
- Normalize query structure in multiple admin endpoints to reduce ad-hoc SQL handling and improve long-term maintainability.

### Dependencies/Tooling
- Transition CI and release workflows to Node.js 24 in GitHub Actions.
- Raise static analysis memory defaults and tighten QA tooling heuristics to keep large security migration waves reliable in CI.
- Add a manual release `workflow_dispatch` path, increase release artifact retention, and tighten CI cache-key strategy for more reliable pipeline runs.

### Docs
- Clarify canonical payment idempotency policy and duplicate-IPN test scope for operators and contributors.
- Add/expand security review and hardening checklist guidance for repository contributors.

### Tests
- Add and extend regression coverage for runtime hardening, forwarded-proto handling, payment/IPN idempotency, and superuser endpoint SQL hardening waves.
- Strengthen SQL QA baseline tests to ensure `addslashes` enforcement remains stable as additional legacy paths are migrated.

## [2.0.4] – 2026-03-10

### Features
- Add optional two-factor authentication (TOTP) with QR/manual enrollment and a staged challenge flow during login.
- Add reCAPTCHA v3 integration for pre-login verification hardening.

### Security
- Migrate password handling to bcrypt with `password_algo` tracking and align installer/login password flows with the new helper behavior.
- Harden login and failed-attempt persistence paths against SQL injection risks and schema drift edge cases.
- Restrict public error rendering and expand security coverage around error handling and authentication flows.

### Bug Fixes
- Stabilize 2FA challenge navigation/redirect handling across repeated logins, subdirectory installs, and failed token submissions.
- Keep 2FA challenge state active across retries and normalize stored allowed-navigation data for resume flows.
- Guard invalid time preference values to prevent date argument type errors in preference handling.

## [2.0.3] – 2026-01-28

### Dependencies
- Upgrade Doctrine ORM to 3.x and Doctrine DBAL to 4.x, aligning the core stack with current Doctrine releases.

### Bug Fixes
- Adjust Doctrine bootstrap configuration to use the ORM 3 attribute driver and field declaration reporting so metadata validation matches ORM 3 expectations.
- Prefix Doctrine join tables when table prefixes are enabled, preventing mismatched table names during metadata loading.
- Normalize DBAL 4 result handling and connection setup so affected-row counts and write operations behave consistently across the legacy wrappers.
- Align mail persistence queries with the DBAL 4 execution flow to keep mailbox updates stable during upgrades.

### Docs
- Document ORM 3 attribute metadata and DBAL 4 migration guidance in the upgrade notes.

## [2.0.2] – 2025-11-27

### Bug Fixes
- Guard mount buff application on new day so characters without mounts do not trigger buff application errors.
- Initialize the output instance in the mercenary camp heal navigation to avoid rendering notices during heal flows.

## [2.0.0] – 2025-11-26
### Features
- Add asynchronous ban lookups so moderators can review affected accounts inline without leaving the list views.
- Refresh the mail popup navigation with button-style quick links and theme styling hooks for modern layouts.
- Enable zlib compression by default.
- Remove legacy settings stub and streamline cron handling.
- Expanded logging: game log entries can include account IDs, user management and module lifecycle actions, and anonymous entries show a system label.
- Account cleanup now runs inside a database transaction for safer deletions.
- Mail delivery helpers expose PHPMailer error details so admin tools can surface actionable diagnostics.
- Add "Test SMTP settings" action to `configuration.php` to send a diagnostics email.
- Introduced severity metadata and filtering support for game log entries, including database migrations and automated coverage.
- Added the Aurora Minimal Twig theme with responsive light and dark styling options.
- Restyled the installer confirmation stage to better communicate upgrade paths and requirements.
- Added example modules showcasing a forest reward encounter and a village gem shop integration.


### Refactor
- Centralize admin player lookup logic on the PlayerSearch service, extending reuse across bank transfers, mail compose flows, and donor tools.
- Migrate module installation routines to Doctrine parameter binding for activation, uninstall, and reinstall paths while keeping cache invalidation intact.
- Standardize top-level scripts to use `__DIR__` in `require` statements for safer path resolution.
- Remove redundant battle buff wrappers.
- Localized mount editor dependencies and continued migrating legacy entry points toward namespaced services.


### Docs
- Clarify the repository's expectations around adding new files to `lib/` in the contributor guidelines.
- Clarify newday cron configuration and cron job setup instructions.
- Add module hook reference documentation.
- Document contributor guidelines and static analysis in maintenance docs.
- Clarified cron job configuration details in the README and admin guide.
- Highlighted DragonPrime community resources and the successor project in the README.
- Documented Docker usage for the PHP 8.3 Apache image.

### Bug Fixes
- Parameterize ban creation, search, and removal flows so moderation tools log out affected players safely and avoid injection vectors.
- Bind parameters throughout petition submissions, news inserts, and debug logging helpers to harden persistence routines against crafted input.
- Rely on Doctrine-powered mail workflows for composing, sending, and listing messages so subject/body data stays sanitized and mailbox state remains accurate.
- Parameterize system mail lookups and inserts so notification deliveries stay sanitized when addressing account IDs.
- Guard admin search helpers by routing list, mail, and donation lookups through PlayerSearch, ensuring consistent escaping and locked-account handling.
- Stabilize asynchronous polling by parameterizing commentary refreshes and surfacing a timeout banner when sessions expire during background checks.
- Guard against missing city and theme parameters and ensure `diddamage` defaults to zero.
- Use safe array access for player name lookup and tighten module migration checks.
- Preserve the `Settings` singleton when loading extended settings or templates.
- Roll back character cleanup when skipped and log deletions only after commit.
- Add unsuspend buff wrappers and seed default navigation for new characters.
- Normalize withdraw log category to lowercase.
- Show system label for anonymous gamelog entries and record account IDs in maintenance logs.
- Show "Deleted User" placeholder when reading mail from deleted accounts instead of erroring.
- Extend the template preference cookie to one year to prevent theme resets.
- Cast equipment editor and hidden field values to strings to avoid PHP type errors on listings.
- Default the game log listing to newest-first ordering and preserve chosen sort parameters between requests.
- Hardened cron bootstrap and error handler wiring to initialize notifications safely before legacy includes.
- Resolved numerous installer upgrade edge cases, including table prefix syncing, stage gating, and migration auto-detection.
- Tightened validation across preference previews, mount editors, clan removal, referral handling, and mail replies to eliminate PHP warnings and bad input.
- Corrected malformed timestamps when reviewing pending email changes.

## [2.0.0-rc12] – 2025-09-06

### Dev / Tooling Enhancements
- Integrated **PHPStan** static analysis into the development workflow. Pulling in `phpstan/phpstan`, including configuration for Doctrine and PHPUnit extensions to ensure cleaner, more maintainable code.
- Added **Psalm** alongside PHPStan for additional static type coverage and complementary error detection.
- Enhanced CI pipeline with linting, PHPStan, Psalm, and PHPUnit; enabling pre-commit and GitHub Actions support for rapid feedback and code quality enforcement.
- Configured IDE integration (e.g., PhpStorm) to run Psalm/PHPStan on the fly, enabling real-time editor warnings and fixes. :contentReference[oaicite:0]{index=0}

### Bug Fixes & Minor Improvements
- Addressed minor issues discovered via static analyzers — cleaned up undefined variable notices, type-stability warnings, and optimized function signatures.
- Fixed legacy annotation compatibility in comments (`@psalm-` and `@phpstan-`) to avoid tool conflicts. :contentReference[oaicite:1]{index=1}

### Summary
These enhancements significantly improve code quality, developer trust, and long-term maintainability—while preserving the legacy engine compatibility.


---

## [2.0.0-rc11] – 2025-08-29
**Final release candidate for 2.0.0**

### Features
- Improved Twig template engine with caching.
- Finalized async/Ajax structure with Jaxon.
- Better admin notifications for async and LoGDnet errors.

### Bugfixes
- Final installer polish.
- Commentary and mail system stability.
- Minor LoGDnet fixes.

---

## [2.0.0-rc10] – 2025-08-25
### Features
- Admin notifications for LoGDnet/async errors (requires cache).
- Favicon handling in installer.

### Bugfixes
- DB prefix handling centralized and fixed in migrations/installer.
- Safe rollback tests in installer.
- Null/credential handling improvements in install stages.
- Logging for migration errors.

---

## [2.0.0-rc9] – 2025-08-22
### Features
- Async mail auto-refresh + incremental commentary without reload.
- Configurable async rate limiting (defaults ~1 request/sec).
- Translator placeholder hardening (`sprintf` style checks).
- LoGDnet listing guards and error handling.

### Bugfixes
- Async/Jaxon bootstrap sequence corrected.
- Commentary sanitization and pagination fixes.
- PvP mail translation now supported.
- Recipient selection and reply flow fixes in mail.
- Numerous installer polish issues resolved.

---

## [2.0.0-rc8] – 2025-08-05
### Features
- **Doctrine ORM + Migrations** integration.
- **Doctrine DBAL** used across core.
- Added `migrations/` tree with upgrade paths.
- PHP **8.3 baseline** documented.

### Refactor
- Broad PSR-4 namespacing (`Lotgd\...`).
- Composer autoload integration for game + modules.
- Strict typing introduced in many subsystems.
- Expanded `src/Lotgd/Config` structure.

---

## [2.0.0-rc6 → rc7] – July 2025
### Features
- Twig templating becomes default (classic HTML templates still supported).
- MOTD preview and UI options.
- Canonical link support.

### Bugfixes
- Commentary quote rendering tests.
- Forest fight XP calculation with floats.
- DK reset defaults corrected.
- Debug log sanitization.

---

## [1.3.2] – 2025-06-02
### Summary
- **Final 1.x line release.**
- Numerous small bugfixes across installer, gameplay, and UI.
- PHP 8 compatibility patches.
- Early Composer wiring for PHPMailer.
- Security patch for `motd.php` injection.

---

# Categories Overview

### Features
- Twig template engine with skin folders and caching.
- Async UX improvements (mail refresh, commentary updates, Jaxon).
- LoGDnet enhancements with safer listing and error logging.
- Mailing via PHPMailer (Composer-managed).
- Translation overhaul with positional placeholders and checks.
- SEO canonical links.

### Refactor / Architecture
- Composer-first, PSR-4 namespaces (`Lotgd\...`).
- Doctrine DBAL + ORM + migrations.
- PHP 8.3 baseline.
- New config structure (`src/Lotgd/Config`).
- Async code moved to dedicated directories.

### Bugfixes
- Installer hardened (stages, DB prefixes, rollback, favicon).
- Mail recipient/reply bugs resolved.
- Commentary sanitization and pagination.
- Forest XP/DK/biography/defeat translations fixed.
- Debug log and counter increments corrected.

### Security
- Safe unserialize for sessions.
- Auth guards on Ajax endpoints.
- LogdNet listing hardened.
- Ajax requests rate-limited (HTTP 429 on abuse).
- PHPMailer kept current via Composer.

### Developer Experience
- Extensive PHPUnit coverage for installer, async, translator, Doctrine.
- Migration logging and DB prefix tests.
- Improved error messages in upgrade/migrate paths.

### Breaking Changes
- Twig is default; template hooks updated.
- Namespacing and strict typing throughout.
- Doctrine migrations required; legacy upgrade first.
- Async endpoints rewritten for Jaxon.
- PHP 8.3 minimum version.

---

*👉 For step-by-step instructions, see [UPGRADING.md](UPGRADING.md).*
