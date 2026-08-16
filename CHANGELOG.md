# Changelog

All notable changes to this package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2026-08-16

> ### Upgrading from 1.3.x — read this first
>
> This release contains **three breaking changes**. All three are compile-time
> or boot-time failures with clear messages, or a documented behavior change —
> not silent breakage.
>
> 1. **`HoldSignupContract` gains `markVerified(): void` and
>    `@property Carbon|null $verified_at`.** A custom `models.signup` class
>    published before this release needs both added. Re-publish the model
>    (`php artisan jamesgifford:hold:setup`, accept the overwrite) or hand-add
>    them; a class that exists but does not implement the contract raises a
>    clear exception at boot rather than resolving to the wrong model.
> 2. **Email verification is required by default.** A fresh signup no longer
>    gets announced to until it clicks a link in the new `SignupVerification`
>    email — `verification.required` defaults to `true`. Existing rows are
>    grandfathered by the new upgrade migration (`verified_at = requested_at`),
>    so nobody already on your list needs to re-verify; only *newly captured*
>    signups are affected. Set `verification.required` to `false` in
>    `config/jamesgifford/hold.php` to keep the 1.3.x single-opt-in behavior.
> 3. **`jamesgifford:hold:announce` now confirms before a real send.** It
>    prints the exact recipient count and asks `Send now?`; a non-interactive
>    run (`-n`, the shape CI/deploy scripts use) refuses without `--yes`. Add
>    `--yes` to any scripted `announce` invocation. `--dry-run` is unaffected.
>
> Also run `php artisan jamesgifford:hold:setup` after upgrading (safe to
> re-run) — it publishes the new `add_verification_to_hold_signups_table`
> migration and the three new views, without touching anything already
> published. Then `php artisan migrate`.

### Added

- **Email verification (double opt-in).** A new signup must click a signed,
  expiring link (`GET /{prefix}/verify`, `verification.link_lifetime_days`
  default 7 days) before the announcer will ever email it — protection
  against one person signing another's address up without their knowledge.
  New `verified_at` column (nullable, indexed), `HoldSignup::markVerified()`
  (idempotent) and `verified()` scope, `SignupVerification` notification +
  `mail/verify.blade.php` template (same palette/header/`$copy` conventions
  as the other three), `verified.blade.php` confirmation page, and config
  `verification.required` (default `true`) / `verification.link_lifetime_days`
  (default `7`). When `required` is `false`, capture stamps `verified_at`
  immediately instead — no address is ever left permanently unreachable
  whichever way this is set. A same-cycle duplicate submission of an
  unverified row re-sends the verification email (still writing nothing to
  the row); re-arming a verified row never resets `verified_at` — proven
  ownership carries forward across every later hold, it is not per-hold like
  `requested_at`/`notified_at`. `Announcer::targets()` now excludes
  unverified rows unconditionally, so `jamesgifford:hold:announce` and
  `Announcer::pending()` agree for free.
- **User-facing opt-out.** Every list email (`LaunchAnnouncement`,
  `ServiceRestored`, `HoldSignupReceipt` — not the team notice or the
  verification email) now carries a signed, **non-expiring** opt-out link
  (`GET`/`POST /{prefix}/unsubscribe`) and RFC 8058 `List-Unsubscribe` /
  `List-Unsubscribe-Post` headers, so mail clients and providers can offer
  their own native one-click unsubscribe UI. The POST is the RFC 8058
  one-click endpoint (CSRF-exempt, like signup); the GET renders a new
  `unsubscribed.blade.php` confirmation page. New `FormatsHoldMail::applyUnsubscribe()`
  mints the link and headers, null-safe (degrades to no link/headers) when
  `routes.register` is `false` and no self-hosted routes are wired.
  **Clicking the verify link is the only thing that ever clears an
  opt-out** — signing up again never does, so a third party who knows an
  opted-out address cannot re-arm it; only the mailbox owner, by opening the
  verification email, can. The operator command
  (`jamesgifford:hold:unsubscribe`) and `HoldSignup::unsubscribe()`/
  `resubscribe()` are unchanged and still work.
