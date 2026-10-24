<?php

declare(strict_types=1);

namespace Tinvar\Live;

use Tinvar\Support\Signer;

/**
 * The hub's keys and audience: what the hub is configured with, and what
 * PHP signs with to match.
 *
 * Derived from the application key, each for its own purpose, as every key
 * here is (Signer) -- so there is no second secret to generate, keep and
 * rotate, and rotating the application key rotates these. Or taken from the
 * environment, for a hub of its own whose keys are provisioned separately.
 *
 * They reach the hub as environment variables its configuration reads --
 * the shop's Caddyfile reads environment() -- and never reach a client: the
 * one thing a client is handed is an access token signed with the
 * subscriber key, in a cookie it cannot read. Nor the renderer, which
 * refuses to start holding either (ADR 0048).
 *
 * Which hub: FrankenPHP's own, in the process serving the request, unless
 * TINVAR_LIVE_HUB_URL names one run as a service of its own -- what more
 * than one app server needs, since the built-in hub's `local` transport
 * reaches only the subscribers of the server that published (ADR 0030).
 * Then every process publishes to that hub over HTTP, and the app's server
 * passes /.well-known/mercure on to it (TINVAR_LIVE_HUB_UPSTREAM, for the
 * Caddyfile), so a client still subscribes on its own origin.
 */
final readonly class HubSettings
{
    public const string SUBSCRIBER_PURPOSE = 'live-hub-subscriber';
    public const string PUBLISHER_PURPOSE = 'live-hub-publisher';

    public const string SUBSCRIBER_ENV = 'TINVAR_LIVE_SUBSCRIBER_KEY';
    public const string PUBLISHER_ENV = 'TINVAR_LIVE_PUBLISHER_KEY';
    public const string AUDIENCE_ENV = 'TINVAR_LIVE_AUDIENCE';

    /** A hub of its own: its URL as this server reaches it. */
    public const string HUB_URL_ENV = 'TINVAR_LIVE_HUB_URL';

    /** That hub's origin, for the app server's proxy of /.well-known/mercure. */
    public const string HUB_UPSTREAM_ENV = 'TINVAR_LIVE_HUB_UPSTREAM';

    /** Where a client may connect over plain HTTP: this machine, and nowhere else. */
    private const array LOOPBACK = ['127.0.0.1', '[::1]', 'localhost'];

    /**
     * What the hub's access tokens are for (`aud`, its `resource_identifier`)
     * when nothing says otherwise. An identifier the hub compares, never a
     * URL anything fetches, and the protocol wants an absolute https URL;
     * `.invalid` is reserved (RFC 2606), so it can never name a server
     * someone else runs. A hub with a public URL of its own is named by it.
     */
    public const string DEFAULT_AUDIENCE = 'https://live.tinvar.invalid/.well-known/mercure';

    public function __construct(
        #[\SensitiveParameter]
        public string $subscriberKey,
        #[\SensitiveParameter]
        public string $publisherKey,
        public string $audience = self::DEFAULT_AUDIENCE,
        public ?string $hubUrl = null,
    ) {
        if ($hubUrl !== null) {
            self::upstreamOf($hubUrl);
        }
    }

    /** Derived from the application key, for a hub configured from environment(). */
    public static function derive(#[\SensitiveParameter] string $appKey, string $audience = self::DEFAULT_AUDIENCE): self
    {
        return new self(
            bin2hex(new Signer($appKey, self::SUBSCRIBER_PURPOSE)->sign('mercure')),
            bin2hex(new Signer($appKey, self::PUBLISHER_PURPOSE)->sign('mercure')),
            $audience,
        );
    }

    /**
     * From the environment where it says, derived from the application key
     * where it does not -- the same answer the process that configured the
     * hub came to, given either.
     */
    public static function resolve(#[\SensitiveParameter] string $appKey): self
    {
        $derived = self::derive($appKey, self::env(self::AUDIENCE_ENV) ?? self::DEFAULT_AUDIENCE);

        return new self(
            self::env(self::SUBSCRIBER_ENV) ?? $derived->subscriberKey,
            self::env(self::PUBLISHER_ENV) ?? $derived->publisherKey,
            $derived->audience,
            self::env(self::HUB_URL_ENV),
        );
    }

    /**
     * The hub this process publishes to, or null where there is none.
     *
     * A hub of its own when one is named, from every process alike; else
     * FrankenPHP's, in-process, where this runs inside it; else -- for a
     * worker or a scheduler beside FrankenPHP, outside it -- the built-in
     * hub of the server at $sameServer, over HTTP; else none, as under
     * php -S.
     *
     * @param string|null $sameServer the hub URL of the FrankenPHP server this
     *        application runs in, for a process outside it
     * @param (\Closure(string, array<string, string>, string): int)|null $post
     *        how an update goes over HTTP, as Mercure::remote() takes it
     */
    public function hub(?string $sameServer = null, ?\Closure $post = null): ?Hub
    {
        if ($this->hubUrl !== null) {
            return Mercure::remote($this->hubUrl, $this->publisherKey, $this->subscriberKey, $this->audience, post: $post);
        }
        if (\function_exists('mercure_publish')) {
            return Mercure::builtIn($this->subscriberKey, $this->audience);
        }

        return $sameServer === null
            ? null
            : Mercure::remote($sameServer, $this->publisherKey, $this->subscriberKey, $this->audience, post: $post);
    }

    /**
     * For the hub's process, never the renderer's.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [
            self::SUBSCRIBER_ENV => $this->subscriberKey,
            self::PUBLISHER_ENV => $this->publisherKey,
            self::AUDIENCE_ENV => $this->audience,
            // Empty for the built-in hub, which the Caddyfile reads as "run
            // your own": set where it is not, by a shell, it would send every
            // subscriber somewhere this process does not publish.
            self::HUB_UPSTREAM_ENV => $this->hubUrl === null ? '' : self::upstreamOf($this->hubUrl),
        ];
    }

    /**
     * The origin of a hub's URL -- scheme, host and port -- for a proxy to
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
            || ($parts['path'] ?? '') !== Mercure::PATH
        ) {
            throw new \InvalidArgumentException(
                self::HUB_URL_ENV . ' must be the hub\'s http(s) URL ending in ' . Mercure::PATH . ', with no credentials, query or fragment.',
            );
        }

        $loopback = \in_array($host, self::LOOPBACK, true)
            || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.'));
        if ($scheme === 'http' && !$loopback) {
            throw new \InvalidArgumentException(
                self::HUB_URL_ENV . " names {$host} over plain HTTP: a hub on another machine is reached over https, "
                . 'or its tokens cross the network in the clear.',
            );
        }

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
