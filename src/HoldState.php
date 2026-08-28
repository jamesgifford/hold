<?php

declare(strict_types=1);

namespace JamesGifford\Hold;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Manages prelaunch ("coming soon") activation state.
 *
 * Prelaunch mode is package-owned state, activated one of two ways:
 *
 *  - COMMAND-DRIVEN (the default): a flag file under storage/jamesgifford/hold/,
 *    toggled by `jamesgifford:hold:enable prelaunch` / `:disable`. Independent
 *    of Laravel's native maintenance mode and survives config caching.
 *  - ENV-FORCED: `config('jamesgifford.hold.prelaunch.forced')` (backed by
 *    HOLD_PRELAUNCH, read in config/hold.php — never at runtime, so
 *    config:cache can't go stale). For ephemeral hosting where local disk does
 *    not survive a deploy. Cannot be turned off from the console — only by
 *    unsetting the env var and redeploying. See `source()`.
 *
 * Either way, the ACTIVE source's bypass token is a per-activation secret: a
 * fresh random token is minted on activation (or via `reissueToken()`), and
 * every preview link / bypass cookie is only valid against the CURRENT token.
 * Command-driven mode stores it as the flag file's contents; env-forced mode
 * has no reliable disk, so it stores it in `prelaunch.token_store` instead
 * (see `reissueToken()`).
 *
 * The class fails gracefully: if the storage directory does not exist yet
 * (setup hasn't run) or no token has been minted yet, state simply reads as
 * inactive / tokenless rather than erroring.
 */
final class HoldState
{
    private const TOKEN_CACHE_KEY = 'jamesgifford-hold:prelaunch-token';

    /**
     * Cache store drivers known not to survive a deploy or a process
     * restart. Not exhaustive — just enough to catch the obvious footgun
     * (Testbench/local defaults, `CACHE_STORE=array`) and warn about it.
     *
     * @var list<string>
     */
    private const NON_PERSISTENT_DRIVERS = ['array', 'null'];

    /**
     * Absolute path to the package's runtime storage directory.
     */
    public function directory(): string
    {
        return storage_path('jamesgifford/hold');
    }

    /**
     * Absolute path to the prelaunch flag file.
     */
    public function flagPath(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'prelaunch.active';
    }

    /**
     * Whether prelaunch is forced on via config (HOLD_PRELAUNCH). This
     * source cannot be turned off from the console — see DisableCommand.
     */
    public function isForced(): bool
    {
        return (bool) config('jamesgifford.hold.prelaunch.forced', false);
    }

    /**
     * Whether prelaunch mode is currently active, via EITHER source.
     */
    public function isActive(): bool
    {
        return $this->isForced() || is_file($this->flagPath());
    }

    /**
     * Which source is driving the current activation, or null when inactive.
     * The env source wins if both are somehow present (see class docblock) —
     * it's the one that can't be turned off, so it's the one that's true.
     */
    public function source(): ?string
    {
        return match (true) {
            $this->isForced() => 'env',
            is_file($this->flagPath()) => 'file',
            default => null,
        };
    }

    /**
     * The current per-activation bypass token, read from whichever source is
     * active, or null when the hold is inactive OR active but no token has
     * been minted yet (a legacy/empty flag file, or env-forced with
     * `jamesgifford:hold:preview` never having been run) — every bypass
     * comparison then fails until one is minted.
     */
    public function token(): ?string
    {
        return match ($this->source()) {
            'env' => $this->cachedToken(),
            'file' => $this->fileToken(),
            default => null,
        };
    }

    /**
     * Mint a fresh bypass token for whichever source is currently active,
     * invalidating every previously issued preview link and bypass cookie —
     * the same revocation semantics disable -> enable already provides for
     * command-driven mode. Does NOT change whether prelaunch is active.
     *
     * @throws RuntimeException when no hold is currently active — there is
     *                          nothing to mint a token for.
     */
    public function reissueToken(): string
    {
        $source = $this->source();

        if ($source === null) {
            throw new RuntimeException('No prelaunch hold is currently active.');
        }

        $token = Str::random(40);

        if ($source === 'env') {
            Cache::store($this->tokenStoreName())->forever(self::TOKEN_CACHE_KEY, $token);
        } else {
            $this->ensureDirectory();
            file_put_contents($this->flagPath(), $token.PHP_EOL);
        }

        return $token;
    }

    /**
     * The cache store env-forced mode persists the bypass token in.
     * `prelaunch.token_store` unset means config('cache.default') — resolved
     * here (rather than left to Cache::store(null)) so callers can inspect
     * which store was actually picked, e.g. to warn when it won't persist.
     */
    public function tokenStoreName(): string
    {
        return (string) (config('jamesgifford.hold.prelaunch.token_store') ?: config('cache.default'));
    }

    /**
     * Whether the resolved token store is backed by durable storage. False
     * is a warning sign, not a hard error: env-forced mode still works, it
     * just loses the bypass token (not the hold itself) on the next deploy
     * or process restart.
     */
    public function tokenStoreIsPersistent(): bool
    {
        $driver = (string) config('cache.stores.'.$this->tokenStoreName().'.driver');

        return ! in_array($driver, self::NON_PERSISTENT_DRIVERS, true);
    }

    /**
     * Activate prelaunch mode via the flag file. Creates the storage
     * directory (with a .gitignore) if it does not exist yet. Idempotent —
     * an already-active hold keeps its existing token. Has no effect on (and
     * is not needed for) env-forced mode, which is controlled entirely by
     * config outside this class.
     */
    public function enable(): void
    {
        $this->ensureDirectory();

        if (! is_file($this->flagPath())) {
            // A fresh per-activation token becomes the flag file's contents, so
            // re-enabling always mints a new token and invalidates old bypasses.
            file_put_contents($this->flagPath(), Str::random(40).PHP_EOL);
        }
    }

    /**
     * Deactivate the flag file, if present. Idempotent. Deleting it drops the
     * token, revoking every outstanding preview link and bypass cookie.
     *
     * Does NOT affect env-forced mode — `isActive()` may still read true
     * afterward if `prelaunch.forced` is on. Callers that care about the
     * distinction (the disable command, the maintenance self-heal listener)
     * check `isForced()` themselves; this method only ever touches the file,
     * which is exactly right either way: a genuine command-driven disable, or
     * clearing a stray leftover file under env-forced mode.
     */
    public function disable(): void
    {
        if (is_file($this->flagPath())) {
            @unlink($this->flagPath());
        }
    }

    /**
     * Ensure the runtime storage directory exists and ignores its own
     * contents from version control.
     */
    public function ensureDirectory(): void
    {
        $directory = $this->directory();

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $gitignore = $directory.DIRECTORY_SEPARATOR.'.gitignore';
        if (! is_file($gitignore)) {
            @file_put_contents($gitignore, "*\n!.gitignore\n");
        }
    }

    private function fileToken(): ?string
    {
        $contents = trim((string) @file_get_contents($this->flagPath()));

        return $contents === '' ? null : $contents;
    }

    private function cachedToken(): ?string
    {
        $token = Cache::store($this->tokenStoreName())->get(self::TOKEN_CACHE_KEY);

        return is_string($token) && $token !== '' ? $token : null;
    }
}
