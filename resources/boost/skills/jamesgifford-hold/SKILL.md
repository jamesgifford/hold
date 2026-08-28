---
name: jamesgifford-hold
description: Use when working on "coming soon" (pre-launch) or maintenance holding pages, email signup capture, email verification, unsubscribe, or launch/restore announcements in an application that uses the jamesgifford/hold package. Covers prelaunch mode, native maintenance mode, the signup/preview/verify/unsubscribe routes, the published App\Models\HoldSignup model, notification overrides, and the jamesgifford:hold:* Artisan commands.
---

# JamesGifford Hold

## When to use this skill

Use when working on a pre-launch ("coming soon") page, an enhanced maintenance
page, capturing email signups from either, or emailing those signups when you
launch or come back online — in an app using the `jamesgifford/hold` package.
The package provides the mechanism; your app owns orchestration.

## The two modes

Hold is the unified interface for both modes, and **only one may be active at a
time**. Drive both with `jamesgifford:hold:enable {prelaunch|maintenance}` and
`jamesgifford:hold:disable` (see Commands). `enable` refuses if a hold is already
active; run `disable` first.

**Prelaunch ("coming soon")** — package-owned, activated one of two ways (see
`HoldState::source()`, returning `'file'`, `'env'`, or `null`):

1. **File-backed (default):** a flag file under `storage/jamesgifford/hold/`,
   toggled by `jamesgifford:hold:enable prelaunch` / `:disable`. Never write
   the flag file yourself.
2. **Env-forced:** `config('jamesgifford.hold.prelaunch.forced')`, backed by
   `JAMESGIFFORD_HOLD_PRELAUNCH_ENABLED=true` (read via `env()` in `config/hold.php` only — safe
   under `config:cache`). For ephemeral hosting (e.g. Laravel Cloud) where
   local disk does not survive a deploy. `HoldState::isForced()` reports it;
   `isActive()` is true for either source, env taking precedence if both
   are somehow set. **Cannot be turned off from the console** — `:disable`
   says so and exits non-zero instead of pretending success; only unsetting
   `JAMESGIFFORD_HOLD_PRELAUNCH_ENABLED` and redeploying ends it. Since `:enable` is never run
   under this mode, there's no token minted the normal way — use
   `jamesgifford:hold:preview` to mint/re-mint one for either source.

A GLOBAL `PrelaunchMode` middleware renders the holding page for every request
while EITHER source is active, except the package's own routes and holders of
a valid bypass cookie. The response status is
`config('jamesgifford.hold.prelaunch.status_code')` (200 to stay indexable, or
503). Because `storage_path()` is the same physical directory regardless of
`APP_ENV`, a flag file left over from local browsing would otherwise silently
intercept a consuming app's own test suite — `PrelaunchMode` no-ops (and logs
a warning) while `app()->environment('testing')` unless
`config('jamesgifford.hold.prelaunch.enforce_in_testing')` is `true`.

**Maintenance** — Laravel's native `php artisan down`, untouched (no env-var
activation for this mode — only the native mechanism). The package keeps its
own routes reachable while down (a container-bound subclass of
`PreventRequestsDuringMaintenance` merges the package route URIs into the
maintenance `except` list). The maintenance capture page is
`resources/views/vendor/hold/maintenance.blade.php`; `resources/views/errors/503.blade.php`
is a thin shim that `@include`s it. You can run `enable maintenance` (it invokes
`down` with a bypass secret) or a plain `php artisan down` / `php artisan up`
directly — if you run `down` natively while prelaunch is active, Hold self-heals
by auto-disabling prelaunch (one hold at a time). Self-heal can't clear an
**env-forced** hold (nothing to unset from a listener) — it logs a WARNING that
both modes are now active instead; maintenance still wins at request time
(its middleware runs first).

## Signup capture

Both holding pages POST to the `hold.signup` route. Behavior to rely on:

- It is deliberately quiet: honeypot hits, duplicates, and over-the-limit IPs
  all return the SAME success as a real signup — never reveal list membership.
- Spam defenses: a CSS-hidden honeypot field (`config('jamesgifford.hold.spam.honeypot_field')`)
  and a per-IP rate limit (`spam.rate_limit_per_minute`).
- The route is **CSRF-exempt** and feedback comes back as a `?hold=subscribed`
  or `?hold=invalid` query param — the holding pages render before Laravel starts
  the session, so session flash / `@csrf` are NOT available there. Do not add a
  CSRF token to these forms.
