<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * The proxies a deployment puts in front of this server, pinned by network,
 * and the client address read through them (ADR 0134).
 *
 * Behind a TLS terminator, a load balancer or an ingress, every connection
 * comes from the proxy, so a throttle keyed on the peer counts the whole
 * internet as one caller. X-Forwarded-For names the client, but it is
 * whatever the client sent unless a proxy you control wrote it, so it is
 * read here only when the peer is a pinned proxy. Then it is read from the
 * right: each proxy appends the address it was connected from, so the
 * right-most hop that is not itself a pinned proxy is the last address a
 * proxy you control saw. Everything to its left is the client's own text,
 * and is never believed.
 *
 * Fails closed. A list with an entry that is not a network is refused
 * whole, at boot, by `TINVAR_TRUSTED_PROXIES` and the entry's place, never
 * its text. It also refuses a prefix of 0, which would let anyone write the
 * header. A hop that is not a bare address (a port, brackets, a zone,
 * `unknown`), a trusted peer that sends no header, or a header made only of
 * proxies resolves to no address. Throttle charges that to its one shared
 * bucket for callers it cannot name, which a misconfigured proxy makes
 * conspicuous at once, rather than handing the caller a fresh bucket.
 * Unless the peer is a pinned proxy, the peer is the address, as it was
 * before.
 */
