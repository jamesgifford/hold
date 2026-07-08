---
name: jamesgifford-hold
description: Use when working on "coming soon" (pre-launch) or maintenance holding pages, email signup capture, or launch/restore announcements in an application that uses the jamesgifford/hold package. Covers prelaunch mode, native maintenance mode, the signup/unsubscribe/preview routes, the published App\Models\Hold\Signup model, notification overrides, and the jamesgifford:hold:* Artisan commands.
---

# JamesGifford Hold

## When to use this skill

Use when working on a pre-launch ("coming soon") page, an enhanced maintenance
page, capturing email signups from either, or emailing those signups when you
launch or come back online — in an app using the `jamesgifford/hold` package.
The package provides the mechanism; your app owns orchestration.

## The two modes

**Prelaunch ("coming soon")** — package-owned, toggled by a flag file under
`storage/jamesgifford/hold/` (not Laravel's maintenance mode). A GLOBAL
`PrelaunchMode` middleware renders the holding page for every request while
active, except the package's own routes and holders of a valid bypass cookie.
Toggle it with the commands below, never by writing the flag file yourself. The
response status is `config('jamesgifford.hold.prelaunch.status_code')` (200 to
stay indexable, or 503).

**Maintenance** — Laravel's native `php artisan down`, untouched. The package
keeps its own routes reachable while down (a container-bound subclass of
`PreventRequestsDuringMaintenance` merges the package route URIs into the
maintenance `except` list) and ships `resources/views/errors/503.blade.php` with
the capture form. Use a plain `php artisan down` / `php artisan up`.

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
- Unsubscribe is a soft state via the signed `hold.unsubscribe` route
  (`Signup::unsubscribe()` sets `unsubscribed_at`); rows are never deleted.
- `hold.preview` is a signed route that sets the bypass cookie so you can view
  the real app behind the prelaunch page.

## Commands

- `jamesgifford:hold:setup` — install: publish config, the timestamped migration,
  the `App\Models\Hold\Signup` model, and the views; create runtime storage.
  Interactive run pauses to let you edit config, then honors it. Flags: `--force`
  (unattended), `--migrate`.
- `jamesgifford:hold:enable` — activate prelaunch mode; prints a signed preview
  link. Guarded in production (`--force`).
- `jamesgifford:hold:disable` — deactivate prelaunch mode; may auto-schedule the
  launch announcement (see config).
- `jamesgifford:hold:announce` — email the launch/restore announcement now.
  Idempotent (stamps `notified_at`, never double-sends). Flags: `--context=prelaunch|maintenance`
  (inferred when one context has pending signups), `--dry-run` (report counts,
  send nothing).
- `jamesgifford:hold:uninstall` — remove everything published and drop the table.
  Flag: `--keep-data` (keep the table + migration). Finish with `composer remove`.

## Configuration

Published to `config/jamesgifford/hold.php`:

- `routes.register` / `routes.prefix` / `routes.middleware` — set `register` false
  to own routing (publish the routes stub); keep `prefix` in sync everywhere.
- `prelaunch.status_code` (200/503), `prelaunch.bypass_cookie_name` / `..._lifetime_days`.
- `notifications.team_addresses` (empty = no team notice), `notifications.send_signup_receipt`,
  `notifications.auto_announce_on_up`, `notifications.announce_delay_minutes` (the
  change-of-mind delay before an auto-announce sends).
- `spam.rate_limit_per_minute`, `spam.honeypot_field`.

## Customizing

- **Model**: `setup` publishes `App\Models\Hold\Signup` (resolved via
  `config('jamesgifford.hold.models.signup')`). Edit the published file — do NOT
  edit the package base model in `vendor/`. Point the config at a subclass to swap it.
- **Views**: reskin by editing the four `--hold-*` CSS custom properties at the
  top of the published `prelaunch.blade.php` / `errors/503.blade.php`.
- **Notifications**: override any email by pointing a `notifications.classes`
  entry at your own Notification subclass — the package resolves the class at
  send time.

## Do not

- Do NOT run `php artisan down --render` — it bypasses the HTTP kernel, so the
  signup POST route stops working. Use a plain `php artisan down`.
- Do NOT edit the package base model in `vendor/` — edit the published `App\Models\Hold\Signup`.
- Do NOT add `@csrf` or rely on session flash on the holding pages — the signup
  route is CSRF-exempt and feedback is the `?hold=` query param.
- Do NOT toggle prelaunch mode by writing the flag file — use `jamesgifford:hold:enable` / `:disable`.
- Do NOT delete signup rows to unsubscribe — unsubscribe is a soft state (`unsubscribed_at`).
- Do NOT hardcode the route prefix — read `config('jamesgifford.hold.routes.prefix')`.