- **Announce rehearsal.** A real `jamesgifford:hold:announce` send now
  prints the exact recipient count and asks for confirmation before
  sending; `--yes` skips the prompt for scripted/CI use, and a
  non-interactive run without it refuses rather than silently emailing
  everyone (`--dry-run` is unaffected either way). New `--test=<address>`
  sends one fully rendered announcement to an arbitrary address without
  touching any row, prompting, or requiring pending signups to exist — a
  way to see exactly what a real send looks like before committing to one;
  mutually exclusive with `--dry-run`.
- `JamesGifford\Hold\Events\HoldSignupVerified` and `HoldSignupUnsubscribed`
  — fired by the new routes; the package ships no listeners for either, they
  exist as observability hooks for the app to build on.
- `JamesGifford\Hold\Support\Verification` — mints and sends the
  verification link (`url()`/`send()`), mirroring
  `EnableCommand::printPreviewLink()`'s graceful degradation when routes
  aren't registered.
- A new upgrade migration, `add_verification_to_hold_signups_table`, adds
  `verified_at` and backfills every existing row to grandfathered-verified
  (`verified_at = requested_at`) — a one-time backfill that runs immediately
  after the column is added, before any row can be genuinely
  awaiting-verification. `PackageMigration` now manages a list of stems
  (`STEMS`, replacing the single `STEM` constant) and publishes/tracks each
  independently — re-running `setup` after upgrading publishes only the
  migration(s) it doesn't already have, not a redundant second copy of the
  original.

### Fixed

- **`jamesgifford:hold:setup` and `:uninstall` never actually published or
  removed three of the four templates a fresh install needed** (found while
  wiring the new ones): `ManagesHoldAssets::viewMap()` — the list these two
  commands actually iterate, distinct from the `vendor:publish` tag array —
  only listed the pre-1.4.0 views. Both lists now agree.

### Changed

- `HoldSignupReceipt` no longer sends when `verification.required` is `true`
  (the default), even with `notifications.send_signup_receipt` also
  enabled — the verification email is the signup-time confirmation in that
  mode, so exactly one email goes out either way, never both.
- `HoldSignup`'s docblock and `jamesgifford:hold:unsubscribe`'s no longer
  describe unsubscribe as having no user-facing surface — it has one now
  (see Added above); the "package never clears it except deliberately"
  half of the contract is unchanged.

### Documentation

- README: new **Email verification** section; **Unsubscribe** rewritten in
  full (self-service link + headers, the verify-click-only reset rule,
  existing-installs re-publish caveat for the footer); config, command,
  and publish-tag tables updated; views tree includes the two new pages and
  `mail/verify.blade.php`; **The signup model contract** documents the
  `markVerified()` breaking change with its own existing-installs note.
