<?php

declare(strict_types=1);

namespace Tinvar\Push;

use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Support\Signer;

/**
 * Sends one push message to one subscription: encrypted for that browser
 * (PushEncryption, RFC 8291), signed with the application's VAPID key
 * (RFC 8292), and POSTed to its push service through OutboundHttp
 * (tinvar-spec.md, "Outbound requests") -- the endpoint resolved once,
 * vetted, pinned, TLS verified, no redirect followed and no body read --
 * and only to a push service the application names (PushServices).
 *
 * What came back is the status alone; the caller -- SendPush -- removes a
 * subscription its service says is gone (404, 410) and tries again what it
 * says to (429, 5xx).
 */
final readonly class WebPush
{
    /** Seconds one send may take. */
    public const float TIMEOUT = 10.0;

    /** The longest a push service is asked to hold an undelivered message: four weeks. */
    public const int MAX_TTL = 2_419_200;

    public const array URGENCIES = ['very-low', 'low', 'normal', 'high'];

    /** @param (\Closure(): int)|null $clock seconds since the epoch; time() unless given */
    public function __construct(
        private OutboundHttp $http,
        private VapidKeys $keys,
        private PushServices $services = new PushServices(),
        private ?\Closure $clock = null,
    ) {}

    /**
     * @param string $payload what the service worker's push event reads: at most PushEncryption::MAX_PAYLOAD bytes
     * @param int $ttl seconds the push service holds it for a browser that is offline
     * @param string|null $topic replaces an undelivered message of the same topic: up to 32 base64url characters
     * @throws OutboundRefused for an endpoint not at a named push service, or refused by the policy
     * @throws \Tinvar\Outbound\OutboundFailed when the request did not complete
     */
    public function send(PushSubscription $subscription, #[\SensitiveParameter] string $payload, int $ttl = 86_400, string $urgency = 'normal', ?string $topic = null): OutboundResponse
    {
        if ($ttl < 0 || $ttl > self::MAX_TTL) {
            throw new \InvalidArgumentException('A push is held for between 0 seconds and four weeks.');
        }
        if (!\in_array($urgency, self::URGENCIES, true)) {
            throw new \InvalidArgumentException("'{$urgency}' is not an urgency: " . implode(', ', self::URGENCIES) . '.');
        }
        if ($topic !== null && preg_match('/^[A-Za-z0-9_-]{1,32}\z/', $topic) !== 1) {
            throw new \InvalidArgumentException('A topic is up to 32 base64url characters.');
        }
        if (!$this->services->allows($subscription->endpoint)) {
            throw new OutboundRefused('push_service', 'The subscription\'s endpoint is not at a push service the application names.');
        }

        $body = PushEncryption::encrypt(
            $payload,
            (string) Signer::decode($subscription->p256dh),
            (string) Signer::decode($subscription->auth),
        );
        $now = $this->clock === null ? time() : ($this->clock)();
        $headers = [
            'content-type' => 'application/octet-stream',
            'content-encoding' => 'aes128gcm',
            'ttl' => (string) $ttl,
            'urgency' => $urgency,
            'authorization' => $this->keys->authorization($subscription->audience(), $now),
        ];
        if ($topic !== null) {
            $headers['topic'] = $topic;
        }

        return $this->http->send(new OutboundRequest('POST', $subscription->endpoint, $headers, $body, timeout: self::TIMEOUT, readBody: false, destinations: $this->services->destinations()));
    }
}
