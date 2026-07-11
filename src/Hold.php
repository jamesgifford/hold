<?php

declare(strict_types=1);

namespace JamesGifford\Hold;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JamesGifford\Hold\Models\HoldSignup;

/**
 * Small resolution helper shared by the controller, announce machinery, and
 * commands, so the model and notification classes each have exactly one
 * resolution point.
 */
final class Hold
{
    /**
     * The configured HoldSignup model class name (falls back to the package model).
     *
     * @return class-string<Model>
     */
    public static function signupModel(): string
    {
        $class = config('jamesgifford.hold.models.signup');

        return is_string($class) && class_exists($class) ? $class : HoldSignup::class;
    }

    /**
     * A fresh query builder for the resolved HoldSignup model.
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
}
