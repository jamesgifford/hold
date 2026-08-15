# Changelog

All notable changes to this package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.1] - 2026-08-15

### Fixed

- **Reskinning `--hold-bg` no longer recolors the email input.** Both holding
  pages reused `--hold-bg` for the input field's fill as well as the page
  background, so changing one changed both. The field now has its own
  `--hold-input-bg` variable (default unchanged — same color as before).

## [1.2.0] - 2026-08-14

### Added

- **`Retry-After` on maintenance mode.** `maintenance.retry_after` (default
  `3600`s) is passed through to `down --retry` when maintenance is enabled via
  `jamesgifford:hold:enable maintenance`, so the 503 response carries a
  `Retry-After` header — protecting search-index standing during extended
  outages. Override per-run with `--retry=<seconds>`; `0`/`null` omits it.
  Only applies when maintenance is enabled through Hold.
- **Meta robots `noindex, nofollow`** on the maintenance page (secondary
  protection behind the 503 status). The prelaunch page is unaffected — it's
  meant to be indexable.
- **Three new maintenance-page `$copy` strings**: `eta`, `apology` (ships
  with default wording), and `contact` — each renders, with spacing, only
  when non-empty, mirroring the email templates' logo-header collapse
  pattern. `contact` is rendered unescaped so it can carry a `mailto:` link.
- **Prelaunch sharing metadata**: `<title>`/`<meta name="description">` and
  Open Graph/Twitter Card tags, driven by the existing `$copy['title']`/
  `['lede']`, plus a new `$ogImage` variable for the share-preview image.
  Not added to the maintenance page.
- **Reading-width and spacing-scale CSS variables** (`--hold-content-width`,
  `--hold-space`) replace the previous fixed `30rem` card width and the
  scattered per-element margins on both holding pages.

> **Existing installs:** page templates published before this release need to
> either re-publish (`vendor:publish --tag=jamesgifford-hold-views --force`,
> which overwrites local edits) or hand-add the new `$copy` keys, `$ogImage`,
> and CSS variables — there is no automatic merge.

### Fixed

- **`uninstall` now removes `config/jamesgifford/` when it's the last thing
  in it.** Previously only `hold.php` was deleted, leaving the (usually
  empty) parent directory behind. If another `jamesgifford/*` package has
  left files there, the directory — and those files — are left untouched.

### Changed

- **Email templates now share a single spacing unit** (`$space`), the same
  approach the holding pages use, replacing the hardcoded per-element margins
  that had drifted independently across `mail/announcement.blade.php`,
  `mail/team.blade.php`, and `mail/receipt.blade.php`. Purely internal —
  every rendered margin is unchanged (24/20/16/8/4px).
- The holding pages' and emails' top-of-file color blocks are now both
  labeled `── Palette ──`, reconciling the pages' former "Reskin knobs"
  wording with the emails' existing convention.

### Documentation

- `config/hold.php`'s `mail.from.address` / `mail.from.name` now have their
  own inline comments, matching every other key in the file.
- README documents two previously-unstated limitations: prelaunch's flag
  file is local-disk state, so it does not propagate across app servers
  without shared storage; and maintenance mode's own storage follows
  whatever `APP_MAINTENANCE_DRIVER` the host app is configured with — Hold
  doesn't set or override that choice.
- Brief copy-writing guidance added to the `$copy` blocks: the prelaunch
  page's `title`/`lede` double as sharing metadata, so they should be a real
  product name and one specific promise, not marketing phrasing; the
  maintenance page's `eta`/`contact` should stay concrete and actually
  staffed, not vague or generic.

### Testing & tooling

*No runtime effect.*

- Added an end-to-end test proving the sync-queue auto-announce path
  (`announce_delay_minutes = 0` on `QUEUE_CONNECTION=sync`) genuinely sends,
  running the real job through Laravel's sync queue rather than asserting
  against a faked dispatch.
- Removed the unused `mockery/mockery` dev dependency (still installed
  transitively via `orchestra/testbench`, just no longer declared directly).

## [1.1.0] - 2026-08-06

