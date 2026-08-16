<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Http\Controllers;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use JamesGifford\Hold\Events\HoldSignupUnsubscribed;
use JamesGifford\Hold\Hold;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opts a signup out of every package email.
 *
 * Reached two ways, both signed and non-expiring (old emails must keep
 * working): a GET from clicking the link in a browser, and an RFC 8058
 * one-click POST an email client sends automatically on the user's behalf.
 * Both share the exact same signed URL — the signature does not depend on
 * the HTTP method — so one link in the mail serves both.
 *
 * unsubscribe() is idempotent, so a repeat hit (either method, either an
 * already-opted-out row or the same link clicked twice) is harmless.
 */
final class UnsubscribeController
{
    public function __invoke(Request $request, ViewFactory $views): Response
    {
        $signup = Hold::signups()->find((string) $request->query('signup'));

        if ($signup === null) {
            abort(404);
        }

        $signup->unsubscribe();

        event(new HoldSignupUnsubscribed($signup));

        if ($request->isMethod('post')) {
            // RFC 8058 one-click: the client only checks the status code.
            return response('', 200);
        }

        return response(
            $views->make('hold::unsubscribed')->render(),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
