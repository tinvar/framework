<?php

declare(strict_types=1);

namespace Tinvar\Observability;

use Tinvar\Dev\Loopback;
use Tinvar\Http\Request;

/**
 * Who may read /_health, /_ready and /_metrics: "Internal network or
 * token", as the spec's endpoint table has it.
 *
 * A caller on one of `networks` -- loopback unless told otherwise -- or one
 * presenting the token as `Authorization: Bearer <token>`. The address is
 * the connecting peer's, never X-Forwarded-For, which the caller writes
 * itself. Metrics name routes, queues and how busy the server is, which is
 * reconnaissance for whoever can read them, so with neither configured
 * nothing but loopback is let in.
 *
 * The token is kept as its hash and compared in constant time, and has to
 * be long enough not to be guessed: 32 characters at least.
 *
 * Let in by its address alone, a caller must look like what it is: a probe
 * from that network, not traffic passed on from somewhere else.
 *
 * - A request carrying a proxy's headers -- Forwarded, X-Forwarded-*,
 *   X-Real-IP, Via -- was passed on by something in front, whoever called
 *   it; it needs the token. A proxy that adds none of them is not told
 *   apart from its caller here: behind one, give ProbeAccess a token and no
 *   networks, or block `/_*` at the proxy.
 * - A loopback peer (all of 127.0.0.0/8, ::1, and their mapped forms, as
 *   the networks test them) must also name this machine in its Host. A page
 *   that points a name of its own at 127.0.0.1 -- DNS rebinding -- reaches
 *   the probes from its visitor's loopback address, but still names its own
 *   domain. A request with no Host header is a client on the machine
 *   itself.
 * - A peer elsewhere on a network it was given must not be a browser. A
 *   page can point a name of its own at this server's address on that
 *   network as well, and reach the probes from its visitor's address
 *   there; the Host it names is no different in kind from a scraper's name
 *   for this server, but the browser says what it is -- Fetch Metadata on
 *   every request, Origin on a cross-origin one -- and no probe or scraper
 *   sends either.
 */
final class ProbeAccess
{
    /** What a proxy adds to what it passes on: a request carrying one came through something in front. */
    private const array PROXY_HEADERS = ['forwarded', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-real-ip', 'via'];

    /** What a browser sends with what it fetches, and a probe or a scraper never does. */
    private const array BROWSER_HEADERS = ['sec-fetch-site', 'sec-fetch-mode', 'sec-fetch-dest', 'origin'];

    /** @var list<array{string, int}> each network as its packed address and prefix length */
    private readonly array $networks;

    private readonly ?string $tokenHash;

    /**
     * @param list<string> $networks CIDR blocks, as `10.0.0.0/8` or `fd00::/8`
     */
    public function __construct(
        array $networks = ['127.0.0.0/8', '::1/128'],
        #[\SensitiveParameter]
        ?string $token = null,
    ) {
        $parsed = [];
        foreach ($networks as $network) {
            $parsed[] = self::network($network);
        }
        $this->networks = $parsed;

        if ($token !== null && \strlen($token) < 32) {
            throw new \InvalidArgumentException('A probe token is 32 characters or more.');
        }
        $this->tokenHash = $token === null ? null : hash('sha256', $token, true);
    }

    public function allows(Request $request): bool
    {
        if ($this->tokenHash !== null) {
            $header = $request->header('authorization') ?? '';
            if (preg_match('/^Bearer\s+(\S+)\z/i', trim($header), $m) === 1
                && hash_equals($this->tokenHash, hash('sha256', $m[1], true))) {
                return true;
            }
        }

        $address = self::packed($request->remoteAddress ?? '');
        if ($address === null) {
            return false;
        }

        foreach (self::PROXY_HEADERS as $name) {
            if ($request->header($name) !== null) {
                return false;
            }
        }

        $host = $request->header('host');
        if ($host !== null && self::loopback($address) && !Loopback::host($host)) {
            return false;
        }

        // A browser on the machine itself is held to the Host rule above;
        // one anywhere else, to saying it is one.
        if (!self::loopback($address)) {
            foreach (self::BROWSER_HEADERS as $name) {
                if ($request->header($name) !== null) {
                    return false;
                }
            }
        }

        foreach ($this->networks as [$network, $prefix]) {
            if (self::within($address, $network, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a packed address is this machine's, by the same test the networks use. */
    private static function loopback(string $address): bool
    {
        return self::within($address, (string) inet_pton('127.0.0.0'), 8) || $address === inet_pton('::1');
    }

    /** @return array{string, int} */
    private static function network(string $cidr): array
    {
        $parts = explode('/', $cidr, 2);
        $prefix = $parts[1] ?? null;
        $packed = self::packed($parts[0]);
        if ($packed === null) {
            throw new \InvalidArgumentException("'{$cidr}' is not a network.");
        }

        $bits = \strlen($packed) * 8;
        if ($prefix === null) {
            return [$packed, $bits];
        }
        if (preg_match('/^(0|[1-9][0-9]{0,2})\z/', $prefix) !== 1 || (int) $prefix > $bits) {
            throw new \InvalidArgumentException("'{$cidr}' has no valid prefix length.");
        }

        return [$packed, (int) $prefix];
    }

    /** An address as packed bytes, an IPv4-mapped IPv6 address as the IPv4 one; null for anything else. */
    private static function packed(string $address): ?string
    {
        $packed = @inet_pton(trim($address, '[]'));
        if ($packed === false) {
            return null;
        }
        if (\strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($packed, 12);
        }

        return $packed;
    }

    private static function within(string $address, string $network, int $prefix): bool
    {
        if (\strlen($address) !== \strlen($network)) {
            return false;
        }

        $bytes = intdiv($prefix, 8);
        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        $bits = $prefix % 8;
        if ($bits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $bits)) & 0xff;

        return (\ord($address[$bytes]) & $mask) === (\ord($network[$bytes]) & $mask);
    }
}
