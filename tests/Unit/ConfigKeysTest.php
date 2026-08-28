<?php

declare(strict_types=1);

use JamesGifford\Hold\Support\ConfigKeys;

it('flattens nested associative arrays to dot-notation keys', function () {
    expect(ConfigKeys::flatten([
        'a' => 1,
        'b' => [
            'c' => 2,
            'd' => [
                'e' => 3,
            ],
        ],
    ]))->toBe([
        'a' => 1,
        'b.c' => 2,
        'b.d.e' => 3,
    ]);
});

it('treats a list as a leaf value rather than expanding its indices', function () {
    expect(ConfigKeys::flatten([
        'middleware' => ['web'],
        'team_addresses' => [],
    ]))->toBe([
        'middleware' => ['web'],
        'team_addresses' => [],
    ]);
});

it('returns an empty array for an empty config', function () {
    expect(ConfigKeys::flatten([]))->toBe([]);
});
