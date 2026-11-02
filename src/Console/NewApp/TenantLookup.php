<?php

declare(strict_types=1);

namespace Tinvar\Console\NewApp;

/**
 * Whether a new application is multi-tenant, and how a request's tenant
 * is found (ADR 0056, 0156): `tinvar new --tenancy=off|subdomain|domain`.
 */
enum TenantLookup: string
{
    /** One tenant's application: no tinvar/tenancy. */
    case Off = 'off';

    /** `acme.example.com` is the tenant whose slug is `acme` (Subdomains). */
    case Subdomain = 'subdomain';

    /** Each tenant on a host of its own, `shop.acme.com`, kept on its row. */
    case Domain = 'domain';

    public function title(): string
    {
        return match ($this) {
            self::Off => 'No: one tenant',
            self::Subdomain => 'Yes, by subdomain: acme.example.com',
            self::Domain => 'Yes, by each tenant\'s own domain: shop.acme.com',
        };
    }

    /** @return array<string, string> value => title, for a select */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $lookup) {
            $options[$lookup->value] = $lookup->title();
        }

        return $options;
    }
}