> ### Upgrading from 1.0.0 — read this first
>
> This release contains **four breaking changes**. All four are compile-time or
> boot-time failures with clear messages, not silent behaviour changes.
>
> 1. **`models.signup` must implement `JamesGifford\Hold\Contracts\HoldSignupContract`.**
>    Re-publish the model (`php artisan jamesgifford:hold:setup`, accept the
>    overwrite) or add `implements HoldSignupContract` and its `use` statement to
>    your copy. A class that exists but does not implement it now throws a
>    `RuntimeException` naming the fix, rather than being silently replaced by the
>    package's own model.
> 2. **`AnnouncementScheduler::scheduleIfAuto()` returns `ScheduleOutcome`, not `bool`.**
>    Update any `if (...)` call site to match on the enum.
> 3. **`HoldSignupCaptured::$signup` is typed `HoldSignupContract`, not `Model`.**
>    Listeners type-hinting `Model` on that property need updating.
> 4. **`PrelaunchMode::__construct()` takes a third argument** (`Illuminate\Contracts\View\Factory`).
>    Only affects code constructing the middleware directly; container resolution
>    is unaffected.
>
> Also review `notifications.announce_delay_minutes` if you use
> `auto_announce_on_up`: on a `sync` queue, auto-announce now refuses to dispatch
> rather than sending immediately. See "Changed" below.

### Security

- **Open redirect on the public signup endpoint.** The `?hold=` bounce-back took
  its target from the `Referer` header and treated a same-host check as
  sufficient. `parse_url()` reports a leading `//host` as the *path*, so a
  crafted same-host referer produced a protocol-relative `Location` that left the
  origin — reachable in practice because a prelaunch hold renders the signup form
  at every path. The target is now allow-listed to a single-slash-rooted path;
  `//host` and `/\host` both collapse to `/`.

### Added

- `JamesGifford\Hold\Contracts\HoldSignupContract` — the shape `models.signup`
  must satisfy. The published model implements it (setup writes it in), which is
  what lets the package resolve an app-owned class it has no static relationship
  with.
- `JamesGifford\Hold\Announcements\ScheduleOutcome` — enum reporting what an
  auto-announce attempt actually did (`Scheduled`, `AutoAnnounceDisabled`,
  `QueueCannotDelay`).
- `AnnouncementScheduler::autoAnnounceEnabled()`, `::autoAnnounceIsUnusable()`
  and `::queueCanDelay()`, so commands can report the queue situation without
  re-deriving the scheduling decision.

### Fixed

- **A failing recipient aborted the entire announcement run.** The per-recipient
  `catch` block in `Announcer::send()` referenced `$context`, which the closure
  never captured, so the error path itself raised — one bad send took down the
  run and left `notified_at` unstamped for everyone.
- **`requested_at` is now `DATETIME`, not `TIMESTAMP`.** On a MySQL/MariaDB
  server with `explicit_defaults_for_timestamp=0`, the table's first non-nullable
  `TIMESTAMP` column is silently given `ON UPDATE CURRENT_TIMESTAMP`, which
  rewrote `requested_at` every time `notified_at` was stamped.
- **Auto-announce no longer fires without its change-of-mind window.**
  `SyncQueue::later()` discards delays, so on a `sync` connection the delayed
  announcement sent immediately while the command claimed it was scheduled. With
  a delay configured and a queue that cannot defer, nothing is dispatched and the
  operator is told to run `jamesgifford:hold:announce`. A zero delay still
  dispatches.
- **`uninstall` no longer drops the table when it cannot ask.** Run with `-n` /
  `--no-interaction` and without `--force` it removed the published assets *and*
  dropped `hold_signups`. Dropping is now an explicit `--force` opt-in; the assets
  still go. `uninstall` also now asks the production-specific confirmation that
  `setup` already asked.
- **Notification classes degrade to the package defaults.** `mergeConfigFrom()`
  merges only the top-level key, so a config published before a key existed left
  `notifications.classes` undefined — which resolved to `''` and fatalled on
  `new ''`. The fallback reads the shipped config file, so it cannot drift from
  the documented defaults. An unknown key now throws `InvalidArgumentException`.
- **`setup` renames the published model class again.** The rename matched
  `extends Model` anchored to end-of-line, so the contract's `implements` clause
  would have published a correctly-named *file* containing the wrong class name
  whenever `models.signup` used a non-default basename.

