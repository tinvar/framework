<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\Target;

/**
 * Where a tenant asked to be sent the application's webhooks, and the
 * secret their deliveries are signed with -- the application's to issue
 * and keep, and the receiver's to verify with (WebhookSignature).
 *
 * The URL is held to what OutboundHttp accepts when it is made, so a
 * subscription that could never be delivered to is refused when it is set
 * up rather than on every try; where it points is vetted when each
 * delivery is sent, since DNS can change in between.
 *
 * A stored subscription (DatabaseWebhookSubscriptions, ADR 0197) may also
 * be paused -- `active` false, so a delivery due to it is ended rather than
 * sent -- and, while its secret is rotated, carry the secret it replaces,
 * which signs the header every receiver reads until the overlap ends, the
 * new one beside it in a header of its own.
 */
final readonly class WebhookSubscription implements \JsonSerializable
{
    public function __construct(
        public string $url,
        #[\SensitiveParameter]
        public string $secret,
        /** The secret this one replaces, which also signs while a rotation's overlap lasts. */
        #[\SensitiveParameter]
        public ?string $previousSecret = null,
        /** False for a paused or disabled subscription: nothing is sent to it. */
        public bool $active = true,
    ) {
        try {
            Target::parse($url);
        } catch (OutboundRefused $e) {
            throw new \InvalidArgumentException("A webhook is not delivered to that URL ({$e->reason}): {$e->getMessage()}", 0, $e);
        }
        if (\strlen($secret) < 32 || ($previousSecret !== null && \strlen($previousSecret) < 32)) {
            throw new \InvalidArgumentException('A webhook signing secret is at least 32 bytes.');
        }
    }

    /**
     * The secret the signature header every receiver reads is made with:
     * while a rotation overlaps, the one being replaced, which a receiver
     * of any version still holds (ADR 0197); this one otherwise.
     */
    public function signingSecret(): string
    {
        return $this->previousSecret ?? $this->secret;
    }

    /** The new secret, signing beside the old in its own header while a rotation overlaps; null otherwise. */
    public function nextSecret(): ?string
    {
        return $this->previousSecret === null || $this->previousSecret === $this->secret ? null : $this->secret;
    }

    /** @return array<string, string|bool> */
    public function __debugInfo(): array
    {
        return [
            'url' => Target::parse($this->url)->origin() . '/[redacted]',
            'secret' => '[redacted]',
            'previousSecret' => $this->previousSecret === null ? 'none' : '[redacted]',
            'active' => $this->active,
        ];
    }

    /**
     * What json_encode() writes, which a log line may be: the same as a
     * dump, never the public properties themselves.
     *
     * @return array<string, string|bool>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
