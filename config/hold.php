<?php

declare(strict_types=1);

use JamesGifford\Hold\Notifications\HoldSignupReceipt;
use JamesGifford\Hold\Notifications\LaunchAnnouncement;
use JamesGifford\Hold\Notifications\ServiceRestored;
use JamesGifford\Hold\Notifications\SignupVerification;
use JamesGifford\Hold\Notifications\TeamHoldEnabled;

return [

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The package's HTTP surface: the signup and preview routes.
    | These stay reachable in both hold modes (prelaunch middleware allows them
    | through; the maintenance except-merge subclass adds them to Laravel's
    | maintenance bypass list). Set `register` to false to own routing entirely
    | and load the published routes stub yourself.
    |
    */

    'routes' => [

        // Master switch. false = the package registers no routes (publish the
        // routes stub and wire them yourself). The maintenance except-merge and
        // prelaunch allow-list still honor `prefix` so your own routes match.
        'register' => true,

        // URI prefix for every package route, e.g. 'hold' => /hold/signup.
        'prefix' => 'hold',

        // Middleware group applied to the package routes. 'web' gives you the
        // session + CSRF stack the Blade forms need.
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prelaunch mode
    |--------------------------------------------------------------------------
    |
    | Prelaunch ("coming soon") is a package-owned mode toggled by a flag file
    | in storage/jamesgifford/hold/. While active, PrelaunchMode middleware
    | intercepts every request (except package routes, the `except` list
    | below, and holders of a valid bypass cookie) and renders the prelaunch
    | view.
    |
    */

    'prelaunch' => [

        // HTTP status returned with the prelaunch page. 503 (the default)
        // tells every machine client that gets held — an unlisted webhook,
        // an uptime check, an API consumer — that the request FAILED and
        // should be retried; a 200 would read as a successful delivery, and
        // a webhook sender would never retry. 200 makes the page indexable
        // and shareable as "coming soon" content, but list your webhook
        // endpoints under `except` first. Only 200 or 503 are supported;
        // anything else falls back to 503.
        'status_code' => 503,

        // Seconds sent as the Retry-After header with a 503 prelaunch
        // response, telling crawlers and well-behaved clients when to come
        // back. Never sent with a 200. A null or 0 value omits the header.
        'retry_after' => 3600,

        // Name of the cookie that lets a browser bypass the prelaunch page and
        // see the real app (set by the signed /preview route).
        'bypass_cookie_name' => 'hold_bypass',

        // How long the bypass cookie lasts, in days.
        'bypass_cookie_lifetime_days' => 30,

        // Whether an active prelaunch hold is actually enforced while
        // APP_ENV is `testing`. Defaults to false: the flag file lives under
        // storage_path(), which resolves to the same physical directory
        // regardless of environment, so a hold left on from local browsing
        // would otherwise silently intercept every request your own test
        // suite makes. PrelaunchMode no-ops in that case instead (logging a
        // warning). Set this to true if you deliberately want a test to see
        // the holding page.
        'enforce_in_testing' => false,

        // Force prelaunch mode on via an environment variable instead of the
        // flag file — for ephemeral hosting (e.g. Laravel Cloud) where local
        // disk does not survive a deploy, an env var does. Set
        // JAMESGIFFORD_HOLD_PRELAUNCH_ENABLED=true to activate; leave it unset (or
        // false) to control prelaunch with the `jamesgifford:hold:enable
        // prelaunch` / `:disable` commands as usual. Toggling this requires
        // a redeploy — it CANNOT be turned off from the console (`:disable`
        // will say so and exit non-zero). Read via env() HERE, in the
        // config file, never at runtime elsewhere: config:cache would
        // otherwise bake in a stale value from whatever the cache-building
        // process saw.
        'forced' => (bool) env('JAMESGIFFORD_HOLD_PRELAUNCH_ENABLED', false),

        // Cache store that persists the prelaunch bypass token while
        // prelaunch is forced on via JAMESGIFFORD_HOLD_PRELAUNCH_ENABLED (the flag
        // file is what's unreliable on ephemeral hosting, so the token
        // can't live there either). Null uses config('cache.default').
        // Ignored entirely in command-driven (flag file) mode, where the
        // token is still the file's own contents. Point this at a store
        // backed by persistent storage (e.g. 'database', 'redis') — an
        // 'array' or 'null' store loses the token on every deploy,
        // defeating the point; `jamesgifford:hold:preview` warns when it
        // detects one.
        'token_store' => env('JAMESGIFFORD_HOLD_PRELAUNCH_TOKEN_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance mode
    |--------------------------------------------------------------------------
    |
    | Options that apply when maintenance mode is enabled THROUGH Hold
    | (`jamesgifford:hold:enable maintenance`). A bare `php artisan down` does
    | not read this config — pass the equivalent flags to it directly.
    |
    */

    'maintenance' => [

        // Seconds passed as `down`'s --retry option, echoed back as the
        // response's Retry-After header — tells crawlers/uptime checks when
        // to come back and protects search-index standing during an
        // extended outage. `--retry` on `jamesgifford:hold:enable
        // maintenance` overrides this. A null or 0 value omits the flag
        // (and so the header) entirely.
        'retry_after' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded requests
    |--------------------------------------------------------------------------
    |
    | Requests that always pass straight through BOTH hold modes — prelaunch
    | and maintenance — and reach your app as if no hold were active. Meant
    | for machine-to-machine endpoints that must never see a holding page:
    | payment webhooks (Stripe/Cashier, Paddle), OAuth callbacks, health
    | checks. A webhook answered with the holding page is lost: the sender
    | either records it as delivered or retries into the same page.
    |
    | The package's own routes (`routes.prefix`) are always excluded and
    | need no entry here. Maintenance exclusions apply however the app went
    | down — `jamesgifford:hold:enable maintenance` or a bare `artisan down`.
    |
    */

    'except' => [

        // URI patterns, matched exactly like Laravel's own maintenance/CSRF
        // `except` lists: a path relative to the app root (leading/trailing
        // slashes ignored), `*` as a wildcard, or a full URL.
        // e.g. ['stripe/webhook', 'webhooks/*']
        'paths' => [],

        // Route names, `*` as a wildcard. Holds run before routing, so each
        // named route is matched against the request directly — URI, HTTP
        // method and domain all have to fit. Paths are the cheaper check
        // when you have the choice.
        // e.g. ['cashier.webhook', 'webhooks.*']
        'routes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Appearance
    |--------------------------------------------------------------------------
    |
    | Set any of these once here to apply it across every holding page and
    | mail template — each is resolved through JamesGifford\Hold\Hold::
    | appearance(), tiered this template's own $property (set directly in the
    | published file, always wins) > the matching 'pages'/'mail' value below >
    | the shared value directly under this key > the package's own automatic
    | derivation (see JamesGifford\Hold\Support\ColorTheme), which is
    | completely unchanged by this section — these are new defaults ahead of
    | it, not a replacement for it.
    |
    */

    'appearance' => [

        // Shared: applies to both the holding pages and the mail templates,
        // unless the more specific 'pages'/'mail' value below is set. null
        // means "no override" for everything except 'bg', which always
        // needs a concrete color.
        'bg' => '#f5f6f8',
        'accent' => null,
        'text' => null,
        'card' => null,
        'card_blend_weight' => null,
        'input_bg' => null,
        'input_border' => null,
        'card_shadow_color' => null,
        'alert_success_bg' => null,
        'alert_success_text' => null,
        'alert_error_bg' => null,
        'alert_error_text' => null,
        'muted' => null,
        'muted_blend_weight' => null,

        // Scopes the shared values above to prelaunch.blade.php /
        // maintenance.blade.php only. No muted/muted_blend_weight — those
        // are mail-only, the pages have no equivalent.
        'pages' => [
            'bg' => null,
            'accent' => null,
            'text' => null,
            'card' => null,
            'card_blend_weight' => null,
            'input_bg' => null,
            'input_border' => null,
            'card_shadow_color' => null,
            'alert_success_bg' => null,
            'alert_success_text' => null,
            'alert_error_bg' => null,
            'alert_error_text' => null,
        ],

        // Scopes the shared values above to announcement.blade.php /
        // team.blade.php / receipt.blade.php only. No input_bg/
        // input_border/card_shadow_color/alert_* — page-only, no form or
        // alert UI in a one-way notification email.
        'mail' => [
            'bg' => null,
            'accent' => null,
            'text' => null,
            'card' => null,
            'card_blend_weight' => null,
            'muted' => null,
            'muted_blend_weight' => null,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Outbound email behavior. The class names below are resolved at send time,
    | so you can point any of them at your own Notification subclass to fully
    | customize copy and channels without touching the package.
    |
    */

    'notifications' => [

        // Addresses that receive the "hold enabled" team notice when either
        // mode begins. Empty = no team notice is sent.
        'team_addresses' => [],

        // Send a one-off "you're on the list" receipt to each new signup.
        'send_signup_receipt' => false,

        // When a hold mode ends, automatically dispatch the announcement job
        // (delayed by announce_delay_minutes) instead of waiting for a manual
        // `jamesgifford:hold:announce`.
        'auto_announce_on_up' => false,

        // Delay, in minutes, before an auto-dispatched announcement actually
        // sends. This is the "change of mind" window: if the mode is re-enabled
        // within it, the delayed job aborts silently without emailing anyone.
        //
        // The window needs a queue that can defer work. Laravel's `sync`
        // connection runs jobs inline and discards the delay, so when this is
        // above zero and the default connection is sync, auto-announce REFUSES
        // to dispatch and tells you to run jamesgifford:hold:announce yourself.
        // Set this to 0 if an immediate send on a sync queue is what you want.
        'announce_delay_minutes' => 10,

        // Subject lines for the two announcements and the verification email.
        // The body copy lives in the published email templates
        // (resources/views/vendor/hold/mail/), but the subjects are set here
        // so you can adjust them without republishing a view.
        'subject_launch' => 'We\'re live!',
        'subject_restored' => 'We\'re back online',
        'subject_verify' => 'Confirm your email address',

        // Notification classes. Override any with your own subclass FQCN.
        'classes' => [
            'team_hold_enabled' => TeamHoldEnabled::class,
            'launch_announcement' => LaunchAnnouncement::class,
            'service_restored' => ServiceRestored::class,
            'signup_receipt' => HoldSignupReceipt::class,
            'signup_verification' => SignupVerification::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Email verification
    |--------------------------------------------------------------------------
    |
    | Double opt-in: a new signup must click a link in the verification email
    | before the announcer will ever email it. Protects against one person
    | signing another's address up without their knowledge.
    |
    */

    'verification' => [

        // Require verification before an address is emailed. When false, a
        // signup is stamped verified at capture time instead — no address is
        // ever left permanently unreachable, whichever way this is set.
        'required' => true,

        // How many days a verification link stays valid. An unverified
        // signup can always re-submit the form for a fresh one.
        'link_lifetime_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    |
    | Optional From overrides for package mail. Null falls back to the app's
    | mail.from configuration.
    |
    */

    'mail' => [
        'from' => [
            // Envelope From address for package mail. Null uses config('mail.from.address').
            'address' => null,

            // Envelope From name for package mail. Null uses config('mail.from.name').
            'name' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Spam protection
    |--------------------------------------------------------------------------
    |
    | Lightweight, JS-free defenses for the public signup form.
    |
    */

    'spam' => [

        // Maximum signup submissions per minute, per IP address.
        'rate_limit_per_minute' => 5,

        // Name of the honeypot field rendered (hidden via CSS) in the forms.
        // A submission with this field filled is treated as a bot: it returns
        // success silently and stores nothing.
        'honeypot_field' => 'website',
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | The HoldSignup model is PUBLISHED into the host app (the app owns it). These
    | values tell the package where it lives and which class to resolve, so the
    | setup command can publish it to the right place and the runtime can find
    | it. Point `signup` at your own subclass to customize behavior.
    |
    | Whatever `signup` names must implement
    | JamesGifford\Hold\Contracts\HoldSignupContract — the published model does,
    | and so does any subclass of it. A class that exists but does not implement
    | it raises an exception rather than being silently replaced.
    |
    */

    'models' => [

        // FQCN the package resolves for signup records. Defaults to the
        // published location below. The class basename (HoldSignup) is also the
        // published filename.
        'signup' => 'App\\Models\\HoldSignup',

        // Where `jamesgifford:hold:setup` publishes the model. `namespace` and
        // `path` must correspond (PSR-4). `path` is relative to the app base.
        'namespace' => 'App\\Models',
        'path' => 'app/Models',
    ],

];