- Context (`prelaunch` vs `maintenance`) is detected server-side; don't trust a
  posted context field.
- One row per email. A same-cycle duplicate (row not yet notified) writes NOTHING
  (byte-identical) — UNLESS the row is unverified, in which case the verification
  email is re-sent (still no write). A re-signup during a LATER hold (row already
  notified) re-arms the row: `notified_at` back to null, `requested_at` to now,
  current context, ip/ua refreshed — `unsubscribed_at` and `verified_at` are NEVER
  touched by a re-arm. Exactly one notification per hold.
- `hold.preview` is a signed route that sets the bypass cookie so you can view
  the real app behind the prelaunch page.

**Email verification (double opt-in).** `config('jamesgifford.hold.verification.required')`
(default `true`): a new signup's `verified_at` stays null until it clicks a
signed, expiring link in the `SignupVerification` email (`GET /verify`,
`link_lifetime_days` default 7) — the announcer never emails an unverified
row. When `required` is `false`, capture stamps `verified_at` immediately
instead. `markVerified()` and the `verified()` scope are part of
`HoldSignupContract`. Verification is permanent per address, not per hold —
a re-arm never resets it.

**Unsubscribe.** The package keeps `unsubscribed_at` and excludes those rows
from every email (both announcements + the receipt). Every list email
carries a signed, non-expiring opt-out link (`GET`/`POST /unsubscribe` — the
POST is the RFC 8058 one-click endpoint, CSRF-exempt) plus `List-Unsubscribe`
/ `List-Unsubscribe-Post` headers. `HoldSignup::unsubscribe()` /
`->resubscribe()`, or the operator command
`jamesgifford:hold:unsubscribe {email} {--resubscribe}`, are the
server-side equivalents. The package NEVER clears `unsubscribed_at` except
via a successful `/verify` click — not on re-arm, not from signing up again.
This is deliberate: only proving mailbox access (verifying) can undo an
opt-out, so a third party can't re-arm an address they don't own.

## Commands

- `jamesgifford:hold:setup` — install: publish config, the timestamped migration,
  the `App\Models\HoldSignup` model, and the views; create runtime storage.
  Interactive run pauses to let you edit config, then honors it. Flags: `--force`
  (unattended), `--migrate`.
- `jamesgifford:hold:enable {mode}` — activate a hold: `prelaunch` (prints a signed
  preview link) or `maintenance` (invokes `down` with a bypass secret and prints
  the secret link). Refuses if a hold is already active — no override; disable first.
  `enable prelaunch` while prelaunch is already env-forced (`JAMESGIFFORD_HOLD_PRELAUNCH_ENABLED`)
  reports that clearly and exits SUCCESS without writing a flag file — not
  treated as the "already active" error. Flag: `--retry=<seconds>` (maintenance
  only) sets the `Retry-After` header via `down --retry`, overriding
  `maintenance.retry_after`; `0` omits it.
- `jamesgifford:hold:disable` — deactivate whichever hold is active (prelaunch and/or
  maintenance); may auto-schedule the launch/restore announcement (see config).
  On a `sync` queue with a non-zero delay it REFUSES to schedule and says so —
  the change-of-mind window cannot exist there. CANNOT turn off an env-forced
  prelaunch hold — reports that and exits non-zero (after removing any stray
  flag file first).
- `jamesgifford:hold:preview` — mint a fresh prelaunch bypass token and print
  its signed preview link, without changing whether prelaunch is active. Works
  for either activation source (file or env); re-running invalidates the
  previous link/cookie. Refuses (non-zero) if prelaunch isn't active at all.
  Warns if the resolved `prelaunch.token_store` won't persist (e.g. `array`).
- `jamesgifford:hold:status` — report which hold is active, its source
  (`env`/`file`), and whether a bypass token currently exists. Always exits
  successfully; read-only.
- `jamesgifford:hold:announce` — email the launch/restore announcement now.
  Idempotent (stamps `notified_at`, never double-sends) and only ever emails
  verified, subscribed signups. Flags: `--context=prelaunch|maintenance`
  (inferred when one context has pending signups), `--dry-run` (report counts,
  send nothing), `--yes` (skip the send confirmation prompt — required for a
  non-interactive/`-n` run, which otherwise refuses), `--test=<address>`
  (send one rendered announcement to that address as a rehearsal, no rows
  touched, no prompt; mutually exclusive with `--dry-run`).
