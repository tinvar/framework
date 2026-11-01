<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Mail\MailBounced;
use Tinvar\Mail\MailComplained;
use Tinvar\Mail\MailDelivered;
use Tinvar\Mail\MailEvent;
use Tinvar\Mail\MailWebhook;
use Tinvar\Mail\MailWebhookDelivery;
use Tinvar\Outbound\Destinations;
use Tinvar\Outbound\OutboundFailed;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\OutboundRequest;

/**
 * SES's events, as SNS delivers them to an HTTPS subscription (ADR 0147).
 *
 * Every message is signed by SNS: an RSA signature -- SHA1 for
 * SignatureVersion 1, SHA256 for 2 -- over the message's fields in SNS's
 * canonical order, with the key of the certificate at its SigningCertURL,
 * which SnsCertificates fetches from SNS's own hosts alone. That proves
 * AWS sent it; not that it is the application's, since SNS signs every
 * topic of every account with the same certificates. So the TopicArn must
 * be one of $topics, the application's own, or the message is refused
 * before its signature is even fetched. Its Timestamp, which the signature
 * covers, is held to an hour of now, and its MessageId is the nonce.
 *
 * A SubscriptionConfirmation for one of $topics is confirmed by fetching
 * its SubscribeURL -- an SNS host's, held as the certificate's is -- once
 * its signature verifies; nothing else is fetched on a message's word.
 *
 * A Notification's Message is SES's event: `notificationType` from an SES
 * identity's notifications, or `eventType` from a configuration set's
 * event destination, both read. Bounce is a hard bounce for each
 * bouncedRecipient when bounceType is Permanent, a soft one otherwise;
 * Complaint a complaint for each complainedRecipient; Delivery a delivery
 * for each recipient. The message id is SES's, as SesTransport handed it
 * back. Raw message delivery must be off on the subscription, as it is by
 * default: the envelope is what is signed.
 */
