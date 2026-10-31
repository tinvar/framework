<?php

declare(strict_types=1);

/**
 * The providers' webhooks, each against deliveries signed as the provider
 * signs them -- with keys made here where the scheme is a public key's --
 * and refused for every way a forged, altered, stale or replayed delivery
 * can differ; then the endpoint that mounts them, writing hard bounces and
 * complaints to the suppression list and dispatching each event once.
 */

namespace Tinvar\Tests\Mail\MailWebhooks;

use Tinvar\Http\ArrayNonceStore;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Mail\ArraySuppressions;
use Tinvar\Mail\MailBounced;
use Tinvar\Mail\MailComplained;
use Tinvar\Mail\MailDelivered;
use Tinvar\Mail\MailEvent;
use Tinvar\Mail\MailEventsEndpoint;
use Tinvar\Mail\MailWebhook;
use Tinvar\Mail\MailWebhookDelivery;
use Tinvar\Mail\Providers\MailgunWebhook;
use Tinvar\Mail\Providers\PostmarkWebhook;
use Tinvar\Mail\Providers\ResendWebhook;
use Tinvar\Mail\Providers\SendGridWebhook;
use Tinvar\Mail\Providers\SesWebhook;
use Tinvar\Mail\Providers\SvixSignature;
use Tinvar\Mail\SuppressionReason;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;

const NOW = 1_800_000_000;
const TOPIC = 'arn:aws:sns:eu-west-1:123456789012:ses-events';
const CERT_URL = 'https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-9c6465fa7f48f5cacd23014631ec1136.pem';

/** @param array<string, string> $headers */
function post(string $provider, string $body, array $headers = []): Request
{
    return new Request('POST', "/_mail/events/{$provider}", array_change_key_case($headers, CASE_LOWER));
}

function refused(MailWebhook $webhook, Request $request, string $body, int $now = NOW): int
{
    try {
        $webhook->receive($request, $body, $now);
    } catch (HttpException $e) {
        return $e->status;
    }

    return 0;
}

describe('Resend (Svix)', function () {
    $secret = 'whsec_' . base64_encode('resend-webhook-secret-32-bytes!!');
    $key = 'resend-webhook-secret-32-bytes!!';
    $body = json_encode([
        'type' => 'email.bounced',
        'created_at' => '2027-01-15T08:00:00.000Z',
        'data' => [
            'email_id' => '49a3999c-0ce1-4ea6-ab68-afcd6dc2e794',
            'from' => 'Pellwick <orders@pellwick.example>',
            'to' => ['Ada@Example.test'],
            'subject' => 'Your order',
            'bounce' => ['message' => 'The recipient\'s mailbox does not exist.', 'subType' => 'General', 'type' => 'Permanent'],
        ],
    ], JSON_THROW_ON_ERROR);
    $signed = static fn (string $body, int $at = NOW, string $id = 'msg_2LgvL5BtK6aAvvGYmYCuzCvShVZ'): Request => post('resend', $body, [
        'svix-id' => $id,
        'svix-timestamp' => (string) $at,
        'svix-signature' => 'v1,bm90LXRoaXMtb25l v1,' . SvixSignature::sign($key, $id, $at, $body),
    ]);

    it('reads a delivery whose signature verifies, any v1 in the header matching', function () use ($secret, $body, $signed) {
        $delivery = new ResendWebhook($secret)->receive($signed($body), $body, NOW + 30);

        expect($delivery->nonce)->toBe('msg_2LgvL5BtK6aAvvGYmYCuzCvShVZ')
            ->and($delivery->expiresAt)->toBe(NOW + 300)
            ->and($delivery->events)->toEqual([
                new MailBounced('resend', 'ada@example.test', '49a3999c-0ce1-4ea6-ab68-afcd6dc2e794', 1_800_000_000, true, 'The recipient\'s mailbox does not exist.'),
            ]);
    });

    it('refuses a body altered, an id or time changed, a signature missing, or a delivery five minutes old', function () use ($secret, $body, $signed) {
        $webhook = new ResendWebhook($secret);
        $good = $signed($body);
        $headers = $good->headers();

        expect(refused($webhook, $good, str_replace('Permanent', 'Transient', $body)))->toBe(401)
            ->and(refused($webhook, new Request('POST', '/_mail/events/resend', [...$headers, 'svix-id' => 'msg_other']), $body))->toBe(401)
            ->and(refused($webhook, new Request('POST', '/_mail/events/resend', [...$headers, 'svix-timestamp' => (string) (NOW + 1)]), $body))->toBe(401)
            ->and(refused($webhook, new Request('POST', '/_mail/events/resend', ['svix-id' => 'msg_1', 'svix-timestamp' => (string) NOW]), $body))->toBe(401)
            ->and(refused($webhook, $signed($body, NOW - 301), $body))->toBe(401)
            ->and(refused($webhook, $signed($body, NOW + 301), $body))->toBe(401)
            ->and(refused(new ResendWebhook('whsec_' . base64_encode('another-secret-of-thirty-two-by!')), $good, $body))->toBe(401);
    });

    it('reads complaints, deliveries and transient bounces, and takes the rest as nothing', function () use ($secret, $signed) {
        $webhook = new ResendWebhook($secret);
        $events = static function (string $type, array $data = []) use ($webhook, $signed): array {
            $body = json_encode(['type' => $type, 'created_at' => '2027-01-15T08:00:00Z', 'data' => ['email_id' => 'e1', 'to' => ['ada@example.test'], ...$data]], JSON_THROW_ON_ERROR);

            return $webhook->receive($signed($body), $body, NOW)->events;
        };

        expect($events('email.complained')[0])->toBeInstanceOf(MailComplained::class)
            ->and($events('email.delivered')[0])->toBeInstanceOf(MailDelivered::class)
            ->and($events('email.bounced', ['bounce' => ['type' => 'Transient']])[0]->permanent)->toBeFalse()
            ->and($events('email.opened'))->toBe([]);
    });

    it('refuses a secret that is not one', function () {
        expect(fn () => new ResendWebhook('not base64 at all!'))->toThrow(\InvalidArgumentException::class);
    });
});