- `resources/boost/skills/jamesgifford-hold/SKILL.md`: same surface
  covered — verification, unsubscribe, the new routes, the `announce`
  flags, the config keys, and updated `Do not` guardrails (no longer "don't
  build a user-facing unsubscribe" — now "don't build a *second* one").

## [1.3.1] - 2026-08-16

### Fixed

- **Malformed `appearance.*` config crashed every holding-page render and
  mail send.** `Hold::appearance()` returned a config value verbatim as long
  as it was non-null, so an empty string (e.g. an unset `env()` var), a CSS
  color name, or a wrong-typed value reached `ColorTheme` and threw — an
  uncaught `InvalidArgumentException` on every prelaunch/maintenance page
  view and every announcement/receipt send for as long as the hold was up.
  `appearance()` now validates each value against its property type (a
  well-formed hex color, or a numeric 0-1 weight for a `*_weight` key)
  before accepting it, and falls through to the next config tier — then the
  package's own derivation — exactly as it already does for a genuinely
  absent value.

### Documentation

- **The "rebrand without touching these files" claim didn't hold for
  templates published before 1.3.0.** Those copies set `$bg` (and its
  siblings) to a literal default rather than `null`, so the template's own
  variable — which always wins — silently ignored the new `appearance.*`
  config tier. The [Appearance](#appearance) section now says so and points
  at re-publishing.
- **`resources/boost/skills/jamesgifford-hold/SKILL.md` miscounted the
  holding pages' palette variables** as "eleven color/weight variables …
  default to null." Eleven *color* variables do; the twelfth,
  `$cardBlendWeight`, is weight-only and does not default to null. Corrected.
- Fixed the `[1.3.0]` compare link below, which pointed at `HEAD` instead of
  the tagged release.

### Testing & tooling

*No runtime effect.*

- `publishEditedPage()` / `publishEditedMail()` (used by the palette/copy
  tests) moved from `PageCopyTest.php` / `EmailCopyTest.php` into
  `tests/Pest.php`, so a test file that needs them — `AppearanceConfigTest.php`
  in particular — no longer errors with `Call to undefined function` when
  Pest is filtered to run it alone.
- Added direct `Hold::appearance()` coverage for `card_blend_weight` /
  `muted_blend_weight`, plus a template-integration test that drives each
  through the shipped `prelaunch.blade.php` / `mail/receipt.blade.php` lines
  from config rather than only via a literal override — previously nothing
  exercised the config path for either weight end to end.
- The two mail palette tests for `$cardBlendWeight`/`$mutedBlendWeight` now
  match only the executable prefix of their target line, not its trailing
  comment verbatim — matching `PagePaletteTest.php`'s existing convention and
  cutting a needless source of test churn on comment-only edits.

## [1.3.0] - 2026-08-15

### Added

- **Holding pages auto-adapt to `$bg`.** Set `$bg` at the top of
  `prelaunch.blade.php` / `maintenance.blade.php` and `$accent`, `$text`,
  `$cardBg`, and `$inputBg` now all derive automatically — a hue-matched
  accent (so the button/eyebrow/focus-ring color can't clash with `$bg`),
  darkened as needed to stay legible on the submit button's fixed white
  label, plus switching to light text for a dark background — computed via
  WCAG contrast and HSL hue matching (`JamesGifford\Hold\Support\ColorTheme`).
  Set any of the four directly for full manual control of just that one
  value. A new `$cardBlendWeight` variable (default
  `ColorTheme::CARD_BLEND_WEIGHT`, `0.12`) tunes how strongly the
  auto-derived `$cardBg` departs from `$bg`.
- **Alert colors, the email input's border, and the card's shadow now also
  adapt to `$bg`.** Previously hardcoded assuming a light theme, these broke
  outright on a dark `$bg` — the alert text could drop as low as ~1.9:1
  contrast against its own background, and the border/shadow were nearly
  invisible. `$alertSuccessBg`/`$alertSuccessText`/`$alertErrorBg`/
  `$alertErrorText` now tint `$cardBg` toward a fixed semantic hue and pick
  a light-/dark-mode text candidate by WCAG contrast; `$inputBorder` blends
  toward `$text` instead of a fixed black; `$cardShadowColor` picks black or
  white, whichever contrasts more with `$bg`. All six are new PHP
  variables, each defaulting to `null` (auto-derive) with a real value
  winning outright, same as the rest of the palette. At the default light
  palette every value is pixel-identical to before except
  `--hold-input-border`, which shifts by a single 8-bit rounding unit
  (`#d0d1d3` → `#d0d1d4`); set `$inputBorder = '#d0d1d3';` to keep the
  previous exact value.
- **The three mail templates auto-adapt to `$bg` too.** Set `$bg` at the top
  of `announcement.blade.php` / `team.blade.php` / `receipt.blade.php` and
  `$accent`, `$text`, `$card`, and `$muted` now all derive automatically,
  same `ColorTheme` math as the holding pages — previously each was a fixed
  hardcoded value with no protection against a changed `$bg` clashing with
  the accent or making text illegible. `$cardBlendWeight` and a new
  `$mutedBlendWeight` (default `ColorTheme::MUTED_BLEND_WEIGHT`, `0.61`)
  tune how strongly `$card`/`$muted` depart from `$bg`. Set any of the four
  colors directly for full manual control of just that one value. Colors
  stay plain PHP variables interpolated into inline styles (not CSS custom
  properties — email clients support those poorly), unchanged from before.
- **A new `config('jamesgifford.hold.appearance')` section sets colors once
  for every template.** Every color/weight property across both holding
  pages and all three mail templates now resolves through the new
  `JamesGifford\Hold\Hold::appearance()`, tiered **this template's own PHP
  variable (unchanged, always wins) → `appearance.pages.<property>` /
  `appearance.mail.<property>` (scope a value to just one template family)
  → shared `appearance.<property>` (applies to both) → the existing
  `ColorTheme` auto-derivation (completely unchanged — this is a new tier
  ahead of it, not a replacement)**. Lets a developer set `appearance.bg`
  once to rebrand every template, or set `appearance.pages.bg` alone to
  recolor just the holding pages while the mail templates keep their
  default. Every key defaults to `null` (no override) except
  `appearance.bg`, which defaults to `#f5f6f8` — nothing changes for an
  install that doesn't touch this config.

### Fixed

- **Removed the false "Unsubscribe anytime" claim** from the prelaunch and
  maintenance pages' default `note` copy. The package ships no user-facing
  unsubscribe mechanism (no route, no link — see
  [Unsubscribe](#unsubscribe-an-app-owned-data-contract)), so the promise
  was never true. The note now reads "We'll email you once when we're
  live/back." — still accurate, since the package sends exactly one
  notification per requested hold.

### Changed

- **`--hold-card-bg`'s shipped default** is now computed (a blend of `$bg`
  toward `$text`, weight 0.12) rather than a hardcoded `#ffffff` literal —
  visually a subtle shift (`#dbdcdf` at the default palette). Set
  `$cardBg = '#ffffff';` to keep the previous exact value.
- **`--hold-accent`'s shipped default** is now computed too (hue-matched to
  `$bg`, at a fixed vibrant saturation/lightness, darkened until it clears
  WCAG 4.5:1 contrast against white) rather than the previous hardcoded
  `#2563eb` literal — a subtle shift to `#2a5bc6` at the default palette
  (same blue family). Set `$accent = '#2563eb';` to keep the previous
  exact value.
- **Page colors moved from CSS custom-property literals to PHP variables**
  at the top of the file, matching the email templates' existing pattern —
  necessary because the light/dark text decision needs real branching logic
  no broadly-supported CSS function can do yet. The five `--hold-*` custom
  properties still exist and every other CSS rule still reads them
  unchanged; only where their values come from has moved.
- **The mail templates' `$card` and `$accent` shipped defaults** are now
  computed the same way as the holding pages' — `$card` shifts from a
  hardcoded `#ffffff` to `#dbdcdf` (blend of `$bg` toward `$text`, weight
  0.12); `$accent` shifts from a hardcoded `#2563eb` to `#2a5bc6`
  (hue-matched to `$bg`, same blue family). `$text` is unchanged (`#1a1d24`
  either way). Set `$card = '#ffffff';` / `$accent = '#2563eb';` to keep the
  previous exact values.
- **The mail templates' `$muted` shipped default** shifts from a hardcoded
  `#6b7280` to `#6f7277` (blended from `$bg` toward `$text`) — chosen to
  land within 0.006 of the previous value's own contrast ratio against
  `$bg`, not an exact color match. Set `$muted = '#6b7280';` to keep the
  previous exact value.

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

[1.4.0]: https://github.com/jamesgifford/hold/compare/v1.3.1...HEAD
[1.3.1]: https://github.com/jamesgifford/hold/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/jamesgifford/hold/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/jamesgifford/hold/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/jamesgifford/hold/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/jamesgifford/hold/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/jamesgifford/hold/releases/tag/v1.0.0
