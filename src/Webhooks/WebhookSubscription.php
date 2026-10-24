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
 */
final readonly class WebhookSubscription
{
    public function __construct(
        public string $url,
        #[\SensitiveParameter]
        public string $secret,
    ) {
        try {
            Target::parse($url);
        } catch (OutboundRefused $e) {
            throw new \InvalidArgumentException("A webhook is not delivered to that URL ({$e->reason}): {$e->getMessage()}", 0, $e);
        }
        if (\strlen($secret) < 32) {
            throw new \InvalidArgumentException('A webhook signing secret is at least 32 bytes.');
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['url' => Target::parse($this->url)->origin() . '/[redacted]', 'secret' => '[redacted]'];
    }
}
