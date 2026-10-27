<?php

declare(strict_types=1);

namespace Tinvar\Tests\Tenancy\Fixtures;

use Tinvar\Tenancy\TenantTree;

/**
 * The application's tree, as tenancy's tests write one: a reseller with two
 * customers, one of which has a site of its own, and a tenant beside them
 * all -- the tenants the database's hierarchy fixture seeds:
 *
 *     reseller            other
 *     ├── acme
 *     │   └── acme-west
 *     └── globex
 */
final class Tree implements TenantTree
{
    /** @var array<string, string> child => parent */
    public array $parents = [
        'acme' => 'reseller',
        'globex' => 'reseller',
        'acme-west' => 'acme',
    ];

    /** How many times each question was asked, for tests of what a check costs. */
    public int $asked = 0;

    public function parentOf(string $tenant): ?string
    {
        $this->asked++;

        return $this->parents[$tenant] ?? null;
    }

    public function descendantsOf(string $tenant): array
    {
        $this->asked++;
        $found = [];
        $queue = [$tenant];
        while ($queue !== []) {
            $node = array_shift($queue);
            foreach ($this->parents as $child => $parent) {
                if ($parent === $node && !\in_array($child, $found, true)) {
                    $found[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $found;
    }
}
