<?php

declare(strict_types=1);

namespace JamesGifford\Hold;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use JamesGifford\Hold\Contracts\HoldSignupContract;
use JamesGifford\Hold\Models\HoldSignup;
use JamesGifford\Hold\Support\ColorTheme;
use RuntimeException;

/**
 * Small resolution helper shared by the controller, announce machinery, and
 * commands, so the model and notification classes each have exactly one
 * resolution point.
 */
final class Hold
{
    /**
     * The configured signup model class name (falls back to the package model).
     *
     * @return class-string<HoldSignupContract>
     *
     * @throws RuntimeException when the configured class exists but does not
     *                          satisfy the contract — silently using a
     *                          different model would discard the app's own.
     */
    public static function signupModel(): string
    {
        $class = config('jamesgifford.hold.models.signup');

        if (is_string($class) && is_a($class, HoldSignupContract::class, true)) {
            return $class;
        }

        if (is_string($class) && $class !== '' && class_exists($class)) {
            throw new RuntimeException(
                "The configured Hold signup model [{$class}] does not implement "
                .HoldSignupContract::class.'. Re-publish the model with '
                .'`php artisan jamesgifford:hold:setup`, or add the interface to your copy.'
            );
        }

        // Not published yet (the default points at App\Models\HoldSignup).
        return HoldSignup::class;
    }

    /**
     * A fresh query builder for the resolved signup model.
     *
     * @return Builder<HoldSignup>
     */
    public static function signups(): Builder
    {
        $class = self::signupModel();

        // The resolved class is the app's own copy of the model rather than a
        // subclass of the package's, so no static type links the two — the
        // contract is what guarantees they share a shape, including the scopes
        // the announcer chains onto this builder. This is the single boundary
        // where that guarantee is asserted rather than proven.
        /** @var Builder<HoldSignup> */
        return (new $class)->newQuery();
    }

    /**
     * Resolve a notification class by its config key, so an app can substitute
     * its own subclass without the package knowing.
     *
     * Falls back to the package's shipped default when the key is missing or
     * unusable: ServiceProvider::mergeConfigFrom() merges only the top-level
     * `jamesgifford.hold` key, so an app's published config file REPLACES whole
     * sections instead of merging into them. Any key added to the package after
     * an app published its config is therefore absent at runtime, and resolving
     * that to an empty string would fatal on `new ''`.
     *
     * @return class-string
     */
    public static function notificationClass(string $key): string
    {
        $class = config("jamesgifford.hold.notifications.classes.{$key}");

        if (is_string($class) && $class !== '' && class_exists($class)) {
            return $class;
        }

        $default = self::packageDefaults()['notifications']['classes'][$key] ?? null;

        if (! is_string($default) || ! class_exists($default)) {
            throw new InvalidArgumentException("Unknown Hold notification key [{$key}].");
        }

        return $default;
    }

    /**
     * The team addresses that receive the "hold enabled" notice (empty = none).
     *
     * @return array<int, string>
     */
    public static function teamAddresses(): array
    {
        return array_values(array_filter((array) config('jamesgifford.hold.notifications.team_addresses', [])));
    }

    /**
     * Resolve an appearance (color/derivation-weight) setting through its
     * tiers: this template-group's config value, then the shared config
     * value, then the package's own shipped default for that key (null for
     * every property except 'bg') — never returns "missing," so a caller can
     * `??=` straight onto its own auto-derivation without a third fallback.
     *
     * A malformed value at the group or shared tier (not a well-formed hex
     * color, or not numeric for a `*_weight` key) is treated as absent at
     * that tier rather than returned verbatim — an unvalidated value would
     * otherwise reach ColorTheme and throw mid-render, turning a config
     * typo (e.g. an unset env var resolving to '') into a hard error on
     * every holding-page visit and mail render for the duration of a hold.
     *
     * Falls back to the RAW shipped config (packageDefaults()), same as
     * notificationClass() and for the same reason (see its docblock):
     * ServiceProvider::mergeConfigFrom() merges only the top-level
     * `jamesgifford.hold` key, so an already-published config file replaces
     * the whole `appearance` array rather than merging into it — without
     * this, a key added to `appearance` after an app already published its
     * config would silently read as missing instead of falling through.
     */
    public static function appearance(string $key, string $group): mixed
    {
        $value = self::validAppearanceValue($key, config("jamesgifford.hold.appearance.{$group}.{$key}"))
            ?? self::validAppearanceValue($key, config("jamesgifford.hold.appearance.{$key}"));

        if ($value !== null) {
            return $value;
        }

        $defaults = self::packageDefaults()['appearance'];

        return $defaults[$group][$key] ?? $defaults[$key] ?? null;
    }

    /**
     * Validate a raw config value for an appearance property against its
     * type — a hex color, or a numeric 0-1 blend weight for a `*_weight`
     * key — returning it only when well-formed; null (the "no override at
     * this tier" sentinel appearance() already treats null as) otherwise.
     */
    private static function validAppearanceValue(string $key, mixed $raw): null|string|float
    {
        if ($raw === null) {
            return null;
        }

        if (str_ends_with($key, '_weight')) {
            return is_int($raw) || is_float($raw) ? (float) $raw : null;
        }

        return is_string($raw) && ColorTheme::isValidHex($raw) ? $raw : null;
    }

    /**
     * The package's own shipped config array.
     *
     * Read from the file rather than restated here, so the fallback above can
     * never drift from the defaults the package actually documents and ships.
     *
     * @return array<string, mixed>
     */
    private static function packageDefaults(): array
    {
        static $defaults;

        return $defaults ??= require dirname(__DIR__).'/config/hold.php';
    }
}
