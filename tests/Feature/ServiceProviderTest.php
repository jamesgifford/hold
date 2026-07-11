<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use JamesGifford\Hold\Console\Commands\AnnounceCommand;
use JamesGifford\Hold\Console\Commands\DisableCommand;
use JamesGifford\Hold\Console\Commands\EnableCommand;
use JamesGifford\Hold\Console\Commands\SetupCommand;
use JamesGifford\Hold\Console\Commands\UninstallCommand;
use JamesGifford\Hold\Console\Commands\UnsubscribeCommand;
use JamesGifford\Hold\HoldServiceProvider;

it('boots the service provider and merges package config', function () {
    expect(app()->getProvider(HoldServiceProvider::class))->not->toBeNull();

    // Config is merged under the jamesgifford.hold namespace with defaults.
    expect(config('jamesgifford.hold'))->toBeArray()
        ->and(config('jamesgifford.hold.routes.prefix'))->toBe('hold')
        ->and(config('jamesgifford.hold.prelaunch.status_code'))->toBe(200);
});

it('registers all six namespaced commands', function () {
    $commands = collect(app(Kernel::class)->all());

    expect($commands)->toHaveKeys([
        'jamesgifford:hold:setup',
        'jamesgifford:hold:uninstall',
        'jamesgifford:hold:enable',
        'jamesgifford:hold:disable',
        'jamesgifford:hold:announce',
        'jamesgifford:hold:unsubscribe',
    ]);

    expect($commands->get('jamesgifford:hold:setup'))->toBeInstanceOf(SetupCommand::class)
        ->and($commands->get('jamesgifford:hold:uninstall'))->toBeInstanceOf(UninstallCommand::class)
        ->and($commands->get('jamesgifford:hold:enable'))->toBeInstanceOf(EnableCommand::class)
        ->and($commands->get('jamesgifford:hold:disable'))->toBeInstanceOf(DisableCommand::class)
        ->and($commands->get('jamesgifford:hold:announce'))->toBeInstanceOf(AnnounceCommand::class)
        ->and($commands->get('jamesgifford:hold:unsubscribe'))->toBeInstanceOf(UnsubscribeCommand::class);
});
