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
 * Resend's webhook: Svix-signed deliveries (SvixSignature), each within
 * five minutes of now, its `svix-id` its nonce (ADR 0147).
 *
 * `email.bounced` is a hard bounce -- Resend sends it when the reader's
 * server refused the message for good -- unless its `bounce.type` says
 * `Transient`; `email.complained` a complaint; `email.delivered` a
 * delivery. The rest -- sent, opened, clicked, delayed -- are not this
 * endpoint's, and are taken and ignored.
 *
 * Resend names the message's readers, not the one the event is about:
 * `data.to` is the whole To list. So an event is read for each address in
 * it, and where the message named more than one reader -- in To, Cc or
 * Bcc -- each bounce and complaint is ambiguous: dispatched, but nobody is
 * suppressed, since one reader's mailbox refusing the message says nothing
 * of the others'. Only a message to one reader is suppressed by its event.
 */
final readonly class ResendWebhook implements MailWebhook
{
    public const int TOLERANCE = 300;

    private Secret $secret;

    public function __construct(#[\SensitiveParameter] Secret|string $secret, private int $tolerance = self::TOLERANCE)
    {
        $this->secret = $secret instanceof Secret ? $secret : new Secret($secret);
        if (SvixSignature::key($this->secret->reveal()) === null) {
            throw new \InvalidArgumentException('A Resend webhook secret is the whsec_... secret Resend shows.');
        }
    }

    public function provesContent(): bool
    {
        return true;
    }

    public function name(): string
    {
        return ResendTransport::NAME;
    }

    public function receive(Request $request, string $rawBody, int $now): MailWebhookDelivery
    {
        $id = $request->header('svix-id') ?? '';
        $timestamp = $request->header('svix-timestamp') ?? '';
        if (preg_match(SvixSignature::ID, $id) !== 1 || preg_match('/^[1-9]\d{0,11}\z/', $timestamp) !== 1) {
            throw Payload::unverified('The delivery carries no Svix id and timestamp this endpoint can read.');
        }
        $expected = SvixSignature::sign((string) SvixSignature::key($this->secret->reveal()), $id, (int) $timestamp, $rawBody);
        if (!SvixSignature::matches($request->header('svix-signature'), $expected)) {
            throw Payload::unverified('The delivery\'s signature does not verify.');
        }
        Payload::assertFresh((int) $timestamp, $now, $this->tolerance);

        return new MailWebhookDelivery($id, (int) $timestamp + $this->tolerance, $this->events(Payload::json($rawBody), $now));
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return list<MailEvent>
     */
    private function events(array $payload, int $now): array
    {
        $data = Payload::map($payload['data'] ?? null);
        $type = $payload['type'] ?? null;
        $messageId = Payload::text($data['email_id'] ?? null, 128);
        $at = Payload::time($payload['created_at'] ?? null, $now);
        $bounce = Payload::map($data['bounce'] ?? null);

        $to = self::addresses($data['to'] ?? null);
        $ambiguous = \count(array_unique([...$to, ...self::addresses($data['cc'] ?? null), ...self::addresses($data['bcc'] ?? null)])) > 1;

        $events = [];
        foreach ($to as $recipient) {
            $event = match ($type) {
                'email.bounced' => new MailBounced(
                    ResendTransport::NAME,
                    $recipient,
                    $messageId,
                    $at,
                    ($bounce['type'] ?? 'Permanent') !== 'Transient',
                    Payload::text($bounce['message'] ?? null),
                    $ambiguous,
                ),
                'email.complained' => new MailComplained(ResendTransport::NAME, $recipient, $messageId, $at, ambiguous: $ambiguous),
                'email.delivered' => new MailDelivered(ResendTransport::NAME, $recipient, $messageId, $at),
                default => null,
            };
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * The addresses a field names, lowercased, each once: a list, or one.
     *
     * @return list<string>
     */
    private static function addresses(mixed $field): array
    {
        $addresses = [];
        foreach (\is_array($field) ? $field : [$field] as $value) {
            $address = Payload::email($value);
            if ($address !== null) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
