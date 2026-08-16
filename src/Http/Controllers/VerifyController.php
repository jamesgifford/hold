<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Http\Controllers;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use JamesGifford\Hold\Events\HoldSignupVerified;
use JamesGifford\Hold\Hold;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confirms a signup's email address ownership.
 *
 * Reached via a signed, expiring link (mailed by SignupVerification). Marking
 * verified and clearing unsubscribed_at are both idempotent, so a second
 * click — or one on an already-verified row — is harmless. This is the ONLY
 * place the package ever clears unsubscribed_at: verifying is proof of
 * mailbox access, the one thing that can re-arm an opted-out address.
 *
 * An expired or tampered link never reaches this class — the `signed`
 * middleware rejects it with a 403 first. A fresh link is one form-submit
 * away: an unverified signup can always re-submit the form.
 */
final class VerifyController
{
    public function __invoke(Request $request, ViewFactory $views): Response
    {
        $signup = Hold::signups()->find((string) $request->query('signup'));

        if ($signup === null) {
            abort(404);
        }

        $signup->markVerified();
        $signup->resubscribe();

        event(new HoldSignupVerified($signup));

        return response(
            $views->make('hold::verified')->render(),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
