<?php

declare(strict_types=1);

namespace Tinvar\Config;

/**
 * The URL of a live hub run as a service of its own (TINVAR_LIVE_HUB_URL),
 * and what it may be.
 *
 * Config's, not the hub's: the framework's config refuses a malformed one
 * at boot, before anything builds a hub, and config stands below the live
 * updates that use it (ADR 0114). The hub's settings and its Mercure
 * client read the same checks and path from here.
 */
final class HubUrl
{
    /** A hub of its own: its URL as this server reaches it. */
    public const string ENV = 'TINVAR_LIVE_HUB_URL';

    /** Where the Mercure protocol's hub answers, on any host. */
    public const string PATH = '/.well-known/mercure';

    /** Where a client may connect over plain HTTP: this machine, and nowhere else. */
    private const array LOOPBACK = ['127.0.0.1', '[::1]', 'localhost'];

    /**
     * The origin of the hub at $hubUrl, for the app server's proxy to
     * pass its path on to, once the URL is one this will publish to.
     *
     * Refused, so the application does not start: anything but http(s), a
     * URL carrying credentials, a query or a fragment, or a path other than
     * the protocol's own -- the proxy forwards /.well-known/mercure as it is,
     * so a hub anywhere else would answer nothing. And plain HTTP to any host
     * but this machine: every publisher token PHP sends and every access
     * cookie a subscriber's request carries would cross the network in the
     * clear, and either is enough to hear or forge invalidations until it
     * runs out.
     */
    public static function upstreamOf(string $hubUrl): string
    {
        $parts = parse_url($hubUrl);
        $scheme = \is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host = \is_array($parts) ? strtolower($parts['host'] ?? '') : '';

        if (
            !\is_array($parts)
            || !\in_array($scheme, ['http', 'https'], true)
            || preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)\z/', $host) !== 1
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== self::PATH
        ) {
            throw new \InvalidArgumentException(
                self::ENV . ' must be the hub\'s http(s) URL ending in ' . self::PATH . ', with no credentials, query or fragment.',
            );
        }

        $loopback = \in_array($host, self::LOOPBACK, true)
            || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.'));
        if ($scheme === 'http' && !$loopback) {
            throw new \InvalidArgumentException(
                self::ENV . " names {$host} over plain HTTP: a hub on another machine is reached over https, "
                . 'or its tokens cross the network in the clear.',
            );
        }

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
