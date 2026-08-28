<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Support;

/**
 * Flattens a config array to dot-notation keys.
 *
 * Shared by the setup command's non-destructive config diff and the
 * package's own drift-guard tests, so the two cannot silently disagree on
 * what counts as "a config key."
 */
final class ConfigKeys
{
    /**
     * @param  array<array-key, mixed>  $config
     * @return array<string, mixed> dotted key => value
     */
    public static function flatten(array $config, string $prefix = ''): array
    {
        $keys = [];

        foreach ($config as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            // A list (including an empty array) is a leaf value, not a
            // branch to recurse into — 'routes.middleware' is one key
            // whose value happens to be an array, not middleware.0 etc.
            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $keys = array_merge($keys, self::flatten($value, $dotted));

                continue;
            }

            $keys[$dotted] = $value;
        }

        return $keys;
    }
}
