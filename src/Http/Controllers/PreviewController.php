<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use JamesGifford\Hold\HoldState;
use JamesGifford\Hold\Http\BypassCookie;

/**
 * Sets the prelaunch bypass cookie and sends the visitor to the real app.
 *
 * Reached via a signed link (printed by `jamesgifford:hold:enable`). A valid
 * signature is necessary but not sufficient: an old link signed for a PREVIOUS
 * activation still verifies, so the link must also carry the CURRENT activation
 * token. On a token mismatch it fails exactly like a tampered signature — an
 * InvalidSignatureException (403) — so a stale link is indistinguishable from a
 * forged one.
 */
final class PreviewController
{
    public function __invoke(Request $request, HoldState $state, BypassCookie $bypass): RedirectResponse
    {
        $token = $state->token();

        if ($token === null || ! hash_equals($token, (string) $request->query('token'))) {
            throw new InvalidSignatureException;
        }

        return redirect()->to('/')->withCookie($bypass->make($token));
    }
}
