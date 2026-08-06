<?php

declare(strict_types=1);

it('renders the prelaunch view standalone with the signup form', function () {
    $html = holdRender('hold::prelaunch');

    // {{ }} escapes the apostrophe in "We're", so match an apostrophe-free fragment.
    expect($html)->toContain('launching soon')
        ->toContain('name="email"')
        ->toContain('action="'.url('hold/signup').'"')
        // Honeypot field is present but the reskin knobs are declared up top.
        ->toContain('name="website"')
        ->toContain('--hold-accent');
});

it('renders the maintenance view standalone with the signup form', function () {
    $html = holdRender('hold::maintenance');

    expect($html)->toContain('right back')
        ->toContain('name="email"')
        ->toContain('value="maintenance"')
        ->toContain('--hold-accent');
});

it('honors a custom route prefix in the form action', function () {
    config()->set('jamesgifford.hold.routes.prefix', 'soon');

    $html = holdRender('hold::prelaunch');

    expect($html)->toContain('action="'.url('soon/signup').'"');
});
