<?php

declare(strict_types=1);

use JamesGifford\Hold\Notifications\LaunchAnnouncement;
use JamesGifford\Hold\Notifications\ServiceRestored;
use JamesGifford\Hold\Notifications\SignupReceipt;
use JamesGifford\Hold\Notifications\TeamHoldEnabled;

it('merges every documented config default', function () {
    $config = config('jamesgifford.hold');

    expect($config)->toBeArray();

    expect($config['routes'])->toMatchArray([
        'register' => true,
        'prefix' => 'hold',
        'middleware' => ['web'],
    ]);

    expect($config['prelaunch'])->toMatchArray([
        'status_code' => 200,
        'bypass_cookie_name' => 'hold_bypass',
        'bypass_cookie_lifetime_days' => 30,
    ]);

    expect($config['notifications'])->toMatchArray([
        'team_addresses' => [],
        'send_signup_receipt' => false,
        'auto_announce_on_up' => false,
        'announce_delay_minutes' => 10,
    ]);

    expect($config['notifications']['classes'])->toMatchArray([
        'team_hold_enabled' => TeamHoldEnabled::class,
        'launch_announcement' => LaunchAnnouncement::class,
        'service_restored' => ServiceRestored::class,
        'signup_receipt' => SignupReceipt::class,
    ]);

    expect($config['spam'])->toMatchArray([
        'rate_limit_per_minute' => 5,
        'honeypot_field' => 'website',
    ]);

    expect($config['mail']['from'])->toMatchArray([
        'address' => null,
        'name' => null,
    ]);

    expect($config['models'])->toMatchArray([
        'namespace' => 'App\\Models\\Hold',
        'path' => 'app/Models/Hold',
    ]);
});
