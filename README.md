# Hold

[![Tests](https://github.com/jamesgifford/hold/actions/workflows/tests.yml/badge.svg)](https://github.com/jamesgifford/hold/actions/workflows/tests.yml)
[![Static Analysis & Code Style](https://github.com/jamesgifford/hold/actions/workflows/code-style.yml/badge.svg)](https://github.com/jamesgifford/hold/actions/workflows/code-style.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

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
the `HoldSignup` model are **published into your app** — you own them. Integration
is container-only: nothing edits `bootstrap/app.php` or any other core file, so
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

> ⚠️ **Single-server limitation:** the flag file lives on local disk
> (`storage/jamesgifford/hold/`), not in a shared store. On a multi-server
> deployment without shared storage for that path, enabling prelaunch on one
> server does not affect the others — they'll keep serving the real app. Route
> `enable`/`disable` through a single node, or point `storage/jamesgifford/hold/`
> at shared storage, if you run more than one app server.

### Maintenance

`enable maintenance` runs Laravel's native `php artisan down` for you (with a
generated `--secret`) and prints the secret bypass link (`/{secret}`); `disable`
runs `php artisan up`. Laravel's maintenance mode is the **untouched underlying
mechanism** — Hold just manages it, keeps its own routes reachable so the capture
form works, and records signups with the `maintenance` context. When
`auto_announce_on_up` is enabled, `up` schedules the "we're back" announcement.

Maintenance mode's own storage — and so its multi-server behavior — is
whatever your app's `APP_MAINTENANCE_DRIVER` is configured to (Laravel
supports `file` and `cache` drivers). Hold doesn't set or override that
choice; if you run more than one app server, point it at a `cache` driver
backed by a shared store, same as you would for a bare `artisan down`.

**Native `down`/`up` still work directly** (deploy tooling often calls them), which
bypasses Hold's one-hold check — so Hold **self-heals**: if prelaunch is active
when maintenance comes up natively, Hold automatically disables prelaunch (logging
an informational line) so only one hold is ever active.

**Retry-After.** `enable maintenance` passes `maintenance.retry_after` (default
`3600` seconds) through to `down --retry`, which Laravel echoes back as the
response's `Retry-After` header — this tells crawlers and uptime checks when to
come back and protects your search-index standing during an extended outage.
Override it per-run with `--retry=<seconds>`; either source set to `0` (or the
config set to `null`) omits the flag, and so the header, entirely. This **only
applies when maintenance is enabled through Hold** — a bare `php artisan down`
needs `--retry` passed manually.

> ⚠️ **Deploy caveat:** a deploy script that wraps the deploy in `artisan down`/`up`
> will therefore knock the app **out of prelaunch** via self-heal. During the
> pre-launch phase, either skip `down`/`up` in your deploy script or re-enable
> prelaunch (`jamesgifford:hold:enable prelaunch`) as the final deploy step.

The maintenance page also ships a `<meta name="robots" content="noindex, nofollow">`
tag — defense-in-depth behind the 503 status, in case a crawler somehow indexes
the page anyway. The prelaunch page deliberately does **not** get this; it's
meant to be indexable (see [Prelaunch sharing metadata](#prelaunch-sharing-metadata)).

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

> ⚠️ **Auto-announce needs a real queue.** Laravel's `sync` connection runs jobs
> inline and throws the delay away, so the change-of-mind window would be zero
> and the mass email would go out — unrecallably — the instant the hold ended.
> When `announce_delay_minutes` is above zero and the default queue connection is
> `sync`, Hold **refuses to dispatch**, logs a warning, and tells you to run
> `jamesgifford:hold:announce` yourself. Set `announce_delay_minutes` to `0` if
> you genuinely want an immediate send on a sync queue.
>
> You also need a **worker actually running** (`php artisan queue:work`) for a
> delayed job to fire at all — Hold can check the connection, not your workers.

### Customizing the announcement emails

Every package email renders through a **self-contained HTML template** with
inline styles and **no dependency on Laravel's mail markdown layout** (no theme,
no build step). Setup publishes three, one per email, that you own and edit:

| Template | Email |
| --- | --- |
| `resources/views/vendor/hold/mail/announcement.blade.php` | launch & restore announcements |
| `resources/views/vendor/hold/mail/team.blade.php` | the internal "hold enabled" team notice |
| `resources/views/vendor/hold/mail/receipt.blade.php` | the optional signup receipt |

The package falls back to its own copy of each until you publish. Two levels of
control:

**1. Edit colors, header, and copy — edit the published template.** Everything
a developer changes lives in plain-PHP variable blocks at the very top of each
file (email clients support CSS custom properties poorly, so these stay plain
PHP variables interpolated into the inline styles — unlike the holding pages,
which use CSS custom properties). **Colors** — a five-value palette, same
`JamesGifford\Hold\Support\ColorTheme` math the holding pages use: set `$bg`
alone and `$accent`/`$text`/`$card`/`$muted` all derive automatically (a
hue-matched accent, light/dark text by WCAG contrast, a card background
blended from `$bg` toward `$text`, and muted/footnote text blended from `$bg`
toward `$text` at a lower weight so it reads as de-emphasized). Set any of the
four directly for full manual control of just that one value:

```php
$bg = null;      // set to override the config default; falls back to #f5f6f8
$accent = null;  // set to override the automatic hue-matched derivation
$text = null;    // set to override the automatic light/dark derivation
$card = null;    // set to override the automatic card-background blend
$muted = null;   // set to override the automatic secondary/footnote-text blend
```

`$cardBlendWeight` and `$mutedBlendWeight` (both default to the matching
`ColorTheme` constant) tune how strongly `$card`/`$muted` depart from `$bg`
while left to auto-derive — same convention as the holding pages'
`$cardBlendWeight`.

Before falling back to the `ColorTheme` derivation, every one of these left
`null` first checks `config('jamesgifford.hold.appearance.*')` — see
[Appearance](#appearance). That's how to rebrand every email at once without
touching these three files: set `appearance.bg`/`appearance.accent` (etc.) in
config, or scope a value to just the mail templates with
`appearance.mail.bg`, leaving the holding pages at whatever their own default
is.

**Copy** — a `$copy` block holding every string. The announcement template keys
it by hold mode; edit the wording (and the button label/URL) in place:

```php
$copy = [
    'prelaunch'   => ['heading' => 'We\'ve launched!', 'body' => '…', 'button' => 'Take a look', 'url' => config('app.url')],
    'maintenance' => ['heading' => 'We\'re back!',     'body' => '…', 'button' => 'Return to the site', 'url' => config('app.url')],
];
```

**Subjects** stay out of the templates so you can tweak them without
republishing a view — set `notifications.subject_launch` and
`notifications.subject_restored` in config.

**Add a header (logo or wordmark).** A third variable block (in every email
template) just below the palette drives an optional header above the heading,
with three modes:

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

> **Existing installs:** a template published before these features won't have
> the header or `$copy` blocks. Re-publish it (delete your copy and re-run setup,
> or `vendor:publish --tag=jamesgifford-hold-views --force`) or hand-add the
> blocks from the package copy — there is no automatic merge.

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
| `maintenance.retry_after` | `3600` | Seconds sent as the `Retry-After` header when maintenance is enabled via `enable maintenance` (`--retry` overrides; `0`/`null` omits it). Only applies to holds enabled through Hold — a bare `artisan down` needs `--retry` passed manually. |
| `appearance.*` | see [Appearance](#appearance) | Set colors once for every template, or scope them to just the holding pages or just the mail templates. |
| `notifications.team_addresses` | `[]` | Who receives the "hold enabled" notice. |
| `notifications.send_signup_receipt` | `false` | Email each new signup a receipt. |
| `notifications.auto_announce_on_up` | `false` | Auto-schedule the announcement when a hold ends. |
| `notifications.announce_delay_minutes` | `10` | Change-of-mind delay before an auto-announce sends. |
| `notifications.subject_launch` | `We're live!` | Subject of the launch announcement (body copy lives in the template). |
| `notifications.subject_restored` | `We're back online` | Subject of the restore announcement (body copy lives in the template). |
| `mail.from.address` / `mail.from.name` | `null` | From override (falls back to app defaults). |
| `spam.rate_limit_per_minute` | `5` | Per-IP signup rate limit. |
| `spam.honeypot_field` | `website` | Hidden honeypot field name. |
| `models.signup` | `App\Models\HoldSignup` | Resolved HoldSignup model. |
| `models.namespace` / `models.path` | `App\Models` / `app/Models` | Where setup publishes the model (`HoldSignup.php`). |

### Appearance

Every color/derivation-weight below is resolved through
`JamesGifford\Hold\Hold::appearance()`, tiered **this template's own PHP
variable (set directly in the published file, always wins) → the matching
`pages`/`mail` value → the shared value directly under `appearance` → the
package's own automatic derivation** (see [Customizing the
views](#customizing-the-views) / [Customizing the announcement
emails](#customizing-the-announcement-emails)) — completely unchanged by
this section, just given a new default ahead of it. Each property lives at
`appearance.<property>` (applies everywhere), and can be scoped to just one
template family with `appearance.pages.<property>` or
`appearance.mail.<property>` — for example `appearance.bg`,
`appearance.pages.bg`, `appearance.mail.bg`.

| Property | Shared default | Applies to |
| --- | --- | --- |
| `bg` | `#f5f6f8` | pages, mail |
| `accent` | `null` | pages, mail |
| `text` | `null` | pages, mail |
| `card` | `null` | pages, mail |
| `card_blend_weight` | `null` | pages, mail |
| `input_bg` | `null` | pages only |
| `input_border` | `null` | pages only |
| `card_shadow_color` | `null` | pages only |
| `alert_success_bg` / `alert_success_text` | `null` | pages only |
| `alert_error_bg` / `alert_error_text` | `null` | pages only |
| `muted` | `null` | mail only |
| `muted_blend_weight` | `null` | mail only |

`null` means "no override at this tier" for every property except `bg`,
which always needs a concrete color to start the rest of the derivation
from.

> **Existing installs:** this config tier only wins where the published
> template leaves its own PHP variable at `null`. A template published
> before this section existed set `$bg` (and friends) to a literal default
> (e.g. `$bg = '#f5f6f8';`), not `null` — tier 1 (the template's own
> variable) always wins, so `appearance.*` config is silently ignored for
> that published copy. Re-publish the affected template (delete your copy
> and re-run setup, or `vendor:publish --tag=jamesgifford-hold-views
> --force`) to pick up config-driven appearance, or hand-edit the variable
> to `null` yourself.

### Publish tags

`jamesgifford:hold:setup` publishes everything for you; these tags exist for
re-publishing a single asset group with `vendor:publish`:

| Tag | Publishes |
| --- | --- |
| `jamesgifford-hold-config` | `config/jamesgifford/hold.php` |
| `jamesgifford-hold-models` | `app/Models/HoldSignup.php` |
| `jamesgifford-hold-views` | the holding pages, email templates, and the `errors/503.blade.php` shim |
| `jamesgifford-hold-routes` | `routes/hold.php` (the self-hosted routes stub) |

Note `vendor:publish --tag=jamesgifford-hold-models` publishes the model
**verbatim**, without the namespace rewrite — use `jamesgifford:hold:setup` for
that. The migration is not a publish tag at all; setup owns it, because it needs
a fresh publish-time timestamp.

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

### The signup model contract

Whatever `models.signup` points at must implement
`JamesGifford\Hold\Contracts\HoldSignupContract` — it declares `unsubscribe()`,
`resubscribe()`, and the columns the package reads. The published
`App\Models\HoldSignup` implements it out of the box (setup writes it in), and so
does any subclass of it. This is what lets the package resolve an app-owned class
it has no static relationship with; a configured class that exists but does not
implement the contract raises a clear exception rather than silently falling back
to the package's own model.

## Customizing the views

Every package view is published into Laravel's standard vendor-views location so
you own an editable copy:

```
resources/views/vendor/hold/
├── prelaunch.blade.php
├── maintenance.blade.php
└── mail/
    ├── announcement.blade.php
    ├── team.blade.php
    └── receipt.blade.php
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
**Colors** are set from PHP variables at the top of the file (the Palette
section of the `@php` block), the same pattern the email templates already
use — not CSS literals, because picking a readable text color for an
arbitrary background needs real branching logic (compare WCAG contrast
against a light and a dark candidate, keep whichever is higher), and
deriving a good accent needs even more (match the background's hue, then
darken it until it clears WCAG contrast against the submit button's fixed
white label) — there's no broadly-supported CSS function that can do either
yet:

```php
$bg = null;                // set to override the config default; falls back to #f5f6f8

$accent = null;            // set to override the automatic hue-matched derivation
$text = null;              // set to override the automatic light/dark derivation
$cardBg = null;            // set to override the automatic card-background blend
$inputBg = null;           // set to override; defaults to $bg (the "cutout" look)
$inputBorder = null;       // set to override the automatic border-color blend
$cardShadowColor = null;   // set to override the automatic light-shadow/dark-glow choice
$alertSuccessBg = null;    // set to override the automatic success-alert background blend
$alertSuccessText = null;  // set to override the automatic success-alert text derivation
$alertErrorBg = null;      // set to override the automatic error-alert background blend
$alertErrorText = null;    // set to override the automatic error-alert text derivation

$cardBlendWeight = \JamesGifford\Hold\Support\ColorTheme::CARD_BLEND_WEIGHT;
// ^ 0-1; how strongly $cardBg blends toward $text when auto-deriving.
//   Only applies while $cardBg above is left null.
```

Set `$bg` and the rest **derive automatically**: `$accent` matches `$bg`'s
hue at a fixed, deliberately vibrant saturation/lightness, darkened as
needed so it stays legible on the submit button's fixed white label
(`JamesGifford\Hold\Support\ColorTheme::accentFor()`) — so it can't clash
with `$bg` the way an unrelated, independently-chosen color can; `$text`
switches between a dark and a light default depending on which gives better
contrast against `$bg` (so a dark `$bg` gets light text, not just a repaint
of the light-mode default); `$cardBg` is a subtle blend of `$bg` toward
`$text`, so the card reads as a distinct surface in both a light and a dark
theme; `$inputBg` defaults to `$bg` exactly, so the email field still reads
as a cutout showing the page behind the card.

`$inputBorder` blends toward `$text` (not a fixed black) at
`ColorTheme::INPUT_BORDER_BLEND_WEIGHT` (`0.17`), so the border stays a
subtle edge on a light `$inputBg` and a clearly visible one on a dark
`$inputBg` — a fixed `rgba(0, 0, 0, 0.15)` border nearly disappears against
a dark background. `$cardShadowColor` picks whichever of pure black or
white contrasts more with `$bg`, so a dark `$bg` gets a light "glow"
instead of an invisible black-on-black shadow. `$alertSuccessBg` and
`$alertErrorBg` tint `$cardBg` — not `$bg` — toward a fixed semantic hue
(green/red), since the alert renders inside the card, so `$cardBg` is its
real backdrop; the blend weight matches the shipped default's previous
`rgba()` alpha exactly, so the default appearance doesn't move.
`$alertSuccessText` and `$alertErrorText` each pick whichever of a
light-mode/dark-mode text candidate contrasts more with that actual
composited alert background, so alert text stays legible on both a light
and a dark card.

These eleven land in `:root {}` as the same `--hold-bg` / `--hold-card-bg`
/ `--hold-text` / `--hold-accent` / `--hold-input-bg` / `--hold-input-border`
/ `--hold-card-shadow-color` / `--hold-alert-success-bg` /
`--hold-alert-success-text` / `--hold-alert-error-bg` /
`--hold-alert-error-text` custom properties every rule in the stylesheet
already reads — editing them there directly has no effect, since Blade
re-writes them from the PHP variables on every render.

`$cardBlendWeight` tunes *how strongly* `$cardBg` departs from `$bg` — the
default (`ColorTheme::CARD_BLEND_WEIGHT`, `0.12`) is deliberately subtle;
raise it for a more visibly distinct card, lower it to sit closer to `$bg`.
It's read only while `$cardBg` is left to auto-derive — set `$cardBg`
directly and this has no effect.

**Full manual control is never lost.** `$bg` is a plain, independently-set
value. `$accent`, `$text`, `$cardBg`, `$inputBg`, `$inputBorder`,
`$cardShadowColor`, `$alertSuccessBg`, `$alertSuccessText`,
`$alertErrorBg`, and `$alertErrorText` each default to `null` ("derive
automatically"); set any one of them to a real hex value instead and that
value wins outright, no derivation runs for that property — a per-property
escape hatch, not an all-or-nothing toggle.

**Before any of the above auto-derives, it checks config first.** Every
property left `null` calls `JamesGifford\Hold\Hold::appearance()`, which
checks `config('jamesgifford.hold.appearance.*')` before falling back to the
`ColorTheme` derivation shown above — see [Appearance](#appearance). That's
the "set once for every template" path: set `appearance.bg` to rebrand the
holding pages *and* the mail templates from one place, or scope it to just
one family with `appearance.pages.bg` — leaving `appearance.mail` (and so
the emails) at whatever it would otherwise be, and vice versa. A value set
directly in this file, as above, still wins over both.

The color math itself lives in `JamesGifford\Hold\Support\ColorTheme` (WCAG
relative luminance and contrast ratio — including a shared
`betterContrast()` helper that picks whichever of two named candidate
colors contrasts more with a background, driving the text/shadow/alert-text
choices — HSL hue extraction/reconstruction for the accent, plus a simple
per-channel blend for the card, border, and alert backgrounds) — plain,
framework-free PHP, unit tested independently of the templates.

**Layout** is two more custom properties alongside the color knobs:
`--hold-content-width` (default `65ch`) sets the card's max-width in reading
terms — roughly 60-70 characters per line at the base font size — rather than
an arbitrary pixel value; `--hold-space` (default `1.25rem`) is the single
spacing value that drives the vertical rhythm between the card's stacked
elements (`.hold-card > * + *`), so the page composes cleanly no matter which
optional elements — the alert, and on the maintenance page eta/apology/contact
— are present at any given moment.

**Copy** is a `$copy` block beside them holding *every* user-visible string —
title, heading, sub-text, the email field's label/placeholder, the button, the
privacy note, and both form-state messages (the `success` line shown after a
signup and the `invalid` line for a bad email). Both state messages live in the
block so you can reword them in one place without triggering them; edit any line
and it renders. Each page is self-contained, so the strings the two pages share
are duplicated per file by design — there is no shared copy include.

**The maintenance page has three extra `$copy` strings** beyond the shared
set: `eta` (a plain-language return estimate, e.g. "We expect to be back by
3pm PT"), `apology` (a brief acknowledgment that the downtime is
inconvenient — ships with default wording, since it's almost always wanted),
and `contact` (an optional line for urgent issues). Like the mail templates'
logo header, each renders — and adds spacing — only when non-empty; clear
any of the three to `''` and it disappears completely, spacing included.
**`contact` is the one `$copy` value rendered unescaped** (so it can carry a
raw `<a href="mailto:...">` link) — every other `$copy` string is
HTML-escaped automatically.

#### Prelaunch sharing metadata

The prelaunch page is meant to be found and shared, so it renders proper
`<title>`/`<meta name="description">` and Open Graph/Twitter Card tags,
driven by the same `$copy` values — `title` for `<title>`/`og:title` and
`lede` for the description/`og:description` (falling back to `config('app.name')`
and the title, respectively, if either is blank). `og:url` is the current
URL; `og:type` is always `website`. The maintenance page deliberately does
**not** get any of this — it has `noindex` instead (see above).

**`$ogImage`** is a variable at the top of the file (default `null`) for the
share-preview image. Like the email templates' `$logoUrl`, it **must be an
absolute, publicly reachable URL** — a local/relative path won't resolve for
a crawler or a chat app's link-preview fetcher — and the conventional size is
around 1200×630. Leave it `null` and `og:image` is omitted entirely (not
emitted empty) and `twitter:card` is `summary`; set it and `twitter:card`
becomes `summary_large_image`.

> **Existing installs:** a page template published before the layout
> variables (`--hold-content-width`, `--hold-space`), the maintenance
> `eta`/`apology`/`contact` `$copy` keys, or the prelaunch sharing-metadata
> block (`$ogImage` and friends) won't have them. Re-publish it (delete your
> copy and re-run setup, or `vendor:publish --tag=jamesgifford-hold-views
> --force`) or hand-add the pieces you want from the package copy — there is
> no automatic merge.

The email templates live alongside them under `mail/` — see
[Customizing the announcement emails](#customizing-the-announcement-emails) for
their palette, header/logo, and copy model.

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

**Dropping the table is always an explicit act.** If the run can't ask — `-n` /
`--no-interaction`, as CI and deploy scripts use — the published assets are still
removed but the table and its migration are **kept**, and the command says so.
Pass `--force` to drop without being asked. In production, `uninstall` refuses
outright without `--force`.

## Commands

| Command | Flags | Purpose |
| --- | --- | --- |
| `jamesgifford:hold:setup` | `--force`, `--migrate` | Publish config, migration, model, views; optionally migrate. |
| `jamesgifford:hold:uninstall` | `--force`, `--keep-data` | Remove everything published and drop the table (`--keep-data` to keep it). |
| `jamesgifford:hold:enable {mode}` | `--retry` | Activate a hold — `prelaunch` or `maintenance` (refuses if one is already active). `--retry=<seconds>` overrides `maintenance.retry_after` for a maintenance enable. |
| `jamesgifford:hold:disable` | — | Deactivate whichever hold is active; optionally auto-announce. |
| `jamesgifford:hold:announce` | `--context`, `--dry-run` | Email the launch/restore announcement. |
| `jamesgifford:hold:unsubscribe {email}` | `--resubscribe` | Operator tool: set or clear a signup's unsubscribe state. |

Neither `enable` nor `disable` is production-guarded — they are the normal way to
put a live site into and out of a hold. `setup` and `uninstall` are guarded, and
refuse to run in production without `--force`.

## Testing

One command runs every gate — code style, static analysis, and the suite against
both engines:

```bash
composer install
composer check
```

The individual gates, if you want them separately:

| Command | What it does |
| --- | --- |
| `composer lint` | Pint, check only |
| `composer format` | Pint, apply fixes |
| `composer analyse` | PHPStan level 6 (no baseline — findings get fixed, not recorded) |
| `composer test` | The suite against **MariaDB** |
| `composer test:sqlite` | The suite against SQLite |
| `composer test:parallel` | The suite in parallel (see note below) |

**The suite defaults to MariaDB**, because that is the deployment target and some
invariants only exist there — notably that `requested_at` never acquires an
implicit `ON UPDATE CURRENT_TIMESTAMP` on a server with
`explicit_defaults_for_timestamp=0`. SQLite cannot express that, so running only
on SQLite would mean asserting it rather than testing it.

`composer test:parallel` gives each worker its own schema *and* its own storage
tree, because prelaunch mode's source of truth is a flag file — without both,
workers read each other's holds and the run produces false failures. The
per-worker schemas are dropped automatically when the run finishes. On a suite
this size it is not meaningfully faster than serial; it exists to prove the
suite has no hidden shared state.

Point it at your own server with `DB_HOST` / `DB_PORT` / `DB_DATABASE` /
`DB_USERNAME` / `DB_PASSWORD` (defaults: `127.0.0.1:3306`, database `hold_test`,
user `root`, password `root`). `DB_CONNECTION=sqlite` skips the MariaDB-only
tests and needs no server. CI runs both.

## License

MIT © James Gifford
