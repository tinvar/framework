<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Config\Secret;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Mail\MailBounced;
use Tinvar\Mail\MailComplained;
use Tinvar\Mail\MailDelivered;
use Tinvar\Mail\MailEvent;
use Tinvar\Mail\MailWebhook;
use Tinvar\Mail\MailWebhookDelivery;

/**
 * Mailgun's webhook (ADR 0147): each event POSTed as JSON with a
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
 *
 * The HMAC covers the timestamp and the token, never the event: it proves a
 * delivery came from someone holding the key, not what it reports. So an
 * event must name its id and its message's id, and the endpoint acts on it
 * only when the sent-mail log says that message went to that reader through
 * Mailgun (provesContent() is false; MailEventsEndpoint requires the log).
 * The key is 32 characters or more (MIN_KEY), as Mailgun's are. And the
 * signing key is this endpoint's alone: shared with another app or
 * endpoint on the account, every delivery signed for those is a triple that
 * verifies here too.
 */
final readonly class MailgunWebhook implements MailWebhook
{
    public const int TOLERANCE = 900;

    /**
     * The shortest signing key taken. Mailgun's are 32 hex digits or more;
     * a shorter one is a mistake, and one guessed in a few tries makes any
     * delivery verify.
     */
    public const int MIN_KEY = 32;

    private Secret $key;

    public function __construct(#[\SensitiveParameter] Secret|string $key, private int $tolerance = self::TOLERANCE)
    {
        $this->key = $key instanceof Secret ? $key : new Secret($key);
        if ($this->key->length() < self::MIN_KEY) {
            throw new \InvalidArgumentException('A Mailgun webhook signing key is ' . self::MIN_KEY . ' characters or more: the HTTP webhook signing key Mailgun shows.');
        }
    }

    public function provesContent(): bool
    {
        return false;
    }

    public function namesReader(): bool
    {
        return true;
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
        $messageId = Payload::text($headers['message-id'] ?? null, 191);
        $event = $data['event'] ?? null;
        // The HMAC proves a delivery happened, not what it says: an event
        // acted on names its own id and the message it is about, which the
        // endpoint holds to the sent-mail log.
        if (\in_array($event, ['failed', 'complained', 'delivered'], true) && (Payload::text($data['id'] ?? null, 128) === null || $messageId === null)) {
            throw new HttpException(400, 'A Mailgun event names its id and its message\'s id.', 'bad_request');
        }
        $messageId = $messageId === null ? null : trim($messageId, '<>');
        $at = Payload::time($data['timestamp'] ?? null, $now);
        $status = Payload::map($data['delivery-status'] ?? null);
        $reason = Payload::text($status['description'] ?? null) ?? Payload::text($status['message'] ?? null) ?? Payload::text($data['reason'] ?? null);

        return match ($event) {
            'failed' => [new MailBounced(MailgunTransport::NAME, $recipient, $messageId, $at, ($data['severity'] ?? null) === 'permanent', $reason)],
            'complained' => [new MailComplained(MailgunTransport::NAME, $recipient, $messageId, $at)],
            'delivered' => [new MailDelivered(MailgunTransport::NAME, $recipient, $messageId, $at)],
            default => [],
        };
    }
}
