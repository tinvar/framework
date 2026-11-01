<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

/**
 * Chooses where `tinvar new` takes the skeleton from (SkeletonSource):
 * `--from=monorepo|packagist` where given; otherwise the checkout this
 * framework runs from, where it is one -- the repository's packages/ with
 * packages/skeleton in it, found from this file or TINVAR_WORKSPACE -- and
 * Packagist everywhere else, as an installed framework is.
 */
final class Skeletons
{
    /**
     * @param string|null $from `monorepo` or `packagist`, or null to detect
     * @param string|null $workspace the repository's checkout, where not found from this file
     * @throws \InvalidArgumentException for a source there is none of, or a checkout that is none
     */
    public static function choose(?string $from, ?string $workspace = null): SkeletonSource
    {
        $workspace ??= self::workspace();

        return match ($from) {
            null => $workspace !== null ? new MonorepoSkeleton($workspace) : new PackagistSkeleton(),
            'monorepo' => new MonorepoSkeleton($workspace ?? throw new \InvalidArgumentException('--from=monorepo, and this framework is not running from the repository\'s checkout: set TINVAR_WORKSPACE to it.')),
            'packagist' => new PackagistSkeleton(),
            default => throw new \InvalidArgumentException("--from is monorepo or packagist, not '{$from}'."),
        };
    }

    /** The repository's checkout this framework runs from, or TINVAR_WORKSPACE names; null for neither. */
    public static function workspace(): ?string
    {
        $named = getenv('TINVAR_WORKSPACE');
        foreach ([\is_string($named) && $named !== '' ? $named : null, \dirname(__DIR__, 5)] as $candidate) {
            if ($candidate !== null && MonorepoSkeleton::isWorkspace($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return null;
    }
}
