<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Http;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * The prelaunch bypass cookie.
 *
 * A signed "let me see the real app" cookie set by the /preview route. Its VALUE
 * is the per-activation bypass token (see HoldState::token()); the PrelaunchMode
 * middleware waves a request through only when the cookie's token matches the
 * current activation, so a cookie from a previous activation stops working.
 *
 * The PrelaunchMode middleware runs GLOBALLY — before Laravel's EncryptCookies
 * middleware decrypts incoming cookies — so this helper decrypts and validates
 * the cookie itself, exactly the way EncryptCookies would (AES payload plus a
 * name-bound CookieValuePrefix). Authenticity comes from the app key: only the
 * app can mint a cookie that decrypts under this name.
 */
final class BypassCookie
{
    /**
     * Build the (unencrypted) cookie carrying the given activation token.
     * Laravel's EncryptCookies middleware encrypts it on the way out.
     */
    public function make(string $token): Cookie
    {
        $minutes = $this->lifetimeDays() * 24 * 60;

        return cookie()->make($this->name(), $token, $minutes);
    }

    /**
     * Build an immediately-expired cookie to clear the bypass.
     */
    public function forget(): Cookie
    {
        return cookie()->forget($this->name());
    }

    /**
     * The bypass token carried by the request's cookie, or null when the cookie
     * is absent or not a valid app-minted cookie. Safe to call before
     * EncryptCookies has run.
     */
    public function tokenFromRequest(Request $request): ?string
    {
        $name = $this->name();
        $raw = $request->cookies->get($name);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $encrypter = app('encrypter');
            $decrypted = $encrypter->decrypt($raw, false);
        } catch (Throwable) {
            return null;
        }

        $keys = method_exists($encrypter, 'getAllKeys')
            ? $encrypter->getAllKeys()
            : [$encrypter->getKey()];

        $value = CookieValuePrefix::validate($name, $decrypted, $keys);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function name(): string
    {
        return (string) config('jamesgifford.hold.prelaunch.bypass_cookie_name', 'hold_bypass');
    }

    public function lifetimeDays(): int
    {
        return (int) config('jamesgifford.hold.prelaunch.bypass_cookie_lifetime_days', 30);
    }
}
