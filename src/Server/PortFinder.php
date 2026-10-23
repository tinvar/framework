<?php

declare(strict_types=1);

namespace Tinvar\Server;

use Random\Randomizer;

/**
 * The address `tinvar serve` listens on: the host and port asked for, or a
 * free port picked at random between 6000 and 7000.
 *
 * "Free" is decided by binding the port, not by guessing: a port is tried by
 * opening a listener on it and closing it again. Random rather than the
 * first free one, so two servers started together -- a test run beside a
 * developer's own -- do not race for the same port. There is still a window
 * between the check and the server binding; a server that loses it fails to
 * start and says so, rather than quietly sharing a port.
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

    /** Whether a TCP listener can open on host and port right now. */
    public static function bind(string $host, int $port): bool
    {
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
}
