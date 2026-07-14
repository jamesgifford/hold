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
   untouched. The package participates by shipping a maintenance capture page
   (`resources/views/vendor/hold/maintenance.blade.php`, rendered on `down` via a
   published `errors/503.blade.php` shim) and by keeping its own routes reachable
   while the app is down.

Hold is the unified interface for both — one command pair (`enable {mode}` /
`disable`), with only one mode ever active at a time.

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
# Put up the "coming soon" page (prints a signed preview link)
php artisan jamesgifford:hold:enable prelaunch

# ...or take a live app down for maintenance (prints a secret bypass link)
php artisan jamesgifford:hold:enable maintenance

# Bring the app back — disables whichever hold is active
php artisan jamesgifford:hold:disable

# Email your signups that you're live / back
php artisan jamesgifford:hold:announce
```

## The two modes

Hold is the single interface for both holding modes, and **only one may be active
at a time.** You drive both through two commands:

```bash
php artisan jamesgifford:hold:enable prelaunch     # "coming soon" page
php artisan jamesgifford:hold:enable maintenance   # native `artisan down`, managed by Hold
php artisan jamesgifford:hold:disable              # end whichever hold is active
```

`enable` **refuses** (and names the active mode) if a hold is already up — run
`disable` first; there is no override. Every run ends with an `Active hold: …`
status line so the resulting state is unambiguous.

### Which mode do I want?

| | **Prelaunch** | **Maintenance** |
| --- | --- | --- |
| Use when | Before you've launched — a "coming soon" teaser | Temporarily taking a live app down for work |
| Mechanism | Package flag file + global middleware | Laravel's native `php artisan down` |
| HTTP status | `200` (indexable) or `503`, configurable | `503` |
| Your bypass | Signed preview link (per-activation token) | Laravel secret link (`/{secret}`) |
| Signup context | `prelaunch` → launch announcement | `maintenance` → "we're back" announcement |

### Prelaunch ("coming soon")

`enable prelaunch` writes a flag file under `storage/jamesgifford/hold/` (so it is
independent of config cache) and prints a **signed preview link**; `disable`
clears it. The `PrelaunchMode` middleware is registered **globally** — when no
hold is active it is a near-zero-cost no-op (a single `is_file()` check). The
response status is configurable (`prelaunch.status_code`): `200` keeps the page
indexable, `503` signals "not yet available" to crawlers and uptime checks.

**Preview links and bypass cookies are valid per-activation.** Each enable mints
a fresh random token (stored as the flag file's contents); the preview link and
the bypass cookie both carry it, and a request is waved through only when its
token matches the current activation. **Disabling the hold revokes every
outstanding preview link and bypass cookie** — re-enabling issues a new token, so
old links (even with a still-valid signature) and old cookies stop working. Share
the link printed by the most recent `enable`.

### Maintenance

`enable maintenance` runs Laravel's native `php artisan down` for you (with a
generated `--secret`) and prints the secret bypass link (`/{secret}`); `disable`
runs `php artisan up`. Laravel's maintenance mode is the **untouched underlying
mechanism** — Hold just manages it, keeps its own routes reachable so the capture
form works, and records signups with the `maintenance` context. When
`auto_announce_on_up` is enabled, `up` schedules the "we're back" announcement.

**Native `down`/`up` still work directly** (deploy tooling often calls them), which
bypasses Hold's one-hold check — so Hold **self-heals**: if prelaunch is active
when maintenance comes up natively, Hold automatically disables prelaunch (logging
an informational line) so only one hold is ever active.

> ⚠️ **Deploy caveat:** a deploy script that wraps the deploy in `artisan down`/`up`
> will therefore knock the app **out of prelaunch** via self-heal. During the
> pre-launch phase, either skip `down`/`up` in your deploy script or re-enable
> prelaunch (`jamesgifford:hold:enable prelaunch`) as the final deploy step.

#### The maintenance template (shim pattern)

Setup publishes the maintenance capture page as
`resources/views/vendor/hold/maintenance.blade.php` — **edit it there.** It also
publishes `resources/views/errors/503.blade.php` as a two-line **shim** that
Laravel renders on `down`; the shim just `@include`s the maintenance view:

```blade
{{-- Hold package: Laravel renders this on `artisan down`; edit maintenance.blade.php instead. --}}
@include('hold::maintenance')
```

(Prelaunch's page is the sibling `resources/views/vendor/hold/prelaunch.blade.php`.)

#### ⚠️ Never use `php artisan down --render`

`--render` renders the maintenance view by **bypassing the HTTP kernel**. The
signup form POSTs to a normal route, and with `--render` that route never runs —
**the form silently fails**. Always use a plain `php artisan down` (or
`jamesgifford:hold:enable maintenance`); the published `errors/503.blade.php` shim
and the route bypass handle the rest.

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

**One row per email, lifecycle-aware.** `requested_at` records when someone
requested notification for the current hold:

- **New email** → a row is created (`requested_at` = now).
- **Same-cycle duplicate** (the row hasn't been notified yet) → **nothing is
  written**; the row stays byte-identical.
- **Re-signup during a later hold** (the row was already notified) → the row is
  **re-armed**: `notified_at` cleared, `requested_at` reset, `context` set to the
  current mode, `ip_address`/`user_agent` refreshed. `unsubscribed_at` is **never**
  touched.

Each requested hold produces **exactly one** notification (the `notified_at` guard).

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
| `HoldSignupReceipt` | a new/re-armed signup (optional) | on capture, if enabled |

Unsubscribed rows (`unsubscribed_at` set) receive **none** of these — including
the receipt (see [Unsubscribe](#unsubscribe-an-app-owned-data-contract)).

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

### Customizing the announcement emails

Every package email — launch, restore, the optional receipt, and the team
notice — renders through **one** self-contained HTML template with inline styles
and **no dependency on Laravel's mail markdown layout** (no logo, no theme, no
build step). Two levels of control:

**1. Edit copy, links, and colors — edit the published template.** Setup
publishes it alongside the other views to
`resources/views/vendor/hold/mail/announcement.blade.php`; the package falls back
to its own copy until you do. The palette is a block of plain PHP variables at
the very top of the file (email clients support CSS custom properties poorly, so
these are interpolated into inline styles):

```php
$bg     = '#f5f6f8';  // page background
$card   = '#ffffff';  // card background
$text   = '#1a1d24';  // body text
$muted  = '#6b7280';  // secondary / footnote text
$accent = '#2563eb';  // heading, button, links
```

Change those five lines to reskin every email; edit the markup below them to
adjust wording, add links, or restructure. Each notification passes in its own
`$heading` and `$body`, so the same file serves all four messages.

**Add a header (logo or wordmark).** A second variable block just below the
palette drives an optional header above the heading, with three modes:

```php
$logoUrl   = null;   // mode A: absolute, publicly hosted image URL
$logoName  = null;   // mode B: text wordmark (e.g. config('app.name'))
$logoWidth = 150;    // rendered image width in px
```

- **Image** — set `$logoUrl`. It **must be an absolute, publicly hosted URL**
  (email clients can't load local files); use `asset('images/logo.png')` or a
  CDN link. Any source size works: it renders centered at `$logoWidth` with its
  aspect ratio preserved (no cropping). A wide/landscape logo (~3:1) suits the
  layout best, and for crisp high-DPI display point it at a source ~2–3× the
  rendered width (~300–450px wide for the default 150). Blocked-image clients
  fall back to the alt text.
- **Wordmark** — leave `$logoUrl` null and set `$logoName` to render the name as
  a styled masthead (tweak its look inline, right there in the file).
- **None** (default) — leave both null; no header and no header spacing render.

If both are set the image wins; the name is still used as the image's alt text.

> **Existing installs:** a template published before this feature won't have the
> header block. Re-publish it (delete your copy and re-run setup, or
> `vendor:publish --tag=jamesgifford-hold-views --force`) or hand-add the four
> variables from the package copy — there is no automatic merge.

**2. Change structure or channels — replace the notification class.** Point any
entry under `notifications.classes` at your own subclass to take over `toMail()`
entirely (a different template, extra channels, etc.) — the package resolves the
class name at send time:

```php
// config/jamesgifford/hold.php
'notifications' => [
    'classes' => [
        'launch_announcement' => \App\Notifications\OurLaunch::class,
        // ...
    ],
],
```

## Unsubscribe (an app-owned data contract)

Unsubscribe is a **data contract, not a feature.** The package keeps the
`unsubscribed_at` column and fully respects it — an unsubscribed row receives
**no** package email (announcements *and* the signup receipt) — but ships **no
user-facing way to set it**: no route, no controller, no link in any email. Your
app decides whether and how to expose opt-out (e.g. a future global
communications preference).

The package provides the means and nothing more:

- **Model methods** on the published `App\Models\HoldSignup`: `->unsubscribe()`
  and `->resubscribe()` (set / clear `unsubscribed_at`).
- **Operator command** (server-side only, no public exposure):

  ```bash
  php artisan jamesgifford:hold:unsubscribe user@example.com
  php artisan jamesgifford:hold:unsubscribe user@example.com --resubscribe
  ```

The package **never** sets or clears `unsubscribed_at` on its own — not even on
re-arm. An unsubscribed address whose row is re-armed by a later signup succeeds
silently but is emailed nothing until the app resubscribes it.

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
| `models.signup` | `App\Models\HoldSignup` | Resolved HoldSignup model. |
| `models.namespace` / `models.path` | `App\Models` / `app/Models` | Where setup publishes the model (`HoldSignup.php`). |

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

Every package view is published into Laravel's standard vendor-views location so
you own an editable copy:

```
resources/views/vendor/hold/
├── prelaunch.blade.php
├── maintenance.blade.php
└── mail/
    └── announcement.blade.php
