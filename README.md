# Hold

Reusable **"coming soon" (pre-launch)** and **enhanced maintenance-mode** holding
pages for Laravel, with email-capture signup and launch/restore announcement
notifications.

## Overview

Hold gives you two holding pages that both capture email addresses and can later
email everyone — once — when you're ready:

1. **Prelaunch** ("coming soon") — a package-owned mode you toggle with a command.
   While active, global middleware intercepts every request (except the package's
   own routes and holders of a valid bypass cookie) and renders the prelaunch page
   with a configurable HTTP status.
2. **Maintenance** — Laravel's native `php artisan down`, left completely
   untouched. The package participates by shipping a `resources/views/errors/503.blade.php`
   that carries a capture form, and by keeping its own routes reachable while the
   app is down.

The package provides mechanism; your app owns orchestration. The migration and
the `Signup` model are **published into your app** — you own them. Integration is
container-only: nothing edits `bootstrap/app.php` or any other core file, so
`composer remove` fully reverses everything.

## Requirements

- PHP `^8.4`
- Laravel `^13.0`

## Installation

Hold is distributed as a plain Git repository (not Packagist). Add it to your
app's `composer.json` `repositories`, then require it:

```jsonc
// composer.json
"repositories": [
    { "type": "vcs", "url": "https://github.com/jamesgifford/hold" }
]
```

```bash
composer require jamesgifford/hold
php artisan jamesgifford:hold:setup
```

### Setup

`jamesgifford:hold:setup` publishes the config, migration, model, and views,
creates the runtime storage directory, and offers to migrate:

```bash
# Interactive: publishes config, PAUSES so you can review/edit it, then honors
# your edits for every remaining step, and offers to run the migration.
php artisan jamesgifford:hold:setup

# Unattended (CI): skip the pause and every overwrite prompt, run the migration.
php artisan jamesgifford:hold:setup --force --migrate
```

The pause matters: right after the config is published, an interactive run stops
so you can set your route prefix, team addresses, announce delay, model location,
and so on. Every later step re-reads the (possibly edited) config — there are no
hardcoded defaults past the pause.

Setup is idempotent: re-running never publishes a second migration and never
clobbers an edited config (it prompts, or skips silently when unattended).

## Quick start

```bash
# Take the app offline behind the "coming soon" page (prints a signed preview link)
php artisan jamesgifford:hold:enable

# ...collect signups...

# Go live again (optionally auto-announces — see config)
php artisan jamesgifford:hold:disable

# Email your prelaunch signups that you've launched
php artisan jamesgifford:hold:announce
```

## The two modes

### Prelaunch ("coming soon")

Prelaunch is toggled by a flag file under `storage/jamesgifford/hold/`, so it is
independent of your app's config cache and of Laravel's maintenance mode.

- `jamesgifford:hold:enable` activates it (guarded in production), notifies your
  team if addresses are configured, and prints a **signed preview link** that sets
  a bypass cookie so you can view the real app behind the page.
- `jamesgifford:hold:disable` deactivates it. If `auto_announce_on_up` is on, it
  schedules the launch announcement after the configured delay.

