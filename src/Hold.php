<?php

declare(strict_types=1);

namespace JamesGifford\Hold;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use JamesGifford\Hold\Models\Signup;

/**
 * Small resolution helper shared by the controller, announce machinery, and
 * commands, so the model, notification classes, and unsubscribe links each have
 * exactly one resolution point.
 */
final class Hold
{
    /**
     * The configured Signup model class name (falls back to the package model).
     *
     * @return class-string<Model>
     */
    public static function signupModel(): string
    {
        $class = config('jamesgifford.hold.models.signup');

        return is_string($class) && class_exists($class) ? $class : Signup::class;
    }

    /**
     * A fresh query builder for the resolved Signup model.
     */
    public static function signups(): Builder
    {
        $class = self::signupModel();

        return (new $class)->newQuery();
    }

    /**
     * Resolve a notification class by its config key, so an app can substitute
     * its own subclass without the package knowing.
     *
     * @return class-string
     */
    public static function notificationClass(string $key): string
    {
        return (string) config("jamesgifford.hold.notifications.classes.{$key}");
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
     * A signed one-click unsubscribe URL for a signup, or null when the package
     * routes are not registered (routes.register = false).
     */
    public static function unsubscribeUrl(Model $signup): ?string
    {
        if (! Route::has('hold.unsubscribe')) {
            return null;
        }

        return URL::signedRoute('hold.unsubscribe', ['signup' => $signup->getKey()]);
    }
}
