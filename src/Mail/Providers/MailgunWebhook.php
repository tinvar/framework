<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Config\Secret;
use Tinvar\Http\Request;
use Tinvar\Mail\MailBounced;
use Tinvar\Mail\MailComplained;
use Tinvar\Mail\MailDelivered;
use Tinvar\Mail\MailEvent;
use Tinvar\Mail\MailWebhook;
use Tinvar\Mail\MailWebhookDelivery;

/**
 * Mailgun's webhook (ADR 0146): each event POSTed as JSON with a
 * `signature` of a timestamp, a random token and the HMAC-SHA256 of the
 * two, keyed with the HTTP webhook signing key. The HMAC is checked in
 * constant time, the timestamp held to fifteen minutes of now, and the
 * token -- which Mailgun makes new for every POST -- is the nonce, so a
 * captured delivery is taken once.
 *
 * `failed` with severity `permanent` is a hard bounce, `temporary` a soft
 * one; `complained` a complaint; `delivered` a delivery. The message id is
 * the one Mailgun gave the send, without its angle brackets. Opens, clicks
 * and the rest are taken and ignored.
 */
final readonly class MailgunWebhook implements MailWebhook
{
    public const int TOLERANCE = 900;

    private Secret $key;

    public function __construct(#[\SensitiveParameter] Secret|string $key, private int $tolerance = self::TOLERANCE)
    {
        $this->key = $key instanceof Secret ? $key : new Secret($key);
    }

    public function name(): string
    {
        return MailgunTransport::NAME;
    }

    /** The signature of a timestamp and a token, as Mailgun makes it. */
    public static function sign(#[\SensitiveParameter] string $key, string $timestamp, string $token): string
    {
        return hash_hmac('sha256', $timestamp . $token, $key);
    }

    public function receive(Request $request, string $rawBody, int $now): MailWebhookDelivery
    {
        // The JSON is read for the signature alone first; nothing else in it
        // is looked at until the signature holds.
        $payload = Payload::json($rawBody);
        $signature = Payload::map($payload['signature'] ?? null);
        $timestamp = $signature['timestamp'] ?? null;
        $token = $signature['token'] ?? null;
        $mac = $signature['signature'] ?? null;
        if (!\is_string($timestamp) || preg_match('/^[1-9]\d{0,11}\z/', $timestamp) !== 1
            || !\is_string($token) || preg_match('/^[A-Za-z0-9]{16,128}\z/', $token) !== 1
            || !\is_string($mac) || preg_match('/^[0-9a-f]{64}\z/', $mac) !== 1) {
            throw Payload::unverified('The delivery carries no Mailgun signature this endpoint can read.');
        }
        if (!hash_equals(self::sign($this->key->reveal(), $timestamp, $token), $mac)) {
            throw Payload::unverified('The delivery\'s signature does not verify.');
        }
        Payload::assertFresh((int) $timestamp, $now, $this->tolerance);

        return new MailWebhookDelivery($token, (int) $timestamp + $this->tolerance, $this->events(Payload::map($payload['event-data'] ?? null), $now));
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<MailEvent>
     */
    private function events(array $data, int $now): array
    {
        $recipient = Payload::email($data['recipient'] ?? null);
        if ($recipient === null) {
            return [];
        }
        $headers = Payload::map(Payload::map($data['message'] ?? null)['headers'] ?? null);
        $messageId = Payload::text($headers['message-id'] ?? null, 128);
        $messageId = $messageId === null ? null : trim($messageId, '<>');
        $at = Payload::time($data['timestamp'] ?? null, $now);
        $status = Payload::map($data['delivery-status'] ?? null);
        $reason = Payload::text($status['description'] ?? null) ?? Payload::text($status['message'] ?? null) ?? Payload::text($data['reason'] ?? null);

        return match ($data['event'] ?? null) {
            'failed' => [new MailBounced(MailgunTransport::NAME, $recipient, $messageId, $at, ($data['severity'] ?? null) === 'permanent', $reason)],
            'complained' => [new MailComplained(MailgunTransport::NAME, $recipient, $messageId, $at)],
            'delivered' => [new MailDelivered(MailgunTransport::NAME, $recipient, $messageId, $at)],
            default => [],
        };
    }
}
