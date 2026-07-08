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
 * A signed "let me see the real app" cookie set by the /preview route. The
 * PrelaunchMode middleware runs GLOBALLY — before Laravel's EncryptCookies
 * middleware decrypts incoming cookies — so this helper decrypts and validates
 * the cookie itself, exactly the way EncryptCookies would (AES payload plus a
 * name-bound CookieValuePrefix). Authenticity comes from the app key: only the
 * app can mint a cookie that decrypts under this name, so no attacker can forge
 * one without APP_KEY.
 */
final class BypassCookie
{
    /**
     * The marker value stored inside the (encrypted) cookie. Its content is not
     * secret — a valid decrypt under the cookie name already proves the app
     * minted it; this just confirms the expected shape.
     */
    private const MARKER = 'active';

    /**
     * Build the (unencrypted) cookie to queue on the response. Laravel's
     * EncryptCookies middleware encrypts it on the way out.
     */
    public function make(): Cookie
    {
        $name = $this->name();
        $minutes = $this->lifetimeDays() * 24 * 60;

        return cookie()->make($name, self::MARKER, $minutes);
    }

    /**
     * Build an immediately-expired cookie to clear the bypass.
     */
    public function forget(): Cookie
    {
        return cookie()->forget($this->name());
    }

    /**
     * Whether the request carries a valid, app-minted bypass cookie. Safe to
     * call before EncryptCookies has run.
     */
    public function validFromRequest(Request $request): bool
    {
        $name = $this->name();
        $raw = $request->cookies->get($name);

        if (! is_string($raw) || $raw === '') {
            return false;
        }

        try {
            $encrypter = app('encrypter');
            $decrypted = $encrypter->decrypt($raw, false);
        } catch (Throwable) {
            return false;
        }

        $keys = method_exists($encrypter, 'getAllKeys')
            ? $encrypter->getAllKeys()
            : [$encrypter->getKey()];

        $value = CookieValuePrefix::validate($name, $decrypted, $keys);

        return is_string($value) && hash_equals(self::MARKER, $value);
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