### Changed

- Signup model access is centralised on `Hold::signups()`. The controller,
  `UnsubscribeCommand` and the factory previously resolved the class themselves;
  scattered resolution is how a config override half-wires itself.
- `PrelaunchMode` renders through the injected view factory instead of the
  `view()` helper.
- `uninstall --force` now documents that it also drops the table.

### Documentation

- README and the Boost skill list every command and flag, including
  `jamesgifford:hold:unsubscribe`, and both cover the sync-queue refusal, the
  `-n` uninstall semantics and the model contract.
- `config/hold.php` referenced a `class` key that never existed; it is `signup`.
- The CHANGELOG's 1.0.0 entry claimed `enable`/`disable` were "production-guarded,
  idempotent". Neither is production-guarded, and `enable` refuses rather than
  no-ops. Corrected in place.

### Testing & tooling

*No runtime effect.*

- The suite runs against **MariaDB** by default; `composer test:sqlite` keeps a
  fast offline loop. Driver-specific assertions skip themselves. CI covers both,
  plus a `--prefer-lowest` job.
- PHPStan (Larastan) at **level 6 with no baseline**, wired into CI.
- `composer check` runs every gate.
- 11 drift guards that derive their expectations from source with empty
  allowlists, covering command/flag documentation, config-key parity in both
  directions, notification-key resolution, renamed identifiers and stub style.
- `pest --parallel` is isolated per worker (own schema and own storage tree).
  Correct, though not meaningfully faster at this suite size.
- Dropped `minimum-stability: dev`, which was resolving dependencies onto dev
  branches instead of stable security patches.
- Connection defaults moved into `phpunit.xml`'s `<php>` block so CI overrides
  them from the environment without editing the file. `DB_SOCKET` is declared
  empty on purpose, so an inherited socket path cannot override host/port.
- Test isolation is transaction-per-test (`RefreshDatabase`), verified sound on
  MariaDB across random orderings and parallel workers. The base `TestCase`
  also releases its database connections after `parent::tearDown()`, and
  `composer test:parallel` prunes the per-worker schemas it creates.
- `composer.json` gained `homepage` and `support` (issues + source) so the
  package points somewhere from Packagist and `composer show`.
- Added `SECURITY.md` (private vulnerability reporting) and `.editorconfig`;
  both are `export-ignore`d from the dist tarball.
- GitHub Actions bumped to `actions/checkout@v7` and `actions/cache@v6`;
  Dependabot now uses `versioning-strategy: increase` so a major bump replaces
  the constraint rather than widening it.

## [1.0.0] - 2026-07-14

First stable release: reusable "coming soon" (prelaunch) and enhanced
maintenance-mode holding pages for Laravel, with email-capture signup and
launch/restore announcement notifications.

### Added

#### Prelaunch ("coming soon") mode
- Package-owned mode toggled by a flag file under `storage/jamesgifford/hold/`, independent of Laravel's maintenance mode and of config caching.
- `PrelaunchMode` global middleware that renders the holding page for every request while active — a near-zero-cost `is_file()` no-op otherwise — with a configurable HTTP status (`200` to stay indexable, or `503`).
- Signed `/preview` route that sets a bypass cookie so the team can view the real app behind the page. The cookie is bound to a per-activation token, so disabling a hold revokes every outstanding preview link and cookie.

#### Maintenance-mode integration
- A container-bound subclass of `PreventRequestsDuringMaintenance` that merges the package's route URIs (from the configured prefix) into the maintenance `except` list, so the signup/preview routes stay reachable during `php artisan down`. It is only a container binding — no host-app core file is modified, and `composer remove` reverses it.
- A published `resources/views/vendor/hold/maintenance.blade.php` capture page, with `resources/views/errors/503.blade.php` published as a two-line shim that `@include`s it.

