<?php

declare(strict_types=1);

use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use JamesGifford\Hold\Models\HoldSignup;

/*
 * The package routes must survive `php artisan route:cache` — and with it
 * `php artisan optimize` — in a consuming app. The framework refuses to cache
 * a route table in which two routes share a name.
 */

it('gives every package route its own name', function () {
    expect(holdDuplicateRouteNames())->toBe([]);
});

it('survives route:cache with every package route still reachable by name', function () {
    holdCacheRoutes();

    expect(Route::getRoutes())->toBeInstanceOf(CompiledRouteCollection::class);

    foreach (['hold.signup', 'hold.preview', 'hold.verify', 'hold.unsubscribe'] as $name) {
        expect(Route::has($name))->toBeTrue("[{$name}] is missing from the cached route table");
    }
});

it('serves both unsubscribe methods from a cached route table, on the one signed URL', function () {
    holdCacheRoutes();

    $clicked = HoldSignup::factory()->create();
    $oneClick = HoldSignup::factory()->create();

    // Emails mint the URL from the `hold.unsubscribe` name; the RFC 8058
    // one-click POST arrives on that same URL, never one of its own.
    $this->get(URL::signedRoute('hold.unsubscribe', ['signup' => $clicked->getKey()]))
        ->assertOk();

    $this->post(URL::signedRoute('hold.unsubscribe', ['signup' => $oneClick->getKey()]), ['List-Unsubscribe' => 'One-Click'])
        ->assertOk()
        ->assertContent('');

    expect($clicked->fresh()->unsubscribed_at)->not->toBeNull()
        ->and($oneClick->fresh()->unsubscribed_at)->not->toBeNull();
});