```

**How overriding works.** The package registers these under the `hold::` view
namespace, so a published copy in `resources/views/vendor/hold/` **automatically
overrides** the package default — the package renders your copy when it exists
and its own otherwise. To revert a view to the shipped default, just **delete the
published file**; no config toggle is involved. (The one exception to the layout
is `resources/views/errors/503.blade.php` — Laravel dictates that path for the
maintenance response; it's a thin shim that renders `hold::maintenance`, so you
edit `maintenance.blade.php`, not the shim.)

The prelaunch and maintenance pages are self-contained single Blade files with
inline CSS and **no build step** — they render even when your app is half-broken.
Each declares four CSS custom properties at the top for a three-line reskin:

```css
:root {
    --hold-bg: #f5f6f8;
    --hold-card-bg: #ffffff;
    --hold-text: #1a1d24;
    --hold-accent: #2563eb;
}
```

The announcement email template lives alongside them at
`mail/announcement.blade.php` — see
[Customizing the announcement emails](#customizing-the-announcement-emails) for
its palette and copy model.

## How maintenance integration works (container binding)

The package binds a subclass of Laravel's
`Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance` in the
container. Wherever Laravel resolves that framework middleware, it gets the
subclass, which merges the package's route URIs (built from `routes.prefix`) into
the maintenance `except` list — so the signup/preview routes stay
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
| `jamesgifford:hold:enable {mode}` | Activate a hold — `prelaunch` or `maintenance` (refuses if one is already active). |
| `jamesgifford:hold:disable` | Deactivate whichever hold is active; optionally auto-announce. |
| `jamesgifford:hold:announce` | Email the launch/restore announcement (`--context`, `--dry-run`). |

## Testing

```bash
composer install
composer test          # vendor/bin/pest
composer format        # vendor/bin/pint
```

## License

MIT © James Gifford
