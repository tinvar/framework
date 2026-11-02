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
 *
 * A peer at one of this machine's own addresses is never a proxy, whatever
 * range holds it. The shipped deployments pin a range the server's own
 * address is in -- compose's subnet holds the container's, 10.0.0.0/8 the
 * pod's -- and the renderers share that network namespace: a compromised
 * renderer that connects to its own container's or pod's address arrives
 * from it, and would otherwise name any client it liked, a throttle bucket
 * of its own for every guess. Loopback is left to the list: a terminator
 * on the same machine connects from there, and is pinned by name.
 *
 * A range is not proof of a proxy, though: the ranges a deployment can pin
 * hold more than its ingress -- the node's own address, which the kubelet's
 * probes and every hostNetwork pod connect from and most network plugins
 * let through any NetworkPolicy, the bridge or node address a pod's
 * connection to its own Service comes back from (hairpin), compose's
 * gateway, which a published port's userland proxy connects from. Given a
 * secret (`TINVAR_TRUSTED_PROXY_SECRET`), a peer in the range is a proxy
 * only when it also sends that secret in `X-Tinvar-Proxy`, which the
 * deployment's own proxy is set to send and to overwrite whatever a client
 * sent in it. The renderers hold no secret, and nothing else on the node
 * knows it.
 *
 * So the secret is required wherever a range is pinned: a list with any
 * entry wider than one address (/32 for IPv4, /128 for IPv6) and no secret
 * is refused, and the boot with it, rather than left open to the node,
 * hairpin and SNAT peers a range holds. A list of single addresses -- a
 * terminator on loopback, or at an address nothing else connects from --
 * needs none.
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

    /** The variable the proof a proxy sends is read from (FrameworkConfig). */
    public const string SECRET_ENV = 'TINVAR_TRUSTED_PROXY_SECRET';

    /** The request header a pinned proxy proves itself with, when a secret is set. */
    public const string SECRET_HEADER = 'x-tinvar-proxy';

    /** The shortest secret taken: long enough not to be guessed. */
    public const int MIN_SECRET = 32;

    /**
     * @param list<array{string, int}> $networks each network's packed address and its prefix length
     * @param list<string> $own this machine's own addresses, packed, loopback left out: never a proxy
     * @param string|null $secretHash the SHA-256 of the secret a proxy must send, or null for none
     */
    private function __construct(private array $networks, private array $own = [], private ?string $secretHash = null) {}

    /** No proxy: the peer is always the client. */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param list<string> $entries CIDR networks, `10.0.0.0/8` or `fd00::/8`, or single addresses
     * @param list<string>|null $own this machine's own addresses, which are never a proxy; read
     *        from its interfaces (ownAddresses()) unless given. Loopback among them is left out.
     * @param string|null $secret what a peer in a pinned network must also send in SECRET_HEADER to be
     *        taken for a proxy; null or '' for none, the range alone deciding
     * @throws \InvalidArgumentException naming the first malformed entry by its place, or for a
     *         secret too short to hold, or one given with no network for it to prove, or for
     *         none given with a range wider than one address
     */
    public static function of(array $entries, ?array $own = null, #[\SensitiveParameter] ?string $secret = null): self
    {
        $secret = $secret === null || $secret === '' ? null : $secret;
        if ($secret !== null && \strlen($secret) < self::MIN_SECRET) {
            throw new \InvalidArgumentException(self::SECRET_ENV . ' is ' . self::MIN_SECRET . ' characters or more.');
        }
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
        if ($secret === null) {
            foreach ($networks as $i => [$packed, $prefix]) {
                if ($prefix < \strlen($packed) * 8) {
                    $at = $i + 1;

                    throw new \InvalidArgumentException(
                        self::SECRET_ENV . ' is required: ' . self::ENV . "'s {$at}" . self::ordinal($at) . ' entry is a range wider than one '
                        . 'address, which holds more peers than the proxy -- the node\'s own address, a hairpin or NAT gateway. Set the secret '
                        . '(32 characters or more) and have the proxy send it in X-Tinvar-Proxy, or pin the proxy\'s single address (/32 or /128).',
                    );
                }
            }
        }
        if ($networks === []) {
            if ($secret !== null) {
                throw new \InvalidArgumentException(self::SECRET_ENV . ' is set, and ' . self::ENV . ' pins no proxy to send it.');
            }

            return new self([]);
        }

        $packed = [];
        foreach ($own ?? self::ownAddresses() as $address) {
            $address = self::packed($address);
            if ($address !== null && !self::loopback($address)) {
                $packed[] = $address;
            }
        }

        return new self($networks, array_values(array_unique($packed)), $secret === null ? null : hash('sha256', $secret, true));
    }

    /**
     * This machine's own unicast addresses -- this network namespace's, in
     * a container or a pod -- loopback left out; none where PHP cannot list
     * its interfaces. Read once per process.
     *
     * @return list<string>
     */
    public static function ownAddresses(): array
    {
        static $own = null;
        if ($own !== null) {
            return $own;
        }

        $found = [];
        $interfaces = \function_exists('net_get_interfaces') ? @net_get_interfaces() : false;
        foreach (\is_array($interfaces) ? $interfaces : [] as $interface) {
            foreach (\is_array($interface['unicast'] ?? null) ? $interface['unicast'] : [] as $unicast) {
                $address = \is_array($unicast) && \is_string($unicast['address'] ?? null) ? $unicast['address'] : null;
                $packed = $address === null ? null : self::packed($address);
                if ($packed !== null && !self::loopback($packed)) {
                    $found[] = (string) inet_ntop($packed);
                }
            }
        }

        return $own = array_values(array_unique($found));
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
        if (!$this->trustsPeer($packed, $request)) {
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
     *
     * A header the proxy sent is its answer even when that answer is empty:
     * `X-Forwarded-Host: shop.test, ` names the empty host, '', which is no
     * host anyone serves -- never the Host header behind it, and never no
     * host at all.
     */
    public function host(Request $request): ?string
    {
        $host = $request->header('host');
        if ($this->networks === []) {
            return $host;
        }

        $packed = self::packed($request->remoteAddress ?? '');
        if ($packed === null || !$this->trustsPeer($packed, $request)) {
            return $host;
        }

        $forwarded = $request->header('x-forwarded-host');
        if ($forwarded === null) {
            return $host;
        }
        $hops = explode(',', $forwarded);

        return trim($hops[\count($hops) - 1], " \t");
    }

    /**
     * Whether a peer is a pinned proxy: in a pinned network, not this machine
     * itself, and -- given a secret -- sending it.
     */
    private function trustsPeer(string $packed, Request $request): bool
    {
        if (\in_array($packed, $this->own, true) || !$this->trustsPacked($packed)) {
            return false;
        }
        if ($this->secretHash === null) {
            return true;
        }
        $proof = $request->header(self::SECRET_HEADER);

        // Compared as hashes, in constant time: the length of what was sent
        // says nothing of the secret's.
        return $proof !== null && $proof !== '' && hash_equals($this->secretHash, hash('sha256', $proof, true));
    }

    /** Whether a packed address is loopback: 127.0.0.0/8 or ::1. */
    private static function loopback(string $packed): bool
    {
        return \strlen($packed) === 4 ? $packed[0] === "\x7f" : $packed === str_repeat("\0", 15) . "\x01";
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