#### Unified enable/disable
- `jamesgifford:hold:enable {prelaunch|maintenance}` and `jamesgifford:hold:disable`. **Only one hold may be active at a time**: `enable` refuses (naming the active mode) while any hold is up. Enabling `maintenance` invokes native `php artisan down` with a generated bypass secret and prints the secret link; `disable` runs `php artisan up` and/or clears the prelaunch flag, handling the both-active case by preferring the maintenance announcement context. `disable` is idempotent (it reports cleanly when no hold is active); `enable` deliberately refuses rather than no-ops. Both print an `Active hold: …` status line. Neither is production-guarded — putting a live site into a hold is their job; `setup` and `uninstall` are the guarded commands.
- Native-down self-heal: running `php artisan down` directly (e.g. from deploy tooling) while prelaunch is active auto-disables prelaunch, so the one-hold invariant holds.

#### Signup capture
- `POST /{prefix}/signup` — honeypot + per-IP rate limiting, server-side context detection (prelaunch vs maintenance), silent success on duplicate/honeypot/throttle, CSRF-exempt with query-param feedback so it works where the holding pages render (before the session starts).
- One row per email, lifecycle-aware: a non-nullable `requested_at` records when the address requested the current hold; a same-cycle duplicate writes nothing (byte-identical row); a re-signup during a later hold **re-arms** the row (clears `notified_at`, resets `requested_at`/context, refreshes ip/ua) and never touches `unsubscribed_at`. Exactly one notification per requested hold.
- Published, app-owned `App\Models\HoldSignup` model (published to `app/Models/HoldSignup.php`) with `notNotified` / `subscribed` / `context` scopes and `unsubscribe()` / `resubscribe()`.
- Published, timestamped `create_hold_signups_table` migration.

#### Notifications and announcements
- `TeamHoldEnabled`, `LaunchAnnouncement`, `ServiceRestored`, and an optional `HoldSignupReceipt` notification, all sent via on-demand mail routes (no `User` model) and each overridable via `notifications.classes`.
- Self-contained, publishable HTML email templates (`mail/announcement`, `mail/team`, `mail/receipt`) with **no dependency on Laravel's markdown mail layout** — each carrying a top-of-file editable palette, an optional logo-image / text-wordmark header, and a `$copy` block holding every string. The two announcement subject lines are configurable (`notifications.subject_launch` / `notifications.subject_restored`).
- `SendAnnouncement` job with a change-of-mind guard (aborts silently if the hold is active again when a delayed run fires), chunked sending, and `notified_at` stamping.
- `jamesgifford:hold:announce` command: immediate, idempotent, context inference, `--dry-run`. Optional auto-announce after a hold ends, delayed by `announce_delay_minutes` and wired to `php artisan up` and to prelaunch disable.

#### Unsubscribe (an app-owned data contract)
- The `unsubscribed_at` column, `subscribed()` scope, and full exclusion of unsubscribed rows from every package email (announcements + receipt). The package ships **no** user-facing unsubscribe surface (no route, no link in any email): set/clear the state via `HoldSignup::unsubscribe()` / `resubscribe()` or the operator command `jamesgifford:hold:unsubscribe {email} {--resubscribe}`. The package never sets or clears `unsubscribed_at` on its own.

#### Holding pages
- Self-contained, single-file Blade pages (prelaunch and maintenance) with inline CSS and no build step, so they render even when the app is half-broken. Color via four `--hold-*` CSS custom properties and every user-visible string (including the `?hold=` success/error messages) via a top-of-file `$copy` block. Published copies in `resources/views/vendor/hold/` override the package defaults automatically; delete a published file to revert.

#### Install lifecycle
- `jamesgifford:hold:setup`: publishes config, the migration (with a fresh publish-time timestamp), the `HoldSignup` model (namespace rewritten to the app), and the views; creates the runtime storage directory; offers an interactive review pause that later steps honor; idempotent re-runs; `--force` / `--migrate` for unattended installs. The final summary lists the full host-app path of every published file and reports skipped steps.
- `jamesgifford:hold:uninstall`: removes every published asset and drops the table (with confirmation), `--keep-data` to keep the table and its migration. Setup → uninstall round-trips to a clean state.

#### Configuration and tooling
- Documented config covering routes, prelaunch, notifications (including announcement subjects), mail from-address override, spam protection, and model resolution / publish location.
- A shipped Laravel Boost skill documenting the package's modes, commands, and customization surface.

[1.2.1]: https://github.com/jamesgifford/hold/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/jamesgifford/hold/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/jamesgifford/hold/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/jamesgifford/hold/releases/tag/v1.0.0
