<?php

declare(strict_types=1);

namespace Tinvar\Attributes;

use Tinvar\Outbound\Addresses;
use Tinvar\Outbound\Cidr;
use Tinvar\Outbound\OutboundFailed;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\Resolver;
use Tinvar\Outbound\Target;

/**
 * Where the application's outbound requests may not go, beyond what no
 * outbound request may reach (Outbound\Addresses) -- and the few private
 * addresses they may.
 *
 *     #[OutboundPolicy(deny: ['198.51.100.0/24', '2001:db8:4::/48'])]
 *     final class Kernel implements Application { ... }
 *
 * tinvar-spec.md, "Outbound requests": "platform-owned prefixes declared in
 * `#[OutboundPolicy]`" are refused -- the platform's own public addresses,
 * its load balancers and its NAT64 prefix, which a tenant's webhook URL
 * must not be able to make the platform call. Declared on the application
 * class, as attributes first has it: there is no config file to keep.
 *
 * `allow` opens soft ranges -- private, loopback, link-local, unique local
 * -- for a service of the application's own on its network, one prefix at
 * a time. It never opens a hard one (metadata, the unspecified address,
 * multicast, an IPv6 address carrying an IPv4 one), and `deny` wins over
 * it: a prefix both deny and allow name is refused.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class OutboundPolicy
{
    /** @var list<Cidr> */
    public array $deny;

    /** @var list<Cidr> */
    public array $allow;

    /**
     * @param array<array-key, mixed> $deny  prefixes, as '198.51.100.0/24'; an address alone is its own /32 or /128
     * @param array<array-key, mixed> $allow the same
     */
    public function __construct(array $deny = [], array $allow = [])
    {
        $this->deny = self::prefixes($deny);
        $this->allow = self::prefixes($allow);
    }

    /**
     * The policy a class declares, or the default -- the built-in ranges
     * alone -- when it declares none.
     *
     * Every `deny` in its lineage counts, as checks do (ClassAttributes);
     * `allow` is read off the class itself, since inheriting a way past a
     * control is the direction that fails open.
     */
    public static function of(string $class): self
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("No class {$class}.");
        }

        $own = new \ReflectionClass($class)->getAttributes(self::class)[0] ?? null;
        $policy = $own?->newInstance() ?? new self();
        $deny = [];
        foreach (ClassAttributes::of($class, self::class) as $declared) {
            $deny = [...$deny, ...$declared->newInstance()->deny];
        }

        return clone($policy, ['deny' => $deny]);
    }

    /**
     * Why $address may not be connected to, as a reason code, or null when
     * it may.
     */
    public function refusal(string $address): ?string
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return 'address_invalid';
        }

        $special = Addresses::classify($packed);
        if ($special !== null && $special[1]) {
            return $special[0];
        }
        foreach ($this->deny as $prefix) {
            if ($prefix->contains($packed)) {
                return 'platform';
            }
        }
        if ($special === null) {
            return null;
        }
        foreach ($this->allow as $prefix) {
            if ($prefix->contains($packed)) {
                return null;
            }
        }

        return $special[0];
    }

    /**
     * The address $target may be connected to: its literal address, or its
     * name resolved once -- refused when any address it resolves to is,
     * since a name that answers with a private address beside a public one
     * is either misconfigured or an attempt.
     *
     * @throws OutboundRefused
     * @throws OutboundFailed when the name does not resolve
     */
    public function vet(Target $target, Resolver $resolver): string
    {
        $addresses = $target->literal !== null ? [$target->literal] : $resolver->resolve($target->host);
        if ($addresses === []) {
            throw new OutboundFailed(OutboundFailed::DNS, "{$target->host} does not resolve.");
        }

        foreach ($addresses as $address) {
            $why = $this->refusal($address);
            if ($why !== null) {
                throw new OutboundRefused($why, "{$target->origin()} is refused: it " . ($target->literal !== null ? 'is' : 'resolves to') . " an address outbound requests may not reach ({$why}).");
            }
        }

        return $addresses[0];
    }

    /**
     * @param array<array-key, mixed> $prefixes
     * @return list<Cidr>
     */
    private static function prefixes(array $prefixes): array
    {
        $parsed = [];
        foreach ($prefixes as $prefix) {
            // Checked, not trusted to its declared type: an attribute's
            // arguments are whatever its author wrote.
            if (!\is_string($prefix)) {
                throw new \InvalidArgumentException('An outbound policy names prefixes as strings, as \'198.51.100.0/24\'.');
            }
            $parsed[] = Cidr::parse($prefix);
        }

        return $parsed;
    }
}
