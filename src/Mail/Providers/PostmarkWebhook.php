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
 * Postmark's webhook (ADR 0146). Postmark signs nothing: what it documents
 * is HTTP basic auth, the credentials in the webhook URL it is given --
 * `https://user:password@host/_mail/events/postmark` -- which it sends as
 * an Authorization header, over TLS. Both are compared in constant time,
 * and a delivery without them is a 401 before its body is read.
 *
 * With no signed id or time to hold a replay to, the nonce is a digest of
 * the body, kept for a week: a delivery sent again within it is taken once.
 * And since a password proves who sent a delivery and nothing of what it
 * says, the endpoint acts on its events only where the sent-mail log has
 * the message going to that reader through Postmark (provesContent()).
 * The credentials are this endpoint's alone, used for no other webhook.
 *
 * RecordType `Bounce` is a hard bounce for a HardBounce, a BadEmailAddress
 * or a ManuallyDeactivated -- and for any Postmark marks Inactive, which
 * it will not send to again itself -- a complaint for a SpamNotification
 * or a SpamComplaint, and a soft bounce otherwise; `SpamComplaint` is a
 * complaint; `Delivery` a delivery. Opens, clicks and the rest are taken
 * and ignored.
 */
final readonly class PostmarkWebhook implements MailWebhook
{
    /** How long a delivery's digest is kept: Postmark retries a failed webhook for hours, not days. */
    public const int KEEP = 604_800;

    /** Bounce types that are an address that will not take mail. */
    public const array HARD = ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated'];

    /** Bounce types that are a complaint, as a bounce webhook may carry it. */
    public const array COMPLAINTS = ['SpamNotification', 'SpamComplaint'];

    private Secret $password;

    public function __construct(private string $username, #[\SensitiveParameter] Secret|string $password)
    {
        $this->password = $password instanceof Secret ? $password : new Secret($password);
        if (preg_match('/^[A-Za-z0-9._-]{1,64}\z/', $username) !== 1) {
            throw new \InvalidArgumentException('A Postmark webhook username is 1 to 64 letters, digits, ., _ or -.');
        }
        if ($this->password->length() < PostmarkConfig::MIN_WEBHOOK_PASSWORD) {
            throw new \InvalidArgumentException('A Postmark webhook password is ' . PostmarkConfig::MIN_WEBHOOK_PASSWORD . ' characters or more.');
        }
    }

    public function provesContent(): bool
    {
        return false;
    }

    public function name(): string
    {
        return PostmarkTransport::NAME;
    }

    public function receive(Request $request, string $rawBody, int $now): MailWebhookDelivery
    {
        $header = $request->header('authorization') ?? '';
        $given = preg_match('/^Basic ([A-Za-z0-9+\/]+={0,2})\z/i', trim($header), $m) === 1 ? base64_decode($m[1], true) : false;
        $expected = "{$this->username}:{$this->password->reveal()}";
        // As digests, so neither the length nor the place a guess goes
        // wrong shows in how long the answer takes.
        if (!\is_string($given) || !hash_equals(hash('sha256', $expected, true), hash('sha256', $given, true))) {
            throw Payload::unverified('The delivery does not carry the webhook\'s credentials.');
        }

        return new MailWebhookDelivery(hash('sha256', $rawBody), $now + self::KEEP, $this->events(Payload::json($rawBody), $now));
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return list<MailEvent>
     */
    private function events(array $payload, int $now): array
    {
        $messageId = Payload::text($payload['MessageID'] ?? null, 128);
        $record = $payload['RecordType'] ?? null;
        $type = \is_string($payload['Type'] ?? null) ? $payload['Type'] : '';

        if ($record === 'Delivery') {
            $recipient = Payload::email($payload['Recipient'] ?? null);

            return $recipient === null ? [] : [new MailDelivered(PostmarkTransport::NAME, $recipient, $messageId, Payload::time($payload['DeliveredAt'] ?? null, $now))];
        }

        $recipient = Payload::email($payload['Email'] ?? null);
        if ($recipient === null) {
            return [];
        }
        $at = Payload::time($payload['BouncedAt'] ?? null, $now);

        if ($record === 'SpamComplaint' || ($record === 'Bounce' && \in_array($type, self::COMPLAINTS, true))) {
            return [new MailComplained(PostmarkTransport::NAME, $recipient, $messageId, $at, $type === '' ? null : $type)];
        }
        if ($record === 'Bounce') {
            $permanent = \in_array($type, self::HARD, true) || ($payload['Inactive'] ?? false) === true;

            return [new MailBounced(PostmarkTransport::NAME, $recipient, $messageId, $at, $permanent, Payload::text($payload['Details'] ?? $payload['Description'] ?? null))];
        }

        return [];
    }
}
