<?php

declare(strict_types=1);

use JamesGifford\Hold\Notifications\HoldSignupReceipt;
use JamesGifford\Hold\Notifications\LaunchAnnouncement;
use JamesGifford\Hold\Notifications\ServiceRestored;
use JamesGifford\Hold\Notifications\TeamHoldEnabled;

return [

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The package's HTTP surface: the signup, unsubscribe, and preview routes.
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
    | intercepts every request (except package routes and holders of a valid
    | bypass cookie) and renders the prelaunch view.
    |
    */

    'prelaunch' => [

        // HTTP status returned with the prelaunch page. 200 keeps the page
        // fully indexable ("coming soon" is legitimate content); 503 signals
        // "not yet available" to crawlers and uptime checks. Only 200 or 503
        // are supported.
        'status_code' => 200,

        // Name of the cookie that lets a browser bypass the prelaunch page and
        // see the real app (set by the signed /preview route).
        'bypass_cookie_name' => 'hold_bypass',

        // How long the bypass cookie lasts, in days.
        'bypass_cookie_lifetime_days' => 30,
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
        'announce_delay_minutes' => 10,

        // Subject lines for the two announcements. The body copy lives in the
        // published email templates (resources/views/vendor/hold/mail/), but the
        // subjects are set here so you can adjust them without republishing a view.
        'subject_launch' => 'We\'re live!',
        'subject_restored' => 'We\'re back online',

        // Notification classes. Override any with your own subclass FQCN.
        'classes' => [
            'team_hold_enabled' => TeamHoldEnabled::class,
            'launch_announcement' => LaunchAnnouncement::class,
            'service_restored' => ServiceRestored::class,
            'signup_receipt' => HoldSignupReceipt::class,
        ],
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
            'address' => null,
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
    | it. Point `class` at your own subclass to customize behavior.
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