final readonly class SesWebhook implements MailWebhook
{
    public const int TOLERANCE = 3600;

    public const string TOPIC_ARN = '/^arn:aws(?:-cn|-us-gov)?:sns:[a-z]{2}(?:-[a-z]+)+-\d{1,2}:\d{12}:[A-Za-z0-9_-]{1,256}\z/';

    /** The fields each type signs, in the order SNS signs them; `Subject` only where the message has one. */
    private const array SIGNED = [
        'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
        'SubscriptionConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
        'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
    ];

    private SnsCertificates $certificates;

    /**
     * @param list<string> $topics the SNS topic ARNs the application's SES events are published to
     * @param bool $confirm whether a subscription to one of them is confirmed on its first message
     * @param (\Closure(Request): string)|null $clientAddress the address a request came from, as the throttles read it through the trusted proxies; the peer's when null
     */
    public function __construct(
        private array $topics,
        private OutboundHttp $http,
        private bool $confirm = true,
        private int $tolerance = self::TOLERANCE,
        ?SnsCertificates $certificates = null,
        private ?\Closure $clientAddress = null,
    ) {
        if ($topics === []) {
            throw new \InvalidArgumentException('An SES webhook names the SNS topics its events come from: SNS signs every account\'s topics alike.');
        }
        foreach ($topics as $topic) {
            if (preg_match(self::TOPIC_ARN, $topic) !== 1) {
                throw new \InvalidArgumentException("'{$topic}' is not an SNS topic ARN.");
            }
        }
        $this->certificates = $certificates ?? new SnsCertificates($http);
    }

    public function provesContent(): bool
    {
        return true;
    }

    public function namesReader(): bool
    {
        return true;
    }

    public function name(): string
    {
        return SesTransport::NAME;
    }

    public function receive(Request $request, string $rawBody, int $now): MailWebhookDelivery
    {
        $message = Payload::json($rawBody);
        $type = $message['Type'] ?? null;
        $fields = \is_string($type) ? (self::SIGNED[$type] ?? null) : null;
        if ($fields === null) {
            throw new HttpException(400, 'The body is not an SNS message.', 'bad_request');
        }
        $announced = $request->header('x-amz-sns-message-type');
        if ($announced !== null && $announced !== $type) {
            throw new HttpException(400, 'The SNS message is not the type its header says.', 'bad_request');
        }
        foreach (['MessageId', 'TopicArn', 'Timestamp', 'Signature', 'SignatureVersion', 'SigningCertURL'] as $required) {
            if (!\is_string($message[$required] ?? null) || $message[$required] === '') {
                throw Payload::unverified("The SNS message has no {$required}.");
            }
        }
        if (!\in_array($message['TopicArn'], $this->topics, true)) {
            throw Payload::unverified('The SNS message is from a topic this application does not take events from.');
        }
        if (preg_match('/^[A-Za-z0-9-]{1,128}\z/', (string) $message['MessageId']) !== 1) {
            throw Payload::unverified('The SNS message\'s id is not one.');
        }

        $this->verify($message, $fields, $now, $this->clientAddress === null ? ($request->remoteAddress ?? '') : ($this->clientAddress)($request));
        $timestamp = strtotime((string) $message['Timestamp']);
        if ($timestamp === false) {
            throw Payload::unverified('The SNS message\'s timestamp cannot be read.');
        }
        Payload::assertFresh($timestamp, $now, $this->tolerance);

        $expiresAt = $timestamp + $this->tolerance;
        $events = match ($type) {
            'SubscriptionConfirmation' => $this->confirmSubscription($message),
            'Notification' => $this->events(Payload::json(\is_string($message['Message'] ?? null) ? $message['Message'] : ''), $now),
            default => [],
        };

        return new MailWebhookDelivery((string) $message['MessageId'], $expiresAt, $events);
    }

    /**
     * The string SNS signs: each field's name and value, a newline after
     * each, in its type's order.
     *
     * @param array<array-key, mixed> $message
     * @param list<string> $fields
     */
    public static function stringToSign(array $message, array $fields): string
    {
        $signed = '';
        foreach ($fields as $field) {
            if ($field === 'Subject' && !\is_string($message['Subject'] ?? null)) {
                continue;
            }
            $value = $message[$field] ?? null;
            if (!\is_string($value)) {
                throw Payload::unverified("The SNS message has no {$field}.");
            }
            $signed .= "{$field}\n{$value}\n";
        }

        return $signed;
    }

    /**
     * @param array<array-key, mixed> $message
     * @param list<string> $fields
     */
    private function verify(array $message, array $fields, int $now, string $client): void
    {
        $algorithm = match ($message['SignatureVersion']) {
            '1' => OPENSSL_ALGO_SHA1,
            '2' => OPENSSL_ALGO_SHA256,
            default => throw Payload::unverified('The SNS message\'s SignatureVersion is not 1 or 2.'),
        };
        $signature = base64_decode((string) $message['Signature'], true);
        if ($signature === false || $signature === '' || \strlen($signature) > 1024) {
            throw Payload::unverified('The SNS message\'s signature cannot be read.');
        }

        // Its topic, already one of the application's, says which SNS host
        // may have signed it: a certificate URL of any other is refused
        // before anything is fetched.
        $key = $this->certificates->key((string) $message['SigningCertURL'], $now, SnsCertificates::hostFor((string) $message['TopicArn']) ?? '', $client);
        if (openssl_verify(self::stringToSign($message, $fields), $signature, $key, $algorithm) !== 1) {
            throw Payload::unverified('The SNS message\'s signature does not verify.');
        }
        $this->certificates->verified((string) $message['SigningCertURL']);
    }

    /**
     * Confirms a verified subscription to one of the topics, by fetching its
     * SubscribeURL from SNS; nothing to report.
     *
     * @param array<array-key, mixed> $message
     * @return list<MailEvent>
     */
    private function confirmSubscription(array $message): array
    {
        if (!$this->confirm) {
            return [];
        }
        $url = $message['SubscribeURL'] ?? null;
        if (!\is_string($url) || !SnsCertificates::isSnsUrl($url, null)) {
            throw Payload::unverified('The subscription\'s SubscribeURL is not an SNS host\'s.');
        }

        try {
            $response = $this->http->send(new OutboundRequest(
                'GET',
                $url,
                timeout: 5.0,
                maxResponseBytes: 16_384,
                maxRedirects: 0,
                destinations: Destinations::hosts([(string) parse_url($url, PHP_URL_HOST)]),
            ));
        } catch (OutboundRefused|OutboundFailed) {
            throw new HttpException(502, 'SNS could not be reached to confirm the subscription.', 'bad_gateway');
        }
        if (!$response->successful()) {
            throw new HttpException(502, 'SNS did not confirm the subscription.', 'bad_gateway');
        }

        return [];
    }

    /**
     * @param array<array-key, mixed> $event
     * @return list<MailEvent>
     */
    private function events(array $event, int $now): array
    {
        $kind = $event['notificationType'] ?? $event['eventType'] ?? null;
        $mail = Payload::map($event['mail'] ?? null);
        $messageId = Payload::text($mail['messageId'] ?? null, 128);
        $events = [];

        if ($kind === 'Bounce') {
            $bounce = Payload::map($event['bounce'] ?? null);
            $permanent = ($bounce['bounceType'] ?? null) === 'Permanent';
            $at = Payload::time($bounce['timestamp'] ?? null, $now);
            foreach (Payload::map($bounce['bouncedRecipients'] ?? null) as $recipient) {
                $recipient = Payload::map($recipient);
                $email = Payload::email($recipient['emailAddress'] ?? null);
                if ($email !== null) {
                    $reason = Payload::text($recipient['diagnosticCode'] ?? null) ?? Payload::text($bounce['bounceSubType'] ?? null);
                    $events[] = new MailBounced(SesTransport::NAME, $email, $messageId, $at, $permanent, $reason);
                }
            }
        } elseif ($kind === 'Complaint') {
            $complaint = Payload::map($event['complaint'] ?? null);
            $at = Payload::time($complaint['timestamp'] ?? null, $now);
            foreach (Payload::map($complaint['complainedRecipients'] ?? null) as $recipient) {
                $email = Payload::email(Payload::map($recipient)['emailAddress'] ?? null);
                if ($email !== null) {
                    $events[] = new MailComplained(SesTransport::NAME, $email, $messageId, $at, Payload::text($complaint['complaintFeedbackType'] ?? null, 64));
                }
            }
        } elseif ($kind === 'Delivery') {
            $delivery = Payload::map($event['delivery'] ?? null);
            $at = Payload::time($delivery['timestamp'] ?? null, $now);
            foreach (Payload::map($delivery['recipients'] ?? null) as $recipient) {
                $email = Payload::email($recipient);
                if ($email !== null) {
                    $events[] = new MailDelivered(SesTransport::NAME, $email, $messageId, $at);
                }
            }
        }

        return $events;
    }
}