- `jamesgifford:hold:uninstall` — remove everything published and drop the table.
  Flags: `--force` (unattended; also required to drop the table when the run
  cannot ask for confirmation, e.g. `-n`), `--keep-data` (keep the table +
  migration). Finish with `composer remove`. Run with `-n` and without `--force`
  it removes the published assets but KEEPS the table — dropping data is always
  an explicit opt-in.
- `jamesgifford:hold:unsubscribe {email}` — operator tool to set a signup's
  unsubscribe state. Flag: `--resubscribe` (clear it instead). This and the model
  methods are the server-side equivalents of the self-service `/unsubscribe`
  route.

## Configuration

Published to `config/jamesgifford/hold.php`:

- `routes.register` / `routes.prefix` / `routes.middleware` — set `register` false
  to own routing (publish the routes stub); keep `prefix` in sync everywhere.
- `prelaunch.status_code` (200/503), `prelaunch.bypass_cookie_name` / `..._lifetime_days`.
- `prelaunch.enforce_in_testing` (default `false`) — whether an active prelaunch
  hold is actually enforced while `APP_ENV` is `testing`; `false` means a
  leftover flag file no-ops instead of intercepting your own test suite.
- `prelaunch.forced` (default `false`, `JAMESGIFFORD_HOLD_PRELAUNCH_ENABLED`) — force prelaunch on
  regardless of the flag file; for ephemeral hosting. Cannot be disabled from
  the console. `prelaunch.token_store` (default `null` → `cache.default`,
  `JAMESGIFFORD_HOLD_PRELAUNCH_TOKEN_STORE`) — cache store for the bypass token under
  env-forced mode; ignored in file-backed mode.
- `maintenance.retry_after` (default 3600) — seconds sent as the `Retry-After`
  header when maintenance is enabled through Hold; `--retry` on `enable` overrides
  it; `0`/`null` omits the header. A bare `artisan down` needs `--retry` manually.
- `notifications.team_addresses` (empty = no team notice), `notifications.send_signup_receipt`,
  `notifications.auto_announce_on_up`, `notifications.announce_delay_minutes` (the
  change-of-mind delay before an auto-announce sends). Auto-announce needs a queue
  that can defer AND a running worker; on `sync` it refuses rather than sending
  immediately. Set the delay to 0 to opt into an immediate send.
- `notifications.subject_launch` / `notifications.subject_restored` /
  `notifications.subject_verify` — the announcement and verification-email
  subject lines (body copy lives in the email templates).
- `verification.required` (default `true`) — require a verify-link click
  before the announcer will ever email a signup; `false` stamps `verified_at`
  at capture instead. `verification.link_lifetime_days` (default 7) — how
  long a verify link stays valid.
- `spam.rate_limit_per_minute`, `spam.honeypot_field`.

## Customizing

- **Model**: `setup` publishes `App\Models\HoldSignup` (resolved via
  `config('jamesgifford.hold.models.signup')`). Edit the published file — do NOT
  edit the package base model in `vendor/`. Point the config at a subclass to swap it.
  Whatever it points at MUST implement `JamesGifford\Hold\Contracts\HoldSignupContract`
  (the published model already does); a class that does not raises an exception.
- **Holding pages**: edit the published `vendor/hold/prelaunch.blade.php` /
  `vendor/hold/maintenance.blade.php` — color via `$bg` (eleven color
  variables default to `null`, including `$bg` itself; a twelfth,
  weight-only variable, `$cardBlendWeight`, is NOT null by default — it
  reads the config-then-`ColorTheme`-constant weight, see below); `$accent`,
  `$text`, `$cardBg`, `$inputBg`, `$inputBorder`, `$cardShadowColor`,
  `$alertSuccessBg`, `$alertSuccessText`, `$alertErrorBg`, and
  `$alertErrorText` all derive automatically from `$bg` via
  `JamesGifford\Hold\Support\ColorTheme` (WCAG contrast picks light or dark
  text; the card background is a blend of `$bg` toward `$text`, strength
  tunable via `$cardBlendWeight`; the accent matches `$bg`'s hue at a fixed
  vibrant saturation/lightness, darkened as needed to stay legible on the
  submit button's fixed white label; the input border blends toward
  `$text`; the card shadow picks black or white, whichever contrasts more
  with `$bg`; the alert backgrounds tint `$cardBg` toward a fixed semantic
  hue, and the alert text picks whichever of a light-/dark-mode candidate
  contrasts more with that alert background). Before auto-deriving, each
  checks `config('jamesgifford.hold.appearance.*')` via
  `JamesGifford\Hold\Hold::appearance()` — tiered this-template-variable >
  `appearance.pages.<property>` > shared `appearance.<property>` >
  auto-derive — so a value can be set once for every template, scoped to
  just the pages, or overridden directly in this one file, which always
  wins. Every user-visible string (incl. the `?hold=` success/error
  messages) is set via the `$copy` block, same top-of-file area (the
  `errors/503.blade.php` shim just includes the maintenance view).
  `vendor/hold/verified.blade.php` / `vendor/hold/unsubscribed.blade.php`
  are minimal siblings rendered after a `/verify` or `/unsubscribe` click —
  no form/alert, so only `$bg`/`$accent`/`$text`/`$cardBg`/
  `$cardShadowColor`/`$cardBlendWeight` and a small `$copy` (`title`,
  `heading`, `body`, `link`), same derivation/config-tiering.
