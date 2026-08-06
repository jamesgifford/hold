---
name: jamesgifford-hold
description: Use when working on "coming soon" (pre-launch) or maintenance holding pages, email signup capture, or launch/restore announcements in an application that uses the jamesgifford/hold package. Covers prelaunch mode, native maintenance mode, the signup/preview routes, the published App\Models\HoldSignup model, the unsubscribe data contract, notification overrides, and the jamesgifford:hold:* Artisan commands.
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

**Prelaunch ("coming soon")** — package-owned, toggled by a flag file under
`storage/jamesgifford/hold/` (not Laravel's maintenance mode). A GLOBAL
`PrelaunchMode` middleware renders the holding page for every request while
active, except the package's own routes and holders of a valid bypass cookie.
Toggle it with the commands, never by writing the flag file yourself. The
response status is `config('jamesgifford.hold.prelaunch.status_code')` (200 to
stay indexable, or 503).

**Maintenance** — Laravel's native `php artisan down`, untouched. The package
keeps its own routes reachable while down (a container-bound subclass of
`PreventRequestsDuringMaintenance` merges the package route URIs into the
maintenance `except` list). The maintenance capture page is
`resources/views/vendor/hold/maintenance.blade.php`; `resources/views/errors/503.blade.php`
is a thin shim that `@include`s it. You can run `enable maintenance` (it invokes
`down` with a bypass secret) or a plain `php artisan down` / `php artisan up`
directly — if you run `down` natively while prelaunch is active, Hold self-heals
by auto-disabling prelaunch (one hold at a time).

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
  (byte-identical). A re-signup during a LATER hold (row already notified) re-arms
  the row: `notified_at` back to null, `requested_at` to now, current context, ip/ua
  refreshed — `unsubscribed_at` is NEVER touched. Exactly one notification per hold.
- `hold.preview` is a signed route that sets the bypass cookie so you can view
  the real app behind the prelaunch page.

**Unsubscribe is an app-owned data contract.** The package keeps `unsubscribed_at`
and excludes those rows from every email (announce + receipt), but ships NO
user-facing unsubscribe (no route, no link). Set/clear it via `HoldSignup::unsubscribe()`
/ `HoldSignup::resubscribe()`, or the operator command `jamesgifford:hold:unsubscribe
{email} {--resubscribe}`. The package never sets or clears it on its own.

## Commands

- `jamesgifford:hold:setup` — install: publish config, the timestamped migration,
  the `App\Models\HoldSignup` model, and the views; create runtime storage.
  Interactive run pauses to let you edit config, then honors it. Flags: `--force`
  (unattended), `--migrate`.
- `jamesgifford:hold:enable {mode}` — activate a hold: `prelaunch` (prints a signed
  preview link) or `maintenance` (invokes `down` with a bypass secret and prints
  the secret link). Refuses if a hold is already active — no override; disable first.
- `jamesgifford:hold:disable` — deactivate whichever hold is active (prelaunch and/or
  maintenance); may auto-schedule the launch/restore announcement (see config).
  On a `sync` queue with a non-zero delay it REFUSES to schedule and says so —
  the change-of-mind window cannot exist there.
- `jamesgifford:hold:announce` — email the launch/restore announcement now.
  Idempotent (stamps `notified_at`, never double-sends). Flags: `--context=prelaunch|maintenance`
  (inferred when one context has pending signups), `--dry-run` (report counts,
  send nothing).
- `jamesgifford:hold:uninstall` — remove everything published and drop the table.
  Flags: `--force` (unattended; also required to drop the table when the run
  cannot ask for confirmation, e.g. `-n`), `--keep-data` (keep the table +
  migration). Finish with `composer remove`. Run with `-n` and without `--force`
  it removes the published assets but KEEPS the table — dropping data is always
  an explicit opt-in.
- `jamesgifford:hold:unsubscribe {email}` — operator tool to set a signup's
  unsubscribe state. Flag: `--resubscribe` (clear it instead). This and the model
  methods are the ONLY ways to change `unsubscribed_at`.

## Configuration

Published to `config/jamesgifford/hold.php`:

- `routes.register` / `routes.prefix` / `routes.middleware` — set `register` false
  to own routing (publish the routes stub); keep `prefix` in sync everywhere.
- `prelaunch.status_code` (200/503), `prelaunch.bypass_cookie_name` / `..._lifetime_days`.
- `notifications.team_addresses` (empty = no team notice), `notifications.send_signup_receipt`,
  `notifications.auto_announce_on_up`, `notifications.announce_delay_minutes` (the
  change-of-mind delay before an auto-announce sends). Auto-announce needs a queue
  that can defer AND a running worker; on `sync` it refuses rather than sending
  immediately. Set the delay to 0 to opt into an immediate send.
- `notifications.subject_launch` / `notifications.subject_restored` — the two
  announcement subject lines (body copy lives in the email templates).
- `spam.rate_limit_per_minute`, `spam.honeypot_field`.

## Customizing

- **Model**: `setup` publishes `App\Models\HoldSignup` (resolved via
  `config('jamesgifford.hold.models.signup')`). Edit the published file — do NOT
  edit the package base model in `vendor/`. Point the config at a subclass to swap it.
  Whatever it points at MUST implement `JamesGifford\Hold\Contracts\HoldSignupContract`
  (the published model already does); a class that does not raises an exception.
- **Holding pages**: edit the published `vendor/hold/prelaunch.blade.php` /
  `vendor/hold/maintenance.blade.php` — color via the four `--hold-*` CSS custom
  properties, and every user-visible string (incl. the `?hold=` success/error
  messages) via the `$copy` block, both at the top of the file (the
  `errors/503.blade.php` shim just includes the maintenance view).
- **Emails**: setup publishes one self-contained template per email —
  `vendor/hold/mail/{announcement,team,receipt}.blade.php`. Each has top-of-file
  blocks for the palette, an optional logo/wordmark header, and a `$copy` block
  with the wording. Subjects for the two announcements are config, not template:
  `notifications.subject_launch` / `notifications.subject_restored`.
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
- Do NOT build a user-facing unsubscribe from the package — it ships none; the app owns that decision. The package never sets/clears `unsubscribed_at` on its own.
- Do NOT hardcode the route prefix — read `config('jamesgifford.hold.routes.prefix')`.
- Do NOT assume `auto_announce_on_up` works on a `sync` queue — it deliberately
  refuses there, because the delay (and so the change-of-mind window) is discarded.
- Do NOT point `models.signup` at a class that does not implement
  `HoldSignupContract` — resolution throws rather than falling back silently.