describe('Postmark (basic auth)', function () {
    $password = 'p0stm4rk-webhook-password-0123';
    $auth = static fn (string $user, string $pass): array => ['Authorization' => 'Basic ' . base64_encode("{$user}:{$pass}")];
    $bounce = json_encode([
        'RecordType' => 'Bounce', 'ID' => 4323372036854775807, 'Type' => 'HardBounce', 'TypeCode' => 1, 'Name' => 'Hard bounce',
        'MessageID' => '883953f4-6105-42a2-a16a-77a8eac79483', 'MessageStream' => 'outbound', 'Description' => 'The server was unable to deliver your message.',
        'Details' => 'smtp;550 5.1.1 The email account that you tried to reach does not exist.', 'Email' => 'grace@example.test',
        'BouncedAt' => '2027-01-15T08:00:00Z', 'Inactive' => true,
    ], JSON_THROW_ON_ERROR);

    it('reads a delivery carrying the webhook\'s credentials, its nonce a digest of the body kept a week', function () use ($password, $auth, $bounce) {
        $delivery = new PostmarkWebhook('postmark', $password)->receive(post('postmark', $bounce, $auth('postmark', $password)), $bounce, NOW);

        expect($delivery->nonce)->toBe(hash('sha256', $bounce))
            ->and($delivery->expiresAt)->toBe(NOW + 604_800)
            ->and($delivery->events)->toEqual([
                new MailBounced('postmark', 'grace@example.test', '883953f4-6105-42a2-a16a-77a8eac79483', 1_800_000_000, true, 'smtp;550 5.1.1 The email account that you tried to reach does not exist.'),
            ]);
    });

    it('refuses wrong, partial or missing credentials before reading the body', function () use ($password, $auth) {
        $webhook = new PostmarkWebhook('postmark', $password);

        expect(refused($webhook, post('postmark', '{', $auth('postmark', 'wrong-password-wrong-password')), '{'))->toBe(401)
            ->and(refused($webhook, post('postmark', '{', $auth('other', $password)), '{'))->toBe(401)
            ->and(refused($webhook, post('postmark', '{', $auth('postmark', substr($password, 0, -1))), '{'))->toBe(401)
            ->and(refused($webhook, post('postmark', '{', ['Authorization' => 'Bearer ' . $password]), '{'))->toBe(401)
            ->and(refused($webhook, post('postmark', '{'), '{'))->toBe(401)
            ->and(refused($webhook, post('postmark', '{', $auth('postmark', $password)), '{'))->toBe(400);
    });

    it('reads soft bounces, spam complaints and deliveries', function () use ($password, $auth) {
        $webhook = new PostmarkWebhook('postmark', $password);
        $events = static function (array $payload) use ($webhook, $auth, $password): array {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);

            return $webhook->receive(post('postmark', $body, $auth('postmark', $password)), $body, NOW)->events;
        };

        expect($events(['RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'Email' => 'a@example.test', 'MessageID' => 'm', 'Inactive' => false])[0]->permanent)->toBeFalse()
            ->and($events(['RecordType' => 'SpamComplaint', 'Type' => 'SpamComplaint', 'Email' => 'a@example.test', 'MessageID' => 'm'])[0])->toBeInstanceOf(MailComplained::class)
            ->and($events(['RecordType' => 'Delivery', 'Recipient' => 'a@example.test', 'MessageID' => 'm', 'DeliveredAt' => '2027-01-15T08:00:00Z'])[0])->toEqual(new MailDelivered('postmark', 'a@example.test', 'm', 1_800_000_000))
            ->and($events(['RecordType' => 'Open', 'Recipient' => 'a@example.test']))->toBe([]);
    });

    it('takes a password of 24 characters or more', function () {
        expect(fn () => new PostmarkWebhook('postmark', 'short'))->toThrow(\InvalidArgumentException::class);
    });
});

describe('Amazon SES (SNS)', function () {
    /**
     * A key and a self-signed certificate standing for SNS's, valid from
     * $from to $to.
     *
     * @return array{\OpenSSLAsymmetricKey, string}
     */
    function snsCertificate(int $days = 365): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        \assert($key !== false);
        $csr = openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $key, ['digest_alg' => 'sha256']);
        \assert($csr !== false && $csr !== true);
        $certificate = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256']);
        \assert($certificate !== false);
        openssl_x509_export($certificate, $pem);

        return [$key, $pem];
    }

    /**
     * An SNS message of $type, signed with $key as SNS signs it.
     *
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    function snsMessage(\OpenSSLAsymmetricKey $key, string $type, array $fields, string $version = '1'): array
    {
        $message = [
            'Type' => $type,
            'MessageId' => '22b80b92-fdea-4c2c-8f9d-bdfb0c7bf324',
            'TopicArn' => TOPIC,
            'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', NOW),
            'SignatureVersion' => $version,
            'SigningCertURL' => CERT_URL,
            ...$fields,
        ];
        $order = $type === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $signed = '';
        foreach ($order as $field) {
            if (isset($message[$field])) {
                $signed .= "{$field}\n{$message[$field]}\n";
            }
        }
        openssl_sign($signed, $signature, $key, $version === '1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256);
        $message['Signature'] = base64_encode($signature);

        return $message;
    }

    function sesBounce(): string
    {
        return json_encode([
            'notificationType' => 'Bounce',
            'bounce' => [
                'bounceType' => 'Permanent', 'bounceSubType' => 'General', 'timestamp' => '2027-01-15T08:00:00.000Z', 'feedbackId' => 'f-1',
                'bouncedRecipients' => [['emailAddress' => 'Grace@Example.test', 'action' => 'failed', 'status' => '5.1.1', 'diagnosticCode' => 'smtp; 550 5.1.1 user unknown']],
            ],
            'mail' => ['messageId' => '0102018f-ses-message-id', 'source' => 'orders@pellwick.example', 'destination' => ['grace@example.test']],
        ], JSON_THROW_ON_ERROR);
    }

    /** An OutboundHttp serving $pem as SNS's certificate, and answering a SubscribeURL. */
    function sns(string $pem): FakeOutboundHttp
    {
        return new FakeOutboundHttp(static fn (OutboundRequest $r): OutboundResponse => $r->url === CERT_URL
            ? new OutboundResponse(200, [], $pem)
            : new OutboundResponse(200, [], '<ConfirmSubscriptionResponse/>'));
    }

    it('verifies a notification with the certificate fetched from SNS alone, by SHA1 and by SHA256, and reads SES\'s bounce', function (string $version) {
        [$key, $pem] = snsCertificate();
        $http = sns($pem);
        $body = json_encode(snsMessage($key, 'Notification', ['Message' => sesBounce()], $version), JSON_THROW_ON_ERROR);
        $webhook = new SesWebhook([TOPIC], $http);
        $delivery = $webhook->receive(post('ses', $body, ['x-amz-sns-message-type' => 'Notification']), $body, NOW + 60);

        expect($delivery->nonce)->toBe('22b80b92-fdea-4c2c-8f9d-bdfb0c7bf324')
            ->and($delivery->events)->toEqual([new MailBounced('ses', 'grace@example.test', '0102018f-ses-message-id', 1_800_000_000, true, 'smtp; 550 5.1.1 user unknown')])
            ->and($http->sent)->toHaveCount(1)
            ->and($http->sent[0]->destinations?->hosts)->toBe(['sns.eu-west-1.amazonaws.com'])
            ->and($http->sent[0]->maxRedirects)->toBe(0);

        // Kept for the worker: a second notification fetches nothing.
        $webhook->receive(post('ses', $body), $body, NOW + 61);
        expect($http->sent)->toHaveCount(1);
    })->with(['1', '2']);

    it('refuses a topic not the application\'s before fetching anything: SNS signs every account\'s alike', function () {
        [$key, $pem] = snsCertificate();
        $http = sns($pem);
        $body = json_encode(snsMessage($key, 'Notification', ['Message' => sesBounce(), 'TopicArn' => 'arn:aws:sns:eu-west-1:999999999999:someone-elses']), JSON_THROW_ON_ERROR);

        expect(refused(new SesWebhook([TOPIC], $http), post('ses', $body), $body))->toBe(401)->and($http->sent)->toBe([]);
    });

    it('fetches a certificate from nowhere but SNS\'s own hosts and paths', function (string $url) {
        [$key, $pem] = snsCertificate();
        $http = sns($pem);
        $body = json_encode(snsMessage($key, 'Notification', ['Message' => sesBounce(), 'SigningCertURL' => $url]), JSON_THROW_ON_ERROR);

        expect(refused(new SesWebhook([TOPIC], $http), post('ses', $body), $body))->toBe(401)->and($http->sent)->toBe([]);
    })->with([
        'plain http' => 'http://sns.eu-west-1.amazonaws.com/SimpleNotificationService-abc.pem',
        'another host' => 'https://evil.example/SimpleNotificationService-abc.pem',
        'a lookalike suffix' => 'https://sns.eu-west-1.amazonaws.com.evil.example/SimpleNotificationService-abc.pem',
        'another amazonaws host' => 'https://s3.amazonaws.com/SimpleNotificationService-abc.pem',
        'credentials' => 'https://user@sns.eu-west-1.amazonaws.com/SimpleNotificationService-abc.pem',
        'a port' => 'https://sns.eu-west-1.amazonaws.com:8443/SimpleNotificationService-abc.pem',
        'another path' => 'https://sns.eu-west-1.amazonaws.com/attacker/key.pem',
        'a query' => 'https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-abc.pem?x=1',
        'a traversal' => 'https://sns.eu-west-1.amazonaws.com/../SimpleNotificationService-abc.pem',
        'internal metadata' => 'https://169.254.169.254/SimpleNotificationService-abc.pem',
    ]);

    it('refuses a message altered, signed by another key, stale, or under an expired certificate', function () {
        [$key, $pem] = snsCertificate();
        [$other] = snsCertificate();
        $webhook = new SesWebhook([TOPIC], sns($pem));
        $good = snsMessage($key, 'Notification', ['Message' => sesBounce()]);
        $tampered = json_encode([...$good, 'Message' => str_replace('Permanent', 'Transient', sesBounce())], JSON_THROW_ON_ERROR);
        $forged = json_encode(snsMessage($other, 'Notification', ['Message' => sesBounce()]), JSON_THROW_ON_ERROR);
        $body = json_encode($good, JSON_THROW_ON_ERROR);

        expect(refused($webhook, post('ses', $tampered), $tampered))->toBe(401)
            ->and(refused($webhook, post('ses', $forged), $forged))->toBe(401)
            ->and(refused($webhook, post('ses', $body), $body, NOW + 3601))->toBe(401)
            ->and(refused($webhook, post('ses', $body, ['x-amz-sns-message-type' => 'SubscriptionConfirmation']), $body))->toBe(400)
            ->and(refused(new SesWebhook([TOPIC], sns($pem)), post('ses', $body), $body, NOW + 400 * 86_400))->toBe(401);
    });

    it('confirms a verified subscription to one of its topics by fetching its SubscribeURL from SNS', function () {
        [$key, $pem] = snsCertificate();
        $http = sns($pem);
        $subscribe = 'https://sns.eu-west-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=' . rawurlencode(TOPIC) . '&Token=2336412f37';
        $body = json_encode(snsMessage($key, 'SubscriptionConfirmation', [
            'Message' => 'You have chosen to subscribe to the topic.', 'SubscribeURL' => $subscribe, 'Token' => '2336412f37',
        ]), JSON_THROW_ON_ERROR);

        $delivery = new SesWebhook([TOPIC], $http)->receive(post('ses', $body), $body, NOW);
        expect($delivery->events)->toBe([])
            ->and($http->sent[1]->url)->toBe($subscribe)
            ->and($http->sent[1]->destinations?->hosts)->toBe(['sns.eu-west-1.amazonaws.com']);

        $elsewhere = json_encode(snsMessage($key, 'SubscriptionConfirmation', [
            'Message' => 'x', 'SubscribeURL' => 'https://evil.example/confirm', 'Token' => 't',
        ]), JSON_THROW_ON_ERROR);
        $quiet = sns($pem);
        expect(refused(new SesWebhook([TOPIC], $quiet), post('ses', $elsewhere), $elsewhere))->toBe(401)
            ->and(array_map(static fn (OutboundRequest $r): string => $r->url, $quiet->sent))->toBe([CERT_URL]);
    });

    it('reads complaints and deliveries, from an identity\'s notifications or a configuration set\'s events', function () {
        [$key, $pem] = snsCertificate();
        $webhook = new SesWebhook([TOPIC], sns($pem));
        $events = static function (array $event) use ($webhook, $key): array {
            $body = json_encode(snsMessage($key, 'Notification', ['Message' => json_encode($event, JSON_THROW_ON_ERROR)]), JSON_THROW_ON_ERROR);

            return $webhook->receive(post('ses', $body), $body, NOW)->events;
        };

        $complaint = $events(['eventType' => 'Complaint', 'complaint' => ['complainedRecipients' => [['emailAddress' => 'a@example.test']], 'complaintFeedbackType' => 'abuse', 'timestamp' => '2027-01-15T08:00:00Z'], 'mail' => ['messageId' => 'm-1']]);
        $delivery = $events(['notificationType' => 'Delivery', 'delivery' => ['recipients' => ['a@example.test', 'b@example.test'], 'timestamp' => '2027-01-15T08:00:00Z'], 'mail' => ['messageId' => 'm-2']]);
        $soft = $events(['notificationType' => 'Bounce', 'bounce' => ['bounceType' => 'Transient', 'bouncedRecipients' => [['emailAddress' => 'a@example.test']]], 'mail' => ['messageId' => 'm-3']]);

        expect($complaint)->toEqual([new MailComplained('ses', 'a@example.test', 'm-1', 1_800_000_000, 'abuse')])
            ->and($delivery)->toHaveCount(2)
            ->and($soft[0]->permanent)->toBeFalse();
    });

    it('names its topics, or takes nothing', function () {
        expect(fn () => new SesWebhook([], new FakeOutboundHttp()))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new SesWebhook(['not-an-arn'], new FakeOutboundHttp()))->toThrow(\InvalidArgumentException::class);
    });
});

