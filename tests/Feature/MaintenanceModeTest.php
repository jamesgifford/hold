<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as FrameworkMiddleware;
use Illuminate\Support\Facades\Route;
use JamesGifford\Hold\Http\Middleware\PreventRequestsDuringMaintenance;
use JamesGifford\Hold\Models\Signup;

beforeEach(function () {
    Route::middleware('web')->get('/', fn () => 'REAL APP HOMEPAGE');
});

it('binds the package maintenance middleware subclass over the framework one', function () {
    expect(app(FrameworkMiddleware::class))->toBeInstanceOf(PreventRequestsDuringMaintenance::class);
});

it('503s a normal route but keeps the package signup route reachable while down', function () {
    app()->maintenanceMode()->activate(['status' => 503]);

    try {
        $this->get('/')->assertStatus(503);

        $this->post('hold/signup', ['email' => 'maint@example.com'])
            ->assertRedirect();
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    expect(Signup::where('email', 'maint@example.com')->exists())->toBeTrue();
});

it('merges the configured route prefix into the excluded paths', function () {
    config()->set('jamesgifford.hold.routes.prefix', 'coming-soon');

    $paths = app(FrameworkMiddleware::class)->getExcludedPaths();

    expect($paths)->toContain('coming-soon')
        ->toContain('coming-soon/*');
});
