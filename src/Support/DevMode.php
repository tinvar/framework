<?php

declare(strict_types=1);

namespace Tinvar\Support;

/**
 * Whether this process is serving in dev: `tinvar serve --dev` says so with
 * TINVAR_DEV=1, and the dev overlay, the renderer's raw messages and the
 * HMR allowances in the policy follow from it.
 *
 * Refused, loudly, beside any sign of production. The dev mode shows
 * stacks, source paths and exception messages to whoever loads a failing
 * page, so a TINVAR_DEV left over in an env file copied to a server must
 * stop the process from booting, not quietly switch that on for the world.
 */
final class DevMode
{
    /** The variables any of which, saying `production`, rule dev mode out. */
    public const array PRODUCTION_MARKERS = ['TINVAR_ENV', 'APP_ENV', 'NODE_ENV'];

    /**
     * @param array<string, string>|null $env the environment; the process's own by default
     * @throws \RuntimeException when dev mode is asked for in production
     */
    public static function fromEnvironment(?array $env = null): bool
    {
        $env ??= getenv();
        if (($env['TINVAR_DEV'] ?? '') !== '1') {
            return false;
        }

        $marker = self::productionMarker($env);
        if ($marker !== null) {
            throw new \RuntimeException(
                "TINVAR_DEV=1 is refused: {$marker}=production. Dev mode shows stacks, source paths and messages "
                . 'to anyone who loads a failing page, and never runs in production. Unset TINVAR_DEV.',
            );
        }

        return true;
    }

    /**
     * The variable saying this is production, or null.
     *
     * @param array<string, string> $env
     */
    public static function productionMarker(array $env): ?string
    {
        foreach (self::PRODUCTION_MARKERS as $name) {
            if (strtolower(trim($env[$name] ?? '')) === 'production') {
                return $name;
            }
        }

        return null;
    }
}
