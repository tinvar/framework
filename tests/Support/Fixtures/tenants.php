<?php

declare(strict_types=1);

namespace Tinvar\Tests\Support\Fixtures;

use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\TenantResolver;
use Tinvar\Orm\Orm;

/**
 * The framework's own tests' tenancy: a stand-in for tinvar/tenancy, which
 * the framework's suite runs without (ADR 0056).
 *
 * A source is any class with `tenantFor(Request): ?string`, built by the
 * test's resolver, and a request already running as a tenant goes on as
 * exactly that one -- no subtree, which is tenancy's. Kept this small on
 * purpose: what tenancy itself does is tested with tenancy.
 */
final readonly class Sources implements TenantResolver
{
    /** @param \Closure(class-string): object $resolver */
    public function __construct(private \Closure $resolver) {}

    public function check(string $source): void
    {
        if (!class_exists($source) || !method_exists($source, 'tenantFor')) {
            throw new \InvalidArgumentException("{$source} reads no tenant from a request.");
        }
    }

    public function resolve(Orm $orm, string $source, Request $request): string
    {
        $this->check($source);
        /** @var class-string $source */
        $built = ($this->resolver)($source);
        $tenant = method_exists($built, 'tenantFor') ? $built->tenantFor($request) : null;
        if (!\is_string($tenant) || $tenant === '') {
            throw new HttpException(404, "{$source} places this request in no tenant.", 'tenant_unknown');
        }

        $current = $orm->tenant();
        if ($current !== '' && $current !== $tenant) {
            throw new HttpException(404, "{$source} places this request in another tenant than its own.", 'tenant_unknown');
        }

        return $tenant;
    }
}
