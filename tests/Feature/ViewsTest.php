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

it('uses a reading-width content variable instead of a fixed pixel max-width', function () {
    foreach (['hold::prelaunch', 'hold::maintenance'] as $view) {
        expect(holdRender($view))
            ->toContain('--hold-content-width:')
            ->not->toContain('max-width: 30rem');
    }
});

it('drives inter-element spacing from a single spacing variable', function () {
    foreach (['hold::prelaunch', 'hold::maintenance'] as $view) {
        expect(holdRender($view))
            ->toContain('--hold-space:')
            ->toContain('.hold-card > * + *');
    }
});

it('gives the email input its own background variable, independent of the page background', function () {
    foreach (['hold::prelaunch', 'hold::maintenance'] as $view) {
        $html = holdRender($view);

        preg_match('/\.hold-field input\[type="email"\]\s*\{[^}]*\}/s', $html, $match);

        expect($match)->not->toBeEmpty();
        expect($match[0])
            ->toContain('var(--hold-input-bg)')
            ->not->toContain('var(--hold-bg)');
        expect($html)->toContain('--hold-input-bg:');
    }
});

// --- accessibility ------------------------------------------------------

it('renders exactly one h1 and no headings used purely for styling', function () {
    foreach (['hold::prelaunch', 'hold::maintenance'] as $view) {
        $html = holdRender($view);

        expect(substr_count($html, '<h1'))->toBe(1)
            ->and($html)->not->toContain('<h2')
            ->not->toContain('<h3');
    }
});

it('associates a label with the email input on both pages', function () {
    foreach (['hold::prelaunch', 'hold::maintenance'] as $view) {
        expect(holdRender($view))
            ->toContain('<label for="hold-email">')
            ->toContain('id="hold-email"');
    }
});

it('hides the honeypot from assistive tech and the tab order, not just visually', function () {
    foreach (['hold::prelaunch', 'hold::maintenance'] as $view) {
        expect(holdRender($view))
            ->toContain('class="hold-hp" aria-hidden="true"')
            ->toContain('tabindex="-1"');
    }
});

it('announces the success and error form states via a live region', function () {
    foreach (['hold::prelaunch', 'hold::maintenance'] as $view) {
        request()->merge(['hold' => 'subscribed']);
        expect(holdRender($view))->toContain('role="status"');

        request()->merge(['hold' => 'invalid']);
        expect(holdRender($view))->toContain('role="alert"');
    }
});