describe('Mailgun (HMAC)', function () {
    $key = 'mailgun-http-webhook-signing-key-0123456789';
    $signed = static function (array $eventData, int $at = NOW, string $token = 'a8ce0edb2dd8301dee6c2405235584e45aa91d1e9f979f3de0') use ($key): string {
        return json_encode([
            'signature' => ['timestamp' => (string) $at, 'token' => $token, 'signature' => MailgunWebhook::sign($key, (string) $at, $token)],
            'event-data' => $eventData,
        ], JSON_THROW_ON_ERROR);
    };
    $failed = [
        'event' => 'failed', 'severity' => 'permanent', 'reason' => 'bounce', 'recipient' => 'grace@example.test', 'timestamp' => 1_800_000_000.123,
        'id' => 'G9Bn5sl1TC6nu79C8C0bwg',
        'message' => ['headers' => ['message-id' => '<20261031100000.1.ABCDEF@mg.pellwick.example>']],
        'delivery-status' => ['code' => 550, 'description' => '', 'message' => '5.1.1 The email account does not exist'],
    ];

    it('reads an event whose HMAC verifies, its token the nonce', function () use ($key, $signed, $failed) {
        $body = $signed($failed);
        $delivery = new MailgunWebhook($key)->receive(post('mailgun', $body), $body, NOW + 10);

        expect($delivery->nonce)->toBe('a8ce0edb2dd8301dee6c2405235584e45aa91d1e9f979f3de0')
            ->and($delivery->events)->toEqual([new MailBounced('mailgun', 'grace@example.test', '20261031100000.1.ABCDEF@mg.pellwick.example', 1_800_000_000, true, '5.1.1 The email account does not exist')]);
    });

    it('refuses an HMAC under another key, a token or time swapped, an event altered, or one fifteen minutes old', function () use ($key, $signed, $failed) {
        $webhook = new MailgunWebhook($key);
        $body = $signed($failed);
        $swapped = json_decode($body, true);
        $swapped['signature']['token'] = 'b8ce0edb2dd8301dee6c2405235584e45aa91d1e9f979f3de0';
        $swapped = json_encode($swapped, JSON_THROW_ON_ERROR);

        expect(refused(new MailgunWebhook('another-key-entirely-0123456789abcdef'), post('mailgun', $body), $body))->toBe(401)
            ->and(refused($webhook, post('mailgun', $swapped), $swapped))->toBe(401)
            ->and(refused($webhook, post('mailgun', $signed($failed, NOW - 901)), $signed($failed, NOW - 901)))->toBe(401)
            ->and(refused($webhook, post('mailgun', '{"event-data":{}}'), '{"event-data":{}}'))->toBe(401);

        // The event-data is not what the HMAC covers -- Mailgun signs the
        // timestamp and token alone -- so the token is what keeps a captured
        // delivery from being taken twice, at the endpoint.
        expect(new MailgunWebhook($key)->receive(post('mailgun', $body), $body, NOW)->nonce)->toBe('a8ce0edb2dd8301dee6c2405235584e45aa91d1e9f979f3de0');
    });

    it('reads temporary failures, complaints and deliveries', function () use ($key, $signed) {
        $webhook = new MailgunWebhook($key);
        $events = static fn (array $data): array => $webhook->receive(post('mailgun', $signed($data)), $signed($data), NOW)->events;

        expect($events(['event' => 'failed', 'severity' => 'temporary', 'recipient' => 'a@example.test'])[0]->permanent)->toBeFalse()
            ->and($events(['event' => 'complained', 'recipient' => 'a@example.test'])[0])->toBeInstanceOf(MailComplained::class)
            ->and($events(['event' => 'delivered', 'recipient' => 'a@example.test'])[0])->toBeInstanceOf(MailDelivered::class)
            ->and($events(['event' => 'opened', 'recipient' => 'a@example.test']))->toBe([]);
    });
});

