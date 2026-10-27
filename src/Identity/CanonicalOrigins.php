<?php

declare(strict_types=1);

namespace Tinvar\Identity;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\HostName;

/**
 * The https origin a user's emailed links are built on: "Every emailed link
 * (magic link, reset, invite, export, unsubscribe) is built from the user's
 * canonical domain, never from the request's `Host` header"
 * (tinvar-spec.md). A worker sending the email has no request to read one
 * from anyway, and a request's Host is whatever its sender typed -- a link
 * built on it would hand a sign-in token to a host of the sender's choosing
 * (ADR 0076).
 *
 * A tenant's canonical origin is its primary custom domain, which the
 * application names, while the framework holds it to every check a domain
 * must pass to be served: a record the application keeps
 * (CustomDomains::find()), active, checked at least once, its last check a
 * pass, and that pass recent -- a re-verification that stopped running is
 * not one still vouching for the DNS -- and a realm (Realms) that is a
 * custom domain of that very tenant, on https. Anything short of all of it,
 * and the origin is the platform's. Falling back is always safe: the
 * platform's own host is the one realm every user may sign in on.
 *
 * Staff never get a custom domain, since staff accounts cannot sign in on
 * one ("Staff accounts can't sign in on custom domains."): forUser() asks
 * the application whether a user is, and gives them the platform's.
 *
 * Everything this returns is an origin alone -- `https://host[:port]`, no
 * path, no trailing slash, no user info -- and https always: a link carries
 * a token, and a plain-http hop hands it to the network.
 */
final readonly class CanonicalOrigins
{
    /** A domain's last pass must be this recent: two days, as it is re-verified daily. */
    public const int MAX_CHECK_AGE = 172_800;

    public string $platform;

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param string $platform the platform's own https origin, where sign-in happens
     * @param (\Closure(string): ?string)|null $primaryDomain a tenant's primary custom domain,
     *        a normalized host, or null for none -- the application's word, which is checked
     * @param CustomDomains|null $domains the application's domain records, asked whether the
     *        domain is active and passing its re-verification
     * @param Realms|null $realms asked whether the domain is a custom domain of that tenant
     * @param (\Closure(Authenticatable): bool)|null $staff whether a user is staff, and so
     *        given the platform's origin whatever their tenant
     * @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given
     */
    public function __construct(
        string $platform,
        private ?\Closure $primaryDomain = null,
        private ?CustomDomains $domains = null,
        private ?Realms $realms = null,
        private ?\Closure $staff = null,
        ?\Closure $clock = null,
    ) {
        $this->platform = self::origin($platform);
        if ($primaryDomain !== null && ($domains === null || $realms === null)) {
            throw new \InvalidArgumentException(
                'A tenant\'s primary domain is used only once its record and its realm are checked: give the custom domains and the realms too.',
            );
        }
        // Fail closed: with custom domains in play, an application that
        // forgot to say who its staff are would send staff to them.
        if ($primaryDomain !== null && $staff === null) {
            throw new \InvalidArgumentException('With custom domains, say who is staff: staff are never sent to one.');
        }
        $this->clock = $clock ?? time(...);
    }

    /**
     * $origin as an emailed link's origin: https, a host, perhaps a port,
     * nothing else; or an InvalidArgumentException.
     */
    public static function origin(string $origin): string
    {
        $origin = rtrim($origin, '/');
        // One spelling per origin, as a browser serializes it: a normalized
        // host name or an address literal, a port only when it is not 443,
        // and nothing after.
        $valid = preg_match('#^https://([^/:?\#@\[\]\s]+|\[[0-9a-f:.]+\])(?::([1-9][0-9]{0,4}))?\z#', $origin, $m) === 1;
        $host = $m[1] ?? '';
        $port = $m[2] ?? '';
        $literal = trim($host, '[]');
        if ($valid && ($port === '443' || ($port !== '' && (int) $port > 65535))) {
            $valid = false;
        }
        if ($valid && HostName::normalize($host) !== $host
            && !(str_starts_with($host, '[') ? filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false : filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)) {
            $valid = false;
        }
        if (!$valid) {
            throw new \InvalidArgumentException(
                "'{$origin}' is not an origin an emailed link is built on: https, a host and perhaps a port, such as https://shop.example.",
            );
        }

        return $origin;
    }

    /** The canonical origin of a tenant: its verified primary domain, or the platform's. */
    public function forTenant(string $tenant): string
    {
        if ($tenant === '' || $this->primaryDomain === null || $this->domains === null || $this->realms === null) {
            return $this->platform;
        }

        $host = ($this->primaryDomain)($tenant);
        if (!\is_string($host) || $host === '' || HostName::normalize($host) !== $host) {
            return $this->platform;
        }

        $record = $this->domains->find($host);
        if (
            $record === null
            || $record->host !== $host
            || !$record->active
            || $record->checkedAt === null
            || $record->failingSince !== null
            || $record->checkedAt->getTimestamp() < ($this->clock)() - self::MAX_CHECK_AGE
        ) {
            return $this->platform;
        }

        $realm = $this->realms->forHost($host);
        if ($realm === null || !$realm->isCustom() || $realm->tenant !== $tenant || !str_starts_with($realm->origin, 'https://')) {
            return $this->platform;
        }

        return $realm->origin;
    }

    /**
     * The canonical origin of a user in a tenant: the platform's for staff,
     * and the tenant's otherwise.
     */
    public function forUser(Authenticatable $user, string $tenant): string
    {
        if ($this->staff !== null && ($this->staff)($user) === true) {
            return $this->platform;
        }

        return $this->forTenant($tenant);
    }
}