- **Emails**: setup publishes one self-contained template per email —
  `vendor/hold/mail/{announcement,team,receipt,verify}.blade.php`. Each has
  top-of-file blocks for the palette, an optional logo/wordmark header, and
  a `$copy` block with the wording. The palette (`$bg`/`$accent`/`$text`/
  `$card`/`$muted`) auto-derives from `$bg` via
  `JamesGifford\Hold\Support\ColorTheme`, same as the holding pages, and
  goes through the same `Hold::appearance()` config-tier
  (`appearance.mail.<property>` here instead of `appearance.pages.<property>`)
  before falling back to that derivation — plain PHP variables, not CSS
  custom properties (email clients support those poorly).
  `announcement.blade.php` / `receipt.blade.php` additionally render an
  opt-out footer link (`$copy['unsubscribe']`, `$unsubscribeUrl`) only when
  the route is registered; `verify.blade.php` and `team.blade.php` never
  get one. Subjects are config, not template: `notifications.subject_launch`
  / `notifications.subject_restored` / `notifications.subject_verify`.
- **Notifications (structure/channels)**: to change more than copy, point a
  `notifications.classes` entry at your own Notification subclass — the package
  resolves the class at send time.

## Do not

- Do NOT run `php artisan down --render` — it bypasses the HTTP kernel, so the
  signup POST route stops working. Use a plain `php artisan down`.
- Do NOT edit the package base model in `vendor/` — edit the published `App\Models\HoldSignup`.
- Do NOT add `@csrf` or rely on session flash on the holding pages — the signup
  route is CSRF-exempt and feedback is the `?hold=` query param.
- Do NOT toggle prelaunch mode by writing the flag file — use `jamesgifford:hold:enable prelaunch` / `disable`.
- Do NOT try to run both holds at once — `enable` refuses while one is active; running native `down` while prelaunch is up auto-disables prelaunch.
- Do NOT delete signup rows to unsubscribe — it's a soft state; use `HoldSignup::unsubscribe()`/`resubscribe()` or `jamesgifford:hold:unsubscribe`.
- Do NOT build a separate user-facing unsubscribe mechanism — the package
  already ships one (`/unsubscribe`, GET+POST, plus `List-Unsubscribe`
  headers on every list email). Point users at the link the email already
  carries rather than inventing another route.
- Do NOT assume signing up again clears an opt-out — it never does. Only a
  successful `/verify` click clears `unsubscribed_at` (besides the operator
  `--resubscribe` command).
- Do NOT hardcode the route prefix — read `config('jamesgifford.hold.routes.prefix')`.
- Do NOT assume `auto_announce_on_up` works on a `sync` queue — it deliberately
  refuses there, because the delay (and so the change-of-mind window) is discarded.
- Do NOT point `models.signup` at a class that does not implement
  `HoldSignupContract` (including `markVerified()`, added in 1.4.0) —
  resolution throws rather than falling back silently.
- Do NOT forget the `verified()` local scope on a custom `models.signup`
  class (also added in 1.4.0) — it is not part of the contract, so nothing
  catches a missing one at boot; `Announcer` throws the first time it chains
  it, even on `--dry-run`.
- Do NOT expect the announcer to email an unverified signup, whatever
  `verification.required` is currently set to — the stamp-on-create rule
  means only genuinely-pending rows are ever excluded.
