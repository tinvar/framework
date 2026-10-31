<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Http\Request;
use Tinvar\Mail\MailBounced;
use Tinvar\Mail\MailComplained;
use Tinvar\Mail\MailDelivered;
use Tinvar\Mail\MailEvent;
use Tinvar\Mail\MailWebhook;
use Tinvar\Mail\MailWebhookDelivery;

/**
 * SendGrid's signed Event Webhook (ADR 0147): an ECDSA P-256 signature,
 * SHA-256, DER and base64, over the timestamp header followed by the raw
 * body, checked with ext-openssl against the verification key SendGrid
 * shows -- a public key, so nothing secret is kept here at all.
 *
 *     X-Twilio-Email-Event-Webhook-Signature: MEUCIQ...
 *     X-Twilio-Email-Event-Webhook-Timestamp: 1792836000
 *
 * The timestamp is held to ten minutes of now. The nonce is a digest of
 * what was signed, not of the signature: an ECDSA signature can be
 * rewritten into another that verifies as well, and a nonce taken from it
 * would let the same delivery in twice.
 *
 * Each delivery is a batch: `bounce` is a hard bounce, unless its `type`
 * is `blocked` -- a server refusing for now -- `spamreport` a complaint,
 * `delivered` a delivery. The message id is SendGrid's X-Message-Id, the
 * part of `sg_message_id` before its first dot.
 */
final readonly class SendGridWebhook implements MailWebhook
{
    public const int TOLERANCE = 600;

    public const string SIGNATURE = 'x-twilio-email-event-webhook-signature';

    public const string TIMESTAMP = 'x-twilio-email-event-webhook-timestamp';

    private \OpenSSLAsymmetricKey $key;

    public function __construct(string $publicKey, private int $tolerance = self::TOLERANCE)
    {
        $this->key = self::publicKey($publicKey) ?? throw new \InvalidArgumentException('A SendGrid verification key is the base64 of an ECDSA P-256 public key.');
    }

    public function provesContent(): bool
    {
        return true;
    }

    public function name(): string
    {
        return SendGridTransport::NAME;
    }

    /**
     * The verification key, from the base64 of its DER -- as SendGrid shows
     * it -- or from PEM; null for anything that is not a P-256 public key.
     */
    public static function publicKey(string $given): ?\OpenSSLAsymmetricKey
    {
        $given = trim($given);
        if (!str_starts_with($given, '-----BEGIN')) {
            $der = base64_decode((string) preg_replace('/\s+/', '', $given), true);
            if ($der === false || $der === '') {
                return null;
            }
            $given = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        }
        $key = openssl_pkey_get_public($given);
        if ($key === false) {
            return null;
        }
        $details = openssl_pkey_get_details($key);

        return \is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? null) === 'prime256v1' ? $key : null;
    }

    public function receive(Request $request, string $rawBody, int $now): MailWebhookDelivery
    {
        $timestamp = $request->header(self::TIMESTAMP) ?? '';
        $signature = base64_decode((string) $request->header(self::SIGNATURE), true);
        if (preg_match('/^[1-9]\d{0,11}\z/', $timestamp) !== 1 || $signature === false || $signature === '' || \strlen($signature) > 128) {
            throw Payload::unverified('The delivery carries no SendGrid signature this endpoint can read.');
        }
        if (openssl_verify($timestamp . $rawBody, $signature, $this->key, OPENSSL_ALGO_SHA256) !== 1) {
            throw Payload::unverified('The delivery\'s signature does not verify.');
        }
        Payload::assertFresh((int) $timestamp, $now, $this->tolerance);

        return new MailWebhookDelivery(hash('sha256', $timestamp . "\n" . $rawBody), (int) $timestamp + $this->tolerance, $this->events(Payload::json($rawBody), $now));
    }

    /**
     * @param array<array-key, mixed> $batch
     * @return list<MailEvent>
     */
    private function events(array $batch, int $now): array
    {
        $events = [];
        foreach (array_is_list($batch) ? $batch : [] as $item) {
            $item = Payload::map($item);
            $recipient = Payload::email($item['email'] ?? null);
            if ($recipient === null) {
                continue;
            }
            $id = Payload::text($item['sg_message_id'] ?? null, 256);
            $messageId = $id === null ? null : explode('.', $id, 2)[0];
            $at = Payload::time($item['timestamp'] ?? null, $now);
            $event = match ($item['event'] ?? null) {
                'bounce' => new MailBounced(SendGridTransport::NAME, $recipient, $messageId, $at, ($item['type'] ?? 'bounce') !== 'blocked', Payload::text($item['reason'] ?? null)),
                'spamreport' => new MailComplained(SendGridTransport::NAME, $recipient, $messageId, $at),
                'delivered' => new MailDelivered(SendGridTransport::NAME, $recipient, $messageId, $at),
                default => null,
            };
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
