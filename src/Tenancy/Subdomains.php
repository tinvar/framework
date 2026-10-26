<?php

declare(strict_types=1);

namespace Tinvar\Tenancy;

use Tinvar\Http\HostName;

/**
 * Tenants named by one label under the platform's own domains:
 * `acme.shop.test` is the tenant the application calls `acme`.
 *
 * Exactly one label. `x.acme.shop.test` is not acme's -- a wildcard
 * certificate covers one level, and a lookup that accepted any depth would
 * hand acme's tenant to a name acme never chose -- and the bare domain is
 * the platform's, not a tenant's.
 *
 * The label goes to the application's lookup, which says which tenant it
 * is, if any: a label is a slug the application issued, and only the
 * application knows which slugs are live.
 */
final readonly class Subdomains implements TenantHosts
{
    /** @var list<string> */
    private array $domains;

    /**
     * @param list<string> $domains the platform's domains, as `shop.test`
     * @param \Closure(string): ?string $tenantForLabel the tenant a label names, or null
     */
    public function __construct(array $domains, private \Closure $tenantForLabel)
    {
        $normalized = [];
        foreach ($domains as $domain) {
            $host = HostName::normalize($domain);
            if ($host === null) {
                throw new \InvalidArgumentException("'{$domain}' is not a domain subdomains can sit under.");
            }
            $normalized[] = $host;
        }

        // The most specific first. With one platform domain under another --
        // `shop.test` and `eu.shop.test` -- `acme.eu.shop.test` is the inner
        // domain's acme, whatever order they were listed in: tried against
        // the outer first, its label was `acme.eu`, two labels, and nobody's.
        usort($normalized, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $this->domains = $normalized;
    }

    public function tenantForHost(string $host): ?string
    {
        // A platform domain is the platform's, even one that is a single
        // label under another: `eu.shop.test` is not the tenant whose slug
        // is `eu`.
        if (\in_array($host, $this->domains, true)) {
            return null;
        }

        foreach ($this->domains as $domain) {
            $suffix = '.' . $domain;
            if (!str_ends_with($host, $suffix)) {
                continue;
            }

            $label = substr($host, 0, -\strlen($suffix));
            if ($label === '' || str_contains($label, '.')) {
                return null;
            }

            $tenant = ($this->tenantForLabel)($label);

            return $tenant === '' ? null : $tenant;
        }

        return null;
    }
}
