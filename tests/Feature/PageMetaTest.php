<?php

declare(strict_types=1);

it('marks the maintenance page noindex, nofollow', function () {
    expect(holdRender('hold::maintenance'))
        ->toContain('<meta name="robots" content="noindex, nofollow">');
});

it('does not mark the prelaunch page noindex', function () {
    expect(holdRender('hold::prelaunch'))->not->toContain('noindex');
});
