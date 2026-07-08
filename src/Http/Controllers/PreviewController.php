<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use JamesGifford\Hold\Http\BypassCookie;

/**
 * Sets the prelaunch bypass cookie and sends the visitor to the real app.
 *
 * Reached via a signed link (printed by `jamesgifford:hold:enable`), so only
 * someone holding that link can grant themselves a bypass. The cookie lets the
 * PrelaunchMode middleware wave subsequent requests through.
 */
final class PreviewController
{
    public function __invoke(BypassCookie $bypass): RedirectResponse
    {
        return redirect()->to('/')->withCookie($bypass->make());
    }
}