**Preview links and bypass cookies are valid per-activation.** Each enable mints
a fresh random token (stored as the flag file's contents); the preview link and
the bypass cookie both carry it, and a request is waved through only when its
token matches the current activation. **Disabling the hold revokes every
outstanding preview link and bypass cookie** — re-enabling issues a new token, so
old links (even with a still-valid signature) and old cookies stop working. Share
the link printed by the most recent `enable`.

The `PrelaunchMode` middleware is registered **globally**. When no hold is active
it is a near-zero-cost no-op (a single `is_file()` check), so the overhead on
normal traffic is negligible. The response status is configurable
(`prelaunch.status_code`): `200` keeps the page indexable, `503` signals
"not yet available" to crawlers and uptime checks.

### Maintenance (`php artisan down`)

Use Laravel's native maintenance mode as usual:

```bash
php artisan down
# ...work...
php artisan up
```

The package keeps its own routes reachable during maintenance (so the 503 page's
signup form works), captures signups with the `maintenance` context, and — when
`auto_announce_on_up` is enabled — schedules the "we're back" announcement when
you run `php artisan up`.

#### ⚠️ Never use `php artisan down --render`

`--render` renders the maintenance view by **bypassing the HTTP kernel**. The
signup form POSTs to a normal route, and with `--render` that route never runs —
**the form silently fails**. Always use a plain `php artisan down`; the package's
published `errors/503.blade.php` and its route bypass handle the rest.

## Signup capture

Both holding pages POST to `/{prefix}/signup`. The endpoint is deliberately quiet:
a bot-tripped honeypot, an over-the-limit IP, and a duplicate address all return
the **same** success response as a genuine new signup — it never reveals whether
an address is already on the list.

- **Honeypot**: a CSS-hidden field (`spam.honeypot_field`, default `website`). A
  filled value is treated as a bot: success is returned, nothing is stored.
- **Rate limiting**: `spam.rate_limit_per_minute` (default 5) per IP.
- **Context**: recorded server-side — `maintenance` when the app is down, else
  `prelaunch` when a hold is active.
- **Unsubscribe** is a soft state (`unsubscribed_at` is set; the row is never
  deleted), so history survives resubscribes and the "already notified" guard
  still holds.

The signup route is **CSRF-exempt** by design: both holding pages render before
Laravel starts the session (prelaunch is global middleware; the 503 view renders
during an aborted maintenance request), so neither can embed a CSRF token. The
honeypot and rate limit guard the endpoint instead. Feedback is returned as a
`?hold=subscribed|invalid` query param the views read — again, because session
flash isn't available where these pages render.

## Announcements and notifications

Four notifications ship with the package, all sent via on-demand mail routes (no
`User` model required):

| Notification | Sent to | When |
| --- | --- | --- |
| `TeamHoldEnabled` | your team addresses | a hold begins |
| `LaunchAnnouncement` | prelaunch signups | you announce a launch |
| `ServiceRestored` | maintenance signups | you announce a restore |
| `SignupReceipt` | a new signup (optional) | on capture, if enabled |

Every public email carries a signed one-click unsubscribe link.

```bash
# Announce immediately (idempotent — notified signups are never emailed twice)
php artisan jamesgifford:hold:announce --context=prelaunch

# See who would be emailed, without sending
php artisan jamesgifford:hold:announce --dry-run
```

If exactly one context has pending signups, `--context` can be omitted. The
announce job sends in chunks and stamps `notified_at` per recipient.

**Delayed / auto-announce.** With `auto_announce_on_up` enabled, disabling
prelaunch (or bringing the app back `up`) dispatches the announcement after
`announce_delay_minutes`. That delay is a change-of-mind window: if the same hold
is active again when the job runs, it aborts silently and emails no one.

### Replacing the notification classes

Point any entry under `notifications.classes` at your own subclass to fully
customize copy or channels — the package resolves the class name at send time:

```php
// config/jamesgifford/hold.php
'notifications' => [
    'classes' => [
        'launch_announcement' => \App\Notifications\OurLaunch::class,
        // ...
    ],
],
```

## Configuration

Published to `config/jamesgifford/hold.php`. Key options:

| Key | Default | Purpose |
| --- | --- | --- |
| `routes.register` | `true` | Register the package routes. `false` = own routing (publish the routes stub). |
| `routes.prefix` | `hold` | URI prefix for every package route. |
| `routes.middleware` | `['web']` | Middleware group for the routes. |
| `prelaunch.status_code` | `200` | HTTP status for the prelaunch page (`200` or `503`). |
| `prelaunch.bypass_cookie_name` | `hold_bypass` | Name of the preview bypass cookie. |
| `prelaunch.bypass_cookie_lifetime_days` | `30` | Bypass cookie lifetime. |
| `notifications.team_addresses` | `[]` | Who receives the "hold enabled" notice. |
| `notifications.send_signup_receipt` | `false` | Email each new signup a receipt. |
| `notifications.auto_announce_on_up` | `false` | Auto-schedule the announcement when a hold ends. |
| `notifications.announce_delay_minutes` | `10` | Change-of-mind delay before an auto-announce sends. |
| `mail.from.address` / `mail.from.name` | `null` | From override (falls back to app defaults). |
| `spam.rate_limit_per_minute` | `5` | Per-IP signup rate limit. |
| `spam.honeypot_field` | `website` | Hidden honeypot field name. |
| `models.signup` | `App\Models\Hold\Signup` | Resolved Signup model. |
| `models.namespace` / `models.path` | `App\Models\Hold` / `app/Models/Hold` | Where setup publishes the model. |

### Owning your routes

Set `routes.register => false` and publish the routes stub to wire routing
yourself:

```bash
php artisan vendor:publish --tag=jamesgifford-hold-routes
# then load routes/hold.php from your own provider or bootstrap/app.php:
# Route::middleware('web')->prefix('hold')->group(base_path('routes/hold.php'));
```

Keep the prefix in sync with `routes.prefix`: the holding pages, the prelaunch
allow-list, and the maintenance except-merge all read that value to know which
URIs are "package routes".

## Customizing the views

The prelaunch and 503 views are self-contained single Blade files with inline CSS
and **no build step** — they render even when your app is half-broken. Each
declares four CSS custom properties at the top for a three-line reskin:

```css
:root {
    --hold-bg: #f5f6f8;
    --hold-card-bg: #ffffff;
    --hold-text: #1a1d24;
    --hold-accent: #2563eb;
}
```

Setup publishes them to `resources/views/vendor/hold/prelaunch.blade.php` and
`resources/views/errors/503.blade.php`; edit them freely.

## How maintenance integration works (container binding)

The package binds a subclass of Laravel's
`Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance` in the
container. Wherever Laravel resolves that framework middleware, it gets the
subclass, which merges the package's route URIs (built from `routes.prefix`) into
the maintenance `except` list — so the signup/unsubscribe/preview routes stay
reachable during `down`.

This is **just a container binding**: no core file is modified, and
`composer remove jamesgifford/hold` removes it automatically. This is also why
`down --render` must not be used — it renders outside the HTTP kernel, so neither
the binding nor the signup route participate.

## AI tooling

The package ships a [Laravel Boost](https://github.com/laravel/boost) skill
(`resources/boost/skills/jamesgifford-hold/`) that teaches an AI assistant the
package's public API and guardrails — the two hold modes, signup capture, the
announcement commands, notification overrides, and anti-patterns (e.g. never
`php artisan down --render`). In a consuming app that uses Boost, install it with
`php artisan boost:install` (or `boost:update` to refresh).

## Uninstall

```bash
php artisan jamesgifford:hold:uninstall            # removes assets + drops the table
php artisan jamesgifford:hold:uninstall --keep-data # keeps the table + migration
composer remove jamesgifford/hold                   # finishes (removes the binding)
```

Uninstall removes the published config, model, views, migration, and the runtime
storage directory, then drops the `hold_signups` table (with its own confirmation;
`--keep-data` skips the drop and leaves the migration in place). Setup → uninstall
round-trips to a clean state.

## Commands

| Command | Purpose |
| --- | --- |
| `jamesgifford:hold:setup` | Publish config, migration, model, views; optionally migrate. |
| `jamesgifford:hold:uninstall` | Remove everything published and drop the table (`--keep-data` to keep it). |
| `jamesgifford:hold:enable` | Activate prelaunch mode; print a signed preview link. |
| `jamesgifford:hold:disable` | Deactivate prelaunch mode; optionally auto-announce. |
| `jamesgifford:hold:announce` | Email the launch/restore announcement (`--context`, `--dry-run`). |

## Testing

```bash
composer install
composer test          # vendor/bin/pest
composer format        # vendor/bin/pint
```

## License

MIT © James Gifford
