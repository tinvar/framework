<?php

declare(strict_types=1);

namespace Tinvar\Webhooks;

use Tinvar\Http\Request;

/**
 * A delivery that has verified: what a #[Webhook] handler is given.
 *
 * By the time one exists the signature, the timestamp and the nonce have
 * all been checked, and the handler is running as the tenant the delivery
 * was for.
 */
final readonly class WebhookCall
{
    public function __construct(
        public Request $request,
        public string $body,
        public string $tenant,
        public string $nonce,
        public int $timestamp,
    ) {}

    /**
     * The body as JSON.
     *
     * @return array<array-key, mixed>
     * @throws \JsonException when it is not a JSON object or array
     */
    public function json(): array
    {
        $data = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \JsonException('The webhook body is JSON, and not an object or an array.');
        }

        return $data;
    }
}
