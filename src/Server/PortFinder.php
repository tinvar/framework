<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Random\Randomizer;

/**
 * The address `tinvar serve` listens on: the host and port asked for, or a
 * free port picked at random between 6000 and 7000.
 *
 * "Free" is decided by trying the port, not by guessing: nothing answers a
 * connection on it, and a listener can open on it and close again. Random
 * rather than the first free one, so two servers started together -- a test
 * run beside a developer's own -- do not race for the same port. There is
 * still a window between the check and the server binding; a server that
 * loses it fails to start and says so, rather than quietly sharing a port.
 */
final class PortFinder
{
    public const int FROM = 6000;
    public const int TO = 7000;

    /** @var \Closure(string, int): bool */
    private readonly \Closure $canBind;

    /**
     * @param (\Closure(string, int): bool)|null $canBind whether a listener can open on host and
     *        port; binds for real by default
     */
    public function __construct(
        ?\Closure $canBind = null,
        private readonly Randomizer $random = new Randomizer(),
    ) {
        $this->canBind = $canBind ?? self::bind(...);
    }

    /**
     * The port to listen on.
     *
     * @param string|null $port the --port value as typed, or null for a random free one
     */
    public function find(string $host, ?string $port = null): int
    {
        self::assertHost($host);

        if ($port !== null) {
            if (preg_match('/^[1-9][0-9]{0,4}\z/', $port) !== 1 || (int) $port > 65535) {
                throw new ServeException("--port must be a whole number from 1 to 65535; got '{$port}'.");
            }
            if (!($this->canBind)($host, (int) $port)) {
                throw new ServeException(
                    "Port {$port} on {$host} is in use or not available to this user. "
                    . 'Stop whatever holds it, choose another --port, or leave --port off to pick a free one.',
                );
            }

            return (int) $port;
        }

        // Every port in the range, each tried once, in random order.
        foreach ($this->random->shuffleArray(range(self::FROM, self::TO)) as $candidate) {
            if (($this->canBind)($host, $candidate)) {
                return $candidate;
            }
        }

        throw new ServeException(sprintf(
            'No free port between %d and %d on %s: all %d are in use or cannot be bound. Pass --port to choose one.',
            self::FROM,
            self::TO,
            $host,
            self::TO - self::FROM + 1,
        ));
    }

    /**
     * A host to listen on: an IPv4 or IPv6 address, or a host name such as
     * localhost. Anything else -- a URL, a port glued on, spaces -- is a
     * mistake to say so about before trying to bind it.
     */
    public static function assertHost(string $host): void
    {
        $valid = filter_var($host, FILTER_VALIDATE_IP) !== false
            || (preg_match('/^[A-Za-z0-9.-]{1,253}\z/', $host) === 1
                && preg_match('/^[0-9.]+\z/', $host) !== 1
                && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false);

        if (!$valid) {
            throw new ServeException("--host must be an IP address or a host name, such as 127.0.0.1; got '{$host}'.");
        }
    }

    /** The host as it goes in a URL or a socket address: IPv6 in brackets. */
    public static function hostForUrl(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$host}]" : $host;
    }

    /** Whether a TCP listener can open on host and port right now, with nothing else already serving it. */
    public static function bind(string $host, int $port): bool
    {
        // Asked first, because binding alone cannot tell. PHP's listeners set
        // SO_REUSEADDR, and under it macOS lets a specific address bind a
        // port a wildcard listener holds, and the reverse: a port another
        // server was serving on every interface read as free, and two
        // servers shared it.
        if (self::answers($host, $port)) {
            return false;
        }

        // Refusals are the answer here, not warnings.
        set_error_handler(static fn (): bool => true);
        try {
            $server = stream_socket_server('tcp://' . self::hostForUrl($host) . ':' . $port, $errno, $error);
        } finally {
            restore_error_handler();
        }

        if ($server === false) {
            return false;
        }
        fclose($server);

        return true;
    }

    /**
     * Whether something accepts a connection on host and port.
     *
     * Every interface is asked through loopback, where any listener on the
     * port answers; an IPv6 one, through IPv4 loopback as well, since it
     * takes IPv4 connections too unless told otherwise.
     */
    public static function answers(string $host, int $port): bool
    {
        $packed = @inet_pton($host);
        $addresses = match (true) {
            $packed === @inet_pton('0.0.0.0') => ['127.0.0.1'],
            $packed === @inet_pton('::') => ['::1', '127.0.0.1'],
            default => [$host],
        };

        foreach ($addresses as $address) {
            // Refused is the usual answer, not a warning.
            set_error_handler(static fn (): bool => true);
            try {
                $socket = fsockopen(self::hostForUrl($address), $port, $errno, $error, 0.2);
            } finally {
                restore_error_handler();
            }

            if ($socket !== false) {
                fclose($socket);

                return true;
            }
        }

        return false;
    }
}
