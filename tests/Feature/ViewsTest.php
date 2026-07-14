<?php

declare(strict_types=1);

it('renders the prelaunch view standalone with the signup form', function () {
    $html = view('hold::prelaunch')->render();

    // {{ }} escapes the apostrophe in "We're", so match an apostrophe-free fragment.
    expect($html)->toContain('launching soon')
        ->toContain('name="email"')
        ->toContain('action="'.url('hold/signup').'"')
        // Honeypot field is present but the reskin knobs are declared up top.
        ->toContain('name="website"')
        ->toContain('--hold-accent');
});

it('renders the maintenance view standalone with the signup form', function () {
    $html = view('hold::maintenance')->render();

    expect($html)->toContain('right back')
        ->toContain('name="email"')
        ->toContain('value="maintenance"')
        ->toContain('--hold-accent');
});

it('renders the 503 shim by including the maintenance template', function () {
    // The published errors/503.blade.php is a thin shim that @includes the
    // maintenance template, so rendering it yields the maintenance capture form.
    $html = view('hold::errors.503')->render();

    expect($html)->toContain('right back')
        ->toContain('value="maintenance"');
});

it('honors a custom route prefix in the form action', function () {
    config()->set('jamesgifford.hold.routes.prefix', 'soon');

    $html = view('hold::prelaunch')->render();

    expect($html)->toContain('action="'.url('soon/signup').'"');
});
