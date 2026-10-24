<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/**
 * An address prefix: `10.0.0.0/8`, `fc00::/7`, or one address alone.
 *
 * Kept as packed bytes, so an IPv4 prefix can never match an IPv6 address
 * by its text -- `::ffff:10.0.0.1` is sixteen bytes and `10.0.0.0/8` four.
 * An IPv4 address carried inside an IPv6 one is refused by its own rule
 * (Addresses), never by being read as the IPv4 address it carries.
 */
final readonly class Cidr
{
    private function __construct(
        /** The prefix's bytes, with every bit past it cleared. */
        public string $network,
        public int $bits,
    ) {}

    /** @throws \InvalidArgumentException for anything but an address or an address and a prefix length */
    public static function parse(string $cidr): self
    {
        $parts = explode('/', trim($cidr));
        $packed = \count($parts) <= 2 ? @inet_pton($parts[0]) : false;
        if ($packed === false) {
            throw new \InvalidArgumentException("'{$cidr}' is not an address prefix: write it as 203.0.113.0/24 or 2001:db8::/32.");
        }

        $max = \strlen($packed) * 8;
        $bits = $max;
        if (isset($parts[1])) {
            if (preg_match('/^(0|[1-9]\d{0,2})\z/', $parts[1]) !== 1 || (int) $parts[1] > $max) {
                throw new \InvalidArgumentException("'{$cidr}' has no prefix length between 0 and {$max}.");
            }
            $bits = (int) $parts[1];
        }

        return new self(self::mask($packed, $bits), $bits);
    }

    /** Whether the packed address $address falls under this prefix. */
    public function contains(string $address): bool
    {
        return \strlen($address) === \strlen($this->network) && self::mask($address, $this->bits) === $this->network;
    }

    /** $packed with every bit past the first $bits cleared. */
    private static function mask(string $packed, int $bits): string
    {
        $whole = intdiv($bits, 8);
        $masked = substr($packed, 0, $whole);
        if ($whole < \strlen($packed)) {
            $rest = $bits % 8;
            $masked .= \chr(\ord($packed[$whole]) & (0xFF << (8 - $rest)) & 0xFF);
            $masked .= str_repeat("\0", \strlen($packed) - $whole - 1);
        }

        return $masked;
    }
}
