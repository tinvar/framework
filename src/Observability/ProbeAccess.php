<?php

declare(strict_types=1);

namespace Tinvar\Observability;

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
 */
final class ProbeAccess
{
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

        foreach ($this->networks as [$network, $prefix]) {
            if (self::within($address, $network, $prefix)) {
                return true;
            }
        }

        return false;
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
