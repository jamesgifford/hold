# Changelog

All notable changes to this package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-07-08

First stable release.

### Added

#### Prelaunch ("coming soon") mode
- Package-owned mode toggled by a flag file under `storage/jamesgifford/hold/`, independent of Laravel's maintenance mode and of config caching.
- `PrelaunchMode` global middleware that renders the holding page for every request while active (a near-zero-cost `is_file()` no-op otherwise), with a configurable HTTP status (`200` or `503`).
- Signed `/preview` route that sets a bypass cookie so the team can view the real app behind the page; the bypass is validated in global middleware by decrypting the cookie the way `EncryptCookies` would.
- `jamesgifford:hold:enable` / `jamesgifford:hold:disable` commands: production-guarded, idempotent, print a signed preview link, and wire the team notice / delayed auto-announce.

#### Maintenance-mode integration
- A published `resources/views/errors/503.blade.php` carrying the capture form.
- A container-bound subclass of `PreventRequestsDuringMaintenance` that merges the package's route URIs (from the configured prefix) into the maintenance `except` list, so the signup/unsubscribe/preview routes stay reachable during `php artisan down`. No host-app core file is modified; `composer remove` reverses it.

#### Signup capture
- `POST /{prefix}/signup` — honeypot + per-IP rate limiting, server-side context detection (prelaunch vs maintenance), silent success on duplicate/honeypot/throttle, CSRF-exempt with query-param feedback so it works where the holding pages render (before the session starts).
- Published `Signup` model (app-owned) with `notNotified` / `subscribed` / `context` scopes and a soft `unsubscribe()`. Signed one-click unsubscribe route.
- Published, timestamped `create_hold_signups_table` migration.

#### Notifications and announcements
- `TeamHoldEnabled`, `LaunchAnnouncement`, `ServiceRestored`, and optional `SignupReceipt` notifications, all sent via on-demand mail routes (no `User` model) and each overridable via config class names.
- `SendAnnouncement` job with a change-of-mind guard (aborts silently if the hold is active again when a delayed run fires), chunked sending, and `notified_at` stamping.
- `jamesgifford:hold:announce` command: immediate, idempotent, context inference, `--dry-run`.
- Optional auto-announce after a hold ends, delayed by `announce_delay_minutes`, wired to `php artisan up` and to prelaunch disable.

#### Install lifecycle
- `jamesgifford:hold:setup`: publishes config, migration (fresh publish-time timestamp), the Signup model (namespace rewritten to the app), and the views; creates the runtime storage directory; interactive review pause that later steps honor; idempotent re-runs; `--force` / `--migrate` for unattended installs.
- `jamesgifford:hold:uninstall`: removes every published asset and drops the table (with confirmation), `--keep-data` to keep the table and its migration. Setup → uninstall round-trips to a clean state.

#### Configuration
- Documented config covering routes, prelaunch, notifications, mail overrides, spam protection, and model resolution/publish location.
