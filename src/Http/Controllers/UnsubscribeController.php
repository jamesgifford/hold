<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use JamesGifford\Hold\Hold;

/**
 * Handles a one-click unsubscribe from a signed link in an announcement email.
 *
 * The route carries Laravel's `signed` middleware, so an unforged link is
 * required to reach this action. Unsubscribe is a soft state — the row is kept
 * (see Signup::unsubscribe()) so the notified guard still holds.
 */
final class UnsubscribeController
{
    public function __invoke(Request $request, int|string $signup): View
    {
        $model = Hold::signupModel();
        $record = $model::query()->findOrFail($signup);

        $record->unsubscribe();

        return view('hold::unsubscribed', ['email' => $record->email]);
    }
}
