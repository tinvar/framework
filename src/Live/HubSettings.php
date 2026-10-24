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
 * subscriber key, in a cookie it cannot read.
 */
final readonly class HubSettings
{
    public const string SUBSCRIBER_PURPOSE = 'live-hub-subscriber';
    public const string PUBLISHER_PURPOSE = 'live-hub-publisher';

    public const string SUBSCRIBER_ENV = 'TINVAR_LIVE_SUBSCRIBER_KEY';
    public const string PUBLISHER_ENV = 'TINVAR_LIVE_PUBLISHER_KEY';
    public const string AUDIENCE_ENV = 'TINVAR_LIVE_AUDIENCE';

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
    ) {}

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
        );
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
        ];
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
