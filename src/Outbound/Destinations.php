<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

/**
 * Where one kind of request may go: the hosts, and the ports, a feature
 * sends to, held on its request (OutboundRequest::$destinations) and
 * checked by OutboundHttp at every hop, redirects included, before a name
 * is looked up.
 *
 *     Destinations::hosts(['fcm.googleapis.com', '*.push.services.mozilla.com'])
 *
 * The policy (OutboundPolicy) says where no request may go; this says where
 * a request of one kind may: a push to the push services, a token request
 * to its provider's host, a webhook to anywhere public. A host is a name,
 * lowercased and in punycode as the URL parser writes it, or `*.` and a
 * name for every name below it -- never the name itself, and never a
 * wildcard anywhere else. A literal address is named as itself.
 *
 * Ports are those given, 443 alone unless told; https only unless
 * `plainHttp` says otherwise, which a test's local server needs and a
 * deployment's provider never should.
 */
final readonly class Destinations
{
    /** @var list<string> */
    public array $hosts;

    /** @var list<int> */
    public array $ports;

    /**
     * @param list<string> $hosts
     * @param list<int> $ports
     */
    private function __construct(array $hosts, array $ports, public bool $plainHttp)
    {
        if ($hosts === []) {
            throw new \InvalidArgumentException('Destinations name at least one host.');
        }
        foreach ($hosts as $host) {
            $name = str_starts_with($host, '*.') ? substr($host, 2) : $host;
            if ($name === '' || str_contains($name, '*') || ($name !== strtolower($name))
                || (preg_match('/^(?!-)[a-z0-9.-]+\z/', $name) !== 1 && filter_var(trim($name, '[]'), FILTER_VALIDATE_IP) === false)) {
                throw new \InvalidArgumentException("'{$host}' is not a host destinations name: a lowercased name, '*.' and a name, or an address.");
            }
        }
        if ($ports === []) {
            throw new \InvalidArgumentException('Destinations name at least one port.');
        }
        foreach ($ports as $port) {
            if ($port < 1 || $port > 65535) {
                throw new \InvalidArgumentException("Port {$port} cannot be connected to.");
            }
        }
        $this->hosts = $hosts;
        $this->ports = $ports;
    }

    /**
     * @param list<string> $hosts
     * @param list<int> $ports
     */
    public static function hosts(array $hosts, array $ports = [443], bool $plainHttp = false): self
    {
        return new self($hosts, $ports, $plainHttp);
    }

    /** The one origin $url names: its host and port, its scheme's alone. */
    public static function originOf(string $url): self
    {
        $target = Target::parse($url);

        return new self([$target->literal ?? $target->host], [$target->port], $target->scheme === 'http');
    }

    /**
     * Why $target is not one of these, as a reason code, or null when it is.
     */
    public function refusal(Target $target): ?string
    {
        if ($target->scheme !== 'https' && !$this->plainHttp) {
            return 'scheme';
        }
        if (!\in_array($target->port, $this->ports, true)) {
            return 'port';
        }
        $host = $target->host;
        foreach ($this->hosts as $allowed) {
            if (str_starts_with($allowed, '*.')
                ? $target->literal === null && str_ends_with($host, substr($allowed, 1))
                : trim($allowed, '[]') === $host) {
                return null;
            }
        }

        return 'destination';
    }

    /** @throws OutboundRefused when $target is not one of these */
    public function assertAllows(Target $target): void
    {
        $why = $this->refusal($target);
        if ($why !== null) {
            throw new OutboundRefused($why, "{$target->origin()} is not a destination this request may go to ({$why}).");
        }
    }
}