describe('SendGrid (ECDSA)', function () {
    /** @return array{\OpenSSLAsymmetricKey, string} a P-256 key, and its public half as SendGrid shows it */
    function sendGridKey(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        \assert($key !== false);
        $pem = (string) openssl_pkey_get_details($key)['key'];
        $base64 = str_replace(["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\n"], '', $pem);

        return [$key, $base64];
    }

    function sendGridSigned(\OpenSSLAsymmetricKey $key, string $body, int $at = NOW): Request
    {
        openssl_sign((string) $at . $body, $signature, $key, OPENSSL_ALGO_SHA256);

        return post('sendgrid', $body, [
            'X-Twilio-Email-Event-Webhook-Signature' => base64_encode($signature),
            'X-Twilio-Email-Event-Webhook-Timestamp' => (string) $at,
        ]);
    }

    $batch = json_encode([
        ['email' => 'grace@example.test', 'timestamp' => 1_800_000_000, 'event' => 'bounce', 'type' => 'bounce', 'status' => '5.1.1', 'reason' => '550 5.1.1 user unknown', 'sg_event_id' => 'ZHJvcC0x', 'sg_message_id' => 'W8x8sBGqR4mHxnfwDs1Wmw.filterdrecv-1.2.3'],
        ['email' => 'carol@example.test', 'timestamp' => 1_800_000_001, 'event' => 'bounce', 'type' => 'blocked', 'reason' => '421 try later', 'sg_message_id' => 'W8x8sBGqR4mHxnfwDs1Wmw.filterdrecv-1.2.4'],
        ['email' => 'ada@example.test', 'timestamp' => 1_800_000_002, 'event' => 'spamreport', 'sg_message_id' => 'W8x8sBGqR4mHxnfwDs1Wmw.x'],
        ['email' => 'ada@example.test', 'timestamp' => 1_800_000_003, 'event' => 'delivered', 'sg_message_id' => 'W8x8sBGqR4mHxnfwDs1Wmw.y'],
        ['email' => 'ada@example.test', 'timestamp' => 1_800_000_004, 'event' => 'open'],
    ], JSON_THROW_ON_ERROR);

    it('verifies a batch with the public key, and reads each event in it', function () use ($batch) {
        [$key, $public] = sendGridKey();
        $delivery = new SendGridWebhook($public)->receive(sendGridSigned($key, $batch), $batch, NOW + 5);

        expect($delivery->nonce)->toBe(hash('sha256', NOW . "\n" . $batch))
            ->and($delivery->events)->toEqual([
                new MailBounced('sendgrid', 'grace@example.test', 'W8x8sBGqR4mHxnfwDs1Wmw', 1_800_000_000, true, '550 5.1.1 user unknown'),
                new MailBounced('sendgrid', 'carol@example.test', 'W8x8sBGqR4mHxnfwDs1Wmw', 1_800_000_001, false, '421 try later'),
                new MailComplained('sendgrid', 'ada@example.test', 'W8x8sBGqR4mHxnfwDs1Wmw', 1_800_000_002),
                new MailDelivered('sendgrid', 'ada@example.test', 'W8x8sBGqR4mHxnfwDs1Wmw', 1_800_000_003),
            ]);
    });

    it('refuses a batch altered, a timestamp swapped, another key\'s signature, or one ten minutes old', function () use ($batch) {
        [$key, $public] = sendGridKey();
        [$other] = sendGridKey();
        $webhook = new SendGridWebhook($public);
        $good = sendGridSigned($key, $batch);

        expect(refused($webhook, $good, str_replace('blocked', 'bounce', $batch)))->toBe(401)
            ->and(refused($webhook, new Request('POST', '/_mail/events/sendgrid', [...$good->headers(), 'x-twilio-email-event-webhook-timestamp' => (string) (NOW + 1)]), $batch))->toBe(401)
            ->and(refused($webhook, sendGridSigned($other, $batch), $batch))->toBe(401)
            ->and(refused($webhook, sendGridSigned($key, $batch, NOW - 601), $batch))->toBe(401)
            ->and(refused($webhook, post('sendgrid', $batch), $batch))->toBe(401);
    });

    it('takes a P-256 public key alone', function () {
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        \assert($rsa !== false);
        $rsaPublic = (string) openssl_pkey_get_details($rsa)['key'];

        expect(SendGridWebhook::publicKey('not a key'))->toBeNull()
            ->and(SendGridWebhook::publicKey($rsaPublic))->toBeNull()
            ->and(fn () => new SendGridWebhook('bm90IGEga2V5'))->toThrow(\InvalidArgumentException::class);
    });
});

