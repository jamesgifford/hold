<?php

declare(strict_types=1);

it('renders the prelaunch view standalone with the signup form', function () {
    $html = view('hold::prelaunch')->render();

    expect($html)->toContain('We\'re launching soon')
        ->toContain('name="email"')
        ->toContain('action="'.url('hold/signup').'"')
        // Honeypot field is present but the reskin knobs are declared up top.
        ->toContain('name="website"')
        ->toContain('--hold-accent');
});

it('renders the maintenance 503 view standalone with the signup form', function () {
    $html = view('hold::errors.503')->render();

    expect($html)->toContain('right back')
        ->toContain('name="email"')
        ->toContain('value="maintenance"')
        ->toContain('--hold-accent');
});

it('honors a custom route prefix in the form action', function () {
    config()->set('jamesgifford.hold.routes.prefix', 'soon');

    $html = view('hold::prelaunch')->render();

    expect($html)->toContain('action="'.url('soon/signup').'"');
});
