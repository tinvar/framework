<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/**
 * The address space an outbound request may not reach on its own: what
 * tinvar-spec.md names -- "private, loopback, link-local, metadata,
 * IPv4-mapped IPv6, NAT64 and `0.0.0.0`" -- and the rest of IANA's
 * special-purpose registries beside them.
 *
 * Each prefix is either hard or soft. A hard one is refused whatever the
 * application allows: the unspecified address and multicast reach nothing
 * a request should; the cloud metadata services hand out the machine's own
 * credentials; and an IPv6 address carrying an IPv4 one (mapped,
 * compatible, translated, NAT64, 6to4, Teredo) is refused outright rather
 * than judged by the address inside it, since an allowlist entry is
 * written as the address it means. A soft one -- private, loopback,
 * link-local, documentation -- is refused unless the application's
 * #[OutboundPolicy] allows it, as a service of its own on its network.
 *
 * The most specific prefix decides, so 169.254.169.254 is metadata (hard)
 * inside link-local (soft), and ::1 is loopback inside the IPv4-compatible
 * block. IPv6 outside 2000::/3, the global unicast space, is reserved.
 */
final class Addresses
{
    /** @var list<array{string, string, bool}> prefix, reason, hard */
    private const array RULES = [
        // IPv4.
        ['0.0.0.0/8', 'unspecified', true],
        ['10.0.0.0/8', 'private', false],
        ['100.64.0.0/10', 'shared', false],
        ['100.100.100.200/32', 'metadata', true],
        ['127.0.0.0/8', 'loopback', false],
        ['169.254.0.0/16', 'link_local', false],
        ['169.254.169.254/32', 'metadata', true],
        ['169.254.170.2/32', 'metadata', true],
        ['169.254.170.23/32', 'metadata', true],
        ['168.63.129.16/32', 'metadata', true],
        ['172.16.0.0/12', 'private', false],
        ['192.0.0.0/24', 'special', false],
        ['192.0.2.0/24', 'documentation', false],
        ['192.88.99.0/24', 'special', false],
        ['192.168.0.0/16', 'private', false],
        ['198.18.0.0/15', 'benchmarking', false],
        ['198.51.100.0/24', 'documentation', false],
        ['203.0.113.0/24', 'documentation', false],
        ['224.0.0.0/4', 'multicast', true],
        ['240.0.0.0/4', 'reserved', true],

        // IPv6: everything outside global unicast first, then what inside
        // it is special.
        ['::/3', 'reserved', true],
        ['4000::/2', 'reserved', true],
        ['8000::/1', 'reserved', true],
        ['::/128', 'unspecified', true],
        ['::1/128', 'loopback', false],
        ['::/96', 'ipv4_compatible', true],
        ['::ffff:0:0/96', 'ipv4_mapped', true],
        ['::ffff:0:0:0/96', 'ipv4_translated', true],
        ['64:ff9b::/96', 'nat64', true],
        ['64:ff9b:1::/48', 'nat64', true],
        ['2001::/23', 'special', false],
        ['2001::/32', 'teredo', true],
        ['2001:db8::/32', 'documentation', false],
        ['2002::/16', '6to4', true],
        ['3fff::/20', 'documentation', false],
        ['fc00::/7', 'unique_local', false],
        ['fd00:ec2::23/128', 'metadata', true],
        ['fd00:ec2::254/128', 'metadata', true],
        ['fe80::/10', 'link_local', false],
        ['fec0::/10', 'site_local', false],
        ['ff00::/8', 'multicast', true],
    ];

    /** @var list<array{Cidr, string, bool}>|null */
    private static ?array $parsed = null;

    /**
     * The most specific special-purpose prefix $address falls under, as its
     * reason and whether it is hard; null for an ordinary public address.
     *
     * @param string $address packed, as inet_pton() gives it
     * @return array{string, bool}|null
     */
    public static function classify(string $address): ?array
    {
        $match = null;
        foreach (self::rules() as [$cidr, $reason, $hard]) {
            if ($cidr->contains($address) && ($match === null || $cidr->bits > $match[0]->bits)) {
                $match = [$cidr, $reason, $hard];
            }
        }

        return $match === null ? null : [$match[1], $match[2]];
    }

    /** @return list<array{Cidr, string, bool}> */
    private static function rules(): array
    {
        if (self::$parsed === null) {
            self::$parsed = [];
            foreach (self::RULES as [$prefix, $reason, $hard]) {
                self::$parsed[] = [Cidr::parse($prefix), $reason, $hard];
            }
        }

        return self::$parsed;
    }
}