describe('the endpoint', function () {
    /** A webhook that reports what it is given, for the endpoint's own rules. */
    final class Scripted implements MailWebhook
    {
        /** @param list<MailEvent> $events */
        public function __construct(public array $events, public string $nonce = 'nonce-1') {}

        public function name(): string
        {
            return 'scripted';
        }

        public function receive(Request $request, string $rawBody, int $now): MailWebhookDelivery
        {
            return new MailWebhookDelivery($this->nonce, $now + 300, $this->events);
        }
    }

    $events = [
        new MailBounced('ses', 'hard@example.test', 'm', NOW, true, '5.1.1'),
        new MailBounced('ses', 'soft@example.test', 'm', NOW, false),
        new MailComplained('ses', 'complained@example.test', 'm', NOW, 'abuse'),
        new MailDelivered('ses', 'ada@example.test', 'm', NOW),
    ];

    it('suppresses hard bounces and complaints, dispatches every event, and takes a replay once', function () use ($events) {
        $suppressions = new ArraySuppressions();
        $dispatched = [];
        $endpoint = new MailEventsEndpoint([new Scripted($events)], new ArrayNonceStore(), $suppressions, static function (MailEvent $e) use (&$dispatched): void {
            $dispatched[] = $e;
        }, static fn (): int => NOW);

        $first = $endpoint->handle(post('scripted', '{}'), '{}');
        expect($first->status)->toBe(200)
            ->and($dispatched)->toBe($events)
            ->and(array_map(static fn ($s): string => $s->reason->value, $suppressions->held))->toBe([
                'hard@example.test' => SuppressionReason::Bounce->value,
                'complained@example.test' => SuppressionReason::Complaint->value,
            ])
            ->and($suppressions->held['hard@example.test']->source)->toBe('ses')
            ->and($suppressions->held['hard@example.test']->detail)->toBe('5.1.1');

        $again = $endpoint->handle(post('scripted', '{}'), '{}');
        expect($again->status)->toBe(200)->and($again->body())->toContain('Already received')->and($dispatched)->toHaveCount(4);
    });

    it('releases the nonce when a listener fails, so the provider\'s retry is taken', function () use ($events) {
        $nonces = new ArrayNonceStore();
        $fail = true;
        $endpoint = new MailEventsEndpoint([new Scripted($events)], $nonces, null, static function () use (&$fail): void {
            if ($fail) {
                throw new \RuntimeException('listener down');
            }
        }, static fn (): int => NOW);

        expect(fn () => $endpoint->handle(post('scripted', '{}'), '{}'))->toThrow(\RuntimeException::class);
        $fail = false;
        expect($endpoint->handle(post('scripted', '{}'), '{}')->body())->toContain('Received.');
    });

    it('answers its own paths alone: a provider it was given, POSTed, within the limit', function () {
        $endpoint = new MailEventsEndpoint([new Scripted([])], new ArrayNonceStore());
        $status = static function (Request $request, string $body = '{}') use ($endpoint): int {
            try {
                return $endpoint->handle($request, $body)->status;
            } catch (HttpException $e) {
                return $e->status;
            }
        };

        expect($endpoint->matches(post('scripted', '')))->toBeTrue()
            ->and($endpoint->matches(new Request('POST', '/_mail/eventsx')))->toBeFalse()
            ->and($status(post('resend', '{}')))->toBe(404)
            ->and($status(new Request('GET', '/_mail/events/scripted')))->toBe(405)
            ->and($status(post('scripted', ''), str_repeat('x', MailEventsEndpoint::MAX_BODY_BYTES + 1)))->toBe(413)
            ->and(fn () => new MailEventsEndpoint([new Scripted([]), new Scripted([])], new ArrayNonceStore()))->toThrow(\InvalidArgumentException::class);
    });

    it('runs a real provider\'s delivery through, signed', function () {
        $key = 'mailgun-http-webhook-signing-key-0123456789';
        $body = json_encode([
            'signature' => ['timestamp' => (string) NOW, 'token' => str_repeat('ab', 25), 'signature' => MailgunWebhook::sign($key, (string) NOW, str_repeat('ab', 25))],
            'event-data' => ['event' => 'complained', 'recipient' => 'ada@example.test'],
        ], JSON_THROW_ON_ERROR);
        $suppressions = new ArraySuppressions();
        $endpoint = new MailEventsEndpoint([new MailgunWebhook($key)], new ArrayNonceStore(), $suppressions, clock: static fn (): int => NOW);

        expect($endpoint->handle(post('mailgun', $body), $body)->status)->toBe(200)
            ->and($suppressions->held['ada@example.test']->reason)->toBe(SuppressionReason::Complaint)
            ->and($endpoint->handle(post('mailgun', $body), $body)->body())->toContain('Already received');
    });
});