final readonly class TrustedProxies
{
    /** The variable the framework reads the list from (FrameworkConfig). */
    public const string ENV = 'TINVAR_TRUSTED_PROXIES';

    /**
     * The same networks, space-separated, as `tinvar serve` hands them to
     * FrankenPHP for its Caddyfile's `trusted_proxies static`: Caddy splits
     * on spaces, and a comma-separated list would stop it starting.
     */
    public const string CADDY_ENV = 'TINVAR_TRUSTED_PROXY_RANGES';

    /** @param list<array{string, int}> $networks each network's packed address and its prefix length */
    private function __construct(private array $networks) {}

    /** No proxy: the peer is always the client. */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param list<string> $entries CIDR networks, `10.0.0.0/8` or `fd00::/8`, or single addresses
     * @throws \InvalidArgumentException naming the first malformed entry by its place
     */
    public static function of(array $entries): self
    {
        $networks = [];
        foreach ($entries as $i => $entry) {
            $network = self::network($entry);
            if ($network === null) {
                $at = $i + 1;

                throw new \InvalidArgumentException(
                    self::ENV . "'s {$at}" . self::ordinal($at) . ' entry is not a network: an IPv4 or IPv6 address, '
                    . 'with a prefix length after a / if it is a range, no bits set past it, and never a prefix of 0.',
                );
            }
            $networks[] = $network;
        }

        return new self($networks);
    }

    /**
     * Each network as CIDR, written the one way, space-separated: Caddy's
     * `trusted_proxies static` takes this (CADDY_ENV). '' for none.
     */
    public function caddyRanges(): string
    {
        return implode(' ', array_map(
            static fn (array $network): string => inet_ntop($network[0]) . '/' . $network[1],
            $this->networks,
        ));
    }

    public function isEmpty(): bool
    {
        return $this->networks === [];
    }

    /** Whether an address lies in a pinned network. Not an address: false. */
    public function trusts(string $address): bool
    {
        $packed = self::packed($address);

        return $packed !== null && $this->trustsPacked($packed);
    }

    /**
     * The address of the client this request came from: the peer, unless
     * the peer is a pinned proxy, and then the right-most X-Forwarded-For
     * hop that is not one. '' when no address can be resolved, which a
     * Throttle charges to its shared bucket.
     */
    public function clientAddress(Request $request): string
    {
        $peer = $request->remoteAddress ?? '';
        if ($this->networks === []) {
            return $peer;
        }

        $packed = self::packed($peer);
        if ($packed === null) {
            return '';
        }
        if (!$this->trustsPacked($packed)) {
            return $peer;
        }

        $forwarded = $request->header('x-forwarded-for');
        if ($forwarded === null || trim($forwarded, " \t") === '') {
            return '';
        }

        $hops = explode(',', $forwarded);
        for ($i = \count($hops) - 1; $i >= 0; $i--) {
            $hop = self::packed(trim($hops[$i], " \t"));
            if ($hop === null) {
                return '';
            }
            if (!$this->trustsPacked($hop)) {
                return (string) inet_ntop($hop);
            }
        }

        return '';
    }

    /**
     * The Host a request was sent to: X-Forwarded-Host when the peer is a
     * pinned proxy and sent one, read from the right as X-Forwarded-For is
     * -- the value the proxy nearest this server wrote, never one the
     * client put ahead of it -- and the Host header otherwise. Raw, as sent:
     * HostName normalizes it, and Domains holds it to the hosts the
     * application serves (ADR 0149).
     */
    public function host(Request $request): ?string
    {
        $host = $request->header('host');
        if ($this->networks === []) {
            return $host;
        }

        $packed = self::packed($request->remoteAddress ?? '');
        if ($packed === null || !$this->trustsPacked($packed)) {
            return $host;
        }

        $forwarded = $request->header('x-forwarded-host');
        if ($forwarded === null || trim($forwarded, " \t") === '') {
            return $host;
        }
        $hops = explode(',', $forwarded);

        return trim($hops[\count($hops) - 1], " \t");
    }

    private function trustsPacked(string $packed): bool
    {
        foreach ($this->networks as [$network, $prefix]) {
            if (\strlen($network) === \strlen($packed) && self::masked($packed, $prefix) === $network) {
                return true;
            }
        }

        return false;
    }

    /** @return array{string, int}|null */
    private static function network(string $entry): ?array
    {
        $parts = explode('/', $entry);
        if (\count($parts) > 2) {
            return null;
        }

        $packed = self::packed($parts[0]);
        if ($packed === null) {
            return null;
        }
        $bits = \strlen($packed) * 8;

        if (!isset($parts[1])) {
            return [$packed, $bits];
        }

        // Digits alone, no sign, no leading zero, no space: a prefix is
        // written one way.
        if (preg_match('/^(?:0|[1-9]\d{0,2})\z/', $parts[1]) !== 1) {
            return null;
        }
        $prefix = (int) $parts[1];

        // A prefix of 0 trusts every address, and the header becomes the
        // client's to write; bits past the prefix are a mistyped network.
        if ($prefix < 1 || $prefix > $bits || self::masked($packed, $prefix) !== $packed) {
            return null;
        }

        return [$packed, $prefix];
    }

    /**
     * The packed form of a bare address -- an IPv4-mapped IPv6 one as the
     * IPv4 one it maps, so the two spellings are one address -- or null.
     */
    private static function packed(string $address): ?string
    {
        // inet_pton() would take neither a port nor brackets, but says
        // nothing of what it refused; written out, nothing else gets past.
        // And filter_var() as well: inet_pton() takes `198.051.100.1`, a
        // spelling some parsers read as octal.
        if ($address === '' || preg_match('/^[0-9A-Fa-f:.]{2,45}\z/', $address) !== 1
            || filter_var($address, \FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = @inet_pton($address);
        if ($packed === false) {
            return null;
        }

        if (\strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($packed, 12);
        }

        return $packed;
    }

    private static function masked(string $packed, int $prefix): string
    {
        $out = '';
        foreach (str_split($packed) as $i => $byte) {
            $keep = max(0, min(8, $prefix - $i * 8));
            $out .= \chr(\ord($byte) & (0xff << (8 - $keep)) & 0xff);
        }

        return $out;
    }

    private static function ordinal(int $n): string
    {
        return match (true) {
            $n % 100 >= 11 && $n % 100 <= 13 => 'th',
            $n % 10 === 1 => 'st',
            $n % 10 === 2 => 'nd',
            $n % 10 === 3 => 'rd',
            default => 'th',
        };
    }
}
