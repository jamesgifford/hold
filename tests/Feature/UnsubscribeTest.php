<?php

declare(strict_types=1);

use Illuminate\Support\Facades\URL;
use JamesGifford\Hold\Models\Signup;

it('unsubscribes a signup reached through a valid signed link', function () {
    $signup = Signup::factory()->create();

    $url = URL::signedRoute('hold.unsubscribe', ['signup' => $signup->getKey()]);

    $this->get($url)
        ->assertOk()
        ->assertSee('unsubscribed');

    expect($signup->refresh()->unsubscribed_at)->not->toBeNull();
});

it('rejects an unsubscribe link with a missing or invalid signature', function () {
    $signup = Signup::factory()->create();

    // No signature at all.
    $this->get('hold/unsubscribe/'.$signup->getKey())->assertForbidden();

    // Tampered signature.
    $url = URL::signedRoute('hold.unsubscribe', ['signup' => $signup->getKey()]).'x';
    $this->get($url)->assertForbidden();

    expect($signup->refresh()->unsubscribed_at)->toBeNull();
});
