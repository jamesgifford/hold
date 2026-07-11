<?php

declare(strict_types=1);

/*
 * Guards the Laravel Boost skill: it must exist at the convention path, begin
 * with `---` on line 1 (or Boost's start-anchored parser silently skips it),
 * carry the required frontmatter, and have `name` equal to its folder name. The
 * identifier checks catch the skill drifting out of sync with the code.
 */

const HOLD_SKILL_DIR = 'jamesgifford-hold';

function holdSkillPath(): string
{
    return dirname(__DIR__, 3).'/resources/boost/skills/'.HOLD_SKILL_DIR.'/SKILL.md';
}

/**
 * @return array<string, string>
 */
function holdSkillFrontmatter(): array
{
    $contents = (string) file_get_contents(holdSkillPath());

    expect(preg_match('/\A---\n(.*?)\n---\n/s', $contents, $m))->toBe(1, 'Frontmatter block not found.');

    $pairs = [];
    foreach (preg_split('/\n/', $m[1]) ?: [] as $line) {
        if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $kv) === 1) {
            $pairs[$kv[1]] = trim($kv[2]);
        }
    }

    return $pairs;
}

it('ships the skill file at the convention path', function () {
    expect(holdSkillPath())->toBeFile();
});

it('starts with exactly the frontmatter delimiter on line 1', function () {
    $contents = (string) file_get_contents(holdSkillPath());

    // No BOM, comment, blank line, or whitespace may precede `---`.
    expect($contents)->toStartWith("---\n")
        ->and(strtok($contents, "\n"))->toBe('---');
});

it('has non-empty name and description frontmatter', function () {
    $frontmatter = holdSkillFrontmatter();

    expect($frontmatter)->toHaveKeys(['name', 'description'])
        ->and(trim($frontmatter['description']))->not->toBe('');
});

it('has a frontmatter name matching its parent folder', function () {
    expect(holdSkillFrontmatter()['name'])->toBe(HOLD_SKILL_DIR);
});

it('references real code identifiers so it cannot drift from the code', function () {
    $contents = (string) file_get_contents(holdSkillPath());

    $needles = [
        'jamesgifford:hold:setup',
        'jamesgifford:hold:enable',
        'jamesgifford:hold:announce',
        'hold.signup',
        'hold.preview',
        'jamesgifford:hold:unsubscribe',
        'down --render',
        'App\\Models\\HoldSignup',
        'notifications.classes',
    ];

    foreach ($needles as $needle) {
        expect($contents)->toContain($needle);
    }
});
