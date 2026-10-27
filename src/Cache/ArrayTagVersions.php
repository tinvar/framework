<?php

declare(strict_types=1);

namespace Tinvar\Cache;

/**
 * Tag versions in this process's memory: a test's, or an application
 * served by one process. A worker's bump reaches no other worker, so an
 * application on several -- FrankenPHP's threads, servers behind a load
 * balancer -- keeps them where all of them read (PdoTagVersions).
 */
final class ArrayTagVersions implements TagVersions
{
    /** @var array<string, int> */
    private array $versions = [];

    public function versions(array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $out[$tag] = $this->versions[$tag] ?? 0;
        }

        return $out;
    }

    public function bump(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->versions[$tag] = random_int(1, PHP_INT_MAX);
        }
    }
}
