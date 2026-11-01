<?php

declare(strict_types=1);

/**
 * The mail providers' transports, against recorded responses through
 * Outbound's fake: each request held to its provider's origin, with no
 * redirect and a timeout; every field of a message mapped -- or the MIME
 * sent whole, where the provider takes it -- and the provider's id handed
 * back; its refusals told apart as retryable or the message's own, and its
 * key in no exception.
 */

namespace Tinvar\Tests\Mail\ProviderTransports;

use Tinvar\Aws\AwsCredentials;
use Tinvar\Aws\SigV4;
use Tinvar\Mail\Address;
use Tinvar\Mail\Attachment;
use Tinvar\Mail\Bounded;
use Tinvar\Mail\Delivers;
use Tinvar\Mail\DkimKey;
use Tinvar\Mail\DkimSigner;
use Tinvar\Mail\MailTransportException;
use Tinvar\Mail\Message;
use Tinvar\Mail\Providers\MailgunTransport;
use Tinvar\Mail\Providers\PostmarkTransport;
use Tinvar\Mail\Providers\ResendTransport;
use Tinvar\Mail\Providers\SendGridTransport;
use Tinvar\Mail\Providers\SesTransport;
use Tinvar\Mail\Unsubscribe;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundFailed;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;

const RESEND_KEY = 're_live_4f9c2a1e8b7d6c5a3f2e1d0c';
const POSTMARK_TOKEN = 'f00dbabe-1234-4abc-9def-0123456789ab';
const MAILGUN_KEY = 'key-3ax6xnjp29jd6fds4gc373sgvjxteol0';
const SENDGRID_KEY = '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';
const SES_SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
const NOW = 1_800_000_000;
const PNG = "\x89PNG\r\n\x1a\nnot really an image";

function full(): Message
{
    return new Message(
        new Address('orders@pellwick.example', 'Pellwick "Supply"'),
        [new Address('ada@example.test', 'Ada Lovelace'), new Address('grace@example.test')],
        'Your order 1042 is on its way',
        '<p>On its way. <img src="cid:logo"></p>',
        "On its way.\nhttps://pellwick.example/orders/1042",
        new Address('help@pellwick.example', 'Pellwick Help'),
        new Unsubscribe('https://pellwick.example/_mail/unsubscribe/v1.abc.def', new Address('unsubscribe@pellwick.example')),
        cc: [new Address('carol@example.test'), new Address('Ada@Example.test')],
        bcc: [new Address('audit@pellwick.example')],
        attachments: [
            new Attachment('invoice-1042.pdf', 'application/pdf', '%PDF-1.7 invoice'),
            Attachment::inline('logo', 'logo.png', 'image/png', PNG),
        ],
        tags: ['shipping', 'order'],
        metadata: ['order_id' => '1042'],
    );
}

/** @param \Closure(OutboundRequest): OutboundResponse $respond */
function fake(\Closure $respond): FakeOutboundHttp
{
    return new FakeOutboundHttp($respond);
}

/** @return array<array-key, mixed> */
function body(OutboundRequest $request): array
{
    return json_decode($request->body, true, 32, JSON_THROW_ON_ERROR);
}

function thrown(Delivers $transport): MailTransportException
{
    try {
        $transport->deliver(full());
    } catch (MailTransportException $e) {
        return $e;
    }

    throw new \LogicException('The send did not fail.');
}

/**
 * Each transport, made against $http, beside the secret it must never say.
 *
 * @return array<string, array{\Closure(FakeOutboundHttp): Delivers, string}>
 */
function transports(): array
{
    return [
        'resend' => [static fn (FakeOutboundHttp $http): Delivers => new ResendTransport(RESEND_KEY, $http), RESEND_KEY],
        'postmark' => [static fn (FakeOutboundHttp $http): Delivers => new PostmarkTransport(POSTMARK_TOKEN, $http), POSTMARK_TOKEN],
        'ses' => [static fn (FakeOutboundHttp $http): Delivers => new SesTransport(new AwsCredentials('AKIDEXAMPLE', SES_SECRET), 'eu-west-1', $http, clock: static fn (): int => NOW), SES_SECRET],
        'mailgun' => [static fn (FakeOutboundHttp $http): Delivers => new MailgunTransport(MAILGUN_KEY, 'mg.pellwick.example', $http), MAILGUN_KEY],
        'sendgrid' => [static fn (FakeOutboundHttp $http): Delivers => new SendGridTransport(SENDGRID_KEY, $http), SENDGRID_KEY],
    ];
}

describe('Resend', function () {
    it('posts every field of the message to its API with the key as a bearer token, and hands back its id', function () {
        $http = fake(static fn (): OutboundResponse => new OutboundResponse(200, [], '{"id":"49a3999c-0ce1-4ea6-ab68-afcd6dc2e794"}'));
        $sent = new ResendTransport(RESEND_KEY, $http)->deliver(full());
        $request = $http->sent[0];

        expect($sent->messageId)->toBe('49a3999c-0ce1-4ea6-ab68-afcd6dc2e794')
            ->and($sent->transport)->toBe('resend')
            ->and($request->method)->toBe('POST')
            ->and($request->url)->toBe('https://api.resend.com/emails')
            ->and($request->headers['authorization'])->toBe('Bearer ' . RESEND_KEY)
            ->and($request->destinations?->hosts)->toBe(['api.resend.com'])
            ->and($request->maxRedirects)->toBe(0)
            ->and($request->timeout)->toBe(15.0)
            ->and(body($request))->toBe([
                'from' => '"Pellwick \"Supply\"" <orders@pellwick.example>',
                'to' => ['"Ada Lovelace" <ada@example.test>', 'grace@example.test'],
                'subject' => 'Your order 1042 is on its way',
                'html' => '<p>On its way. <img src="cid:logo"></p>',
                'text' => "On its way.\nhttps://pellwick.example/orders/1042",
                'cc' => ['carol@example.test', 'Ada@Example.test'],
                'bcc' => ['audit@pellwick.example'],
                'reply_to' => '"Pellwick Help" <help@pellwick.example>',
                'headers' => [
                    'List-Unsubscribe' => '<https://pellwick.example/_mail/unsubscribe/v1.abc.def>, <mailto:unsubscribe@pellwick.example>',
                    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                ],
                'attachments' => [
                    ['filename' => 'invoice-1042.pdf', 'content' => base64_encode('%PDF-1.7 invoice'), 'content_type' => 'application/pdf'],
                    ['filename' => 'logo.png', 'content' => base64_encode(PNG), 'content_type' => 'image/png', 'content_id' => 'logo'],
                ],
                'tags' => [['name' => 'shipping', 'value' => 'true'], ['name' => 'order', 'value' => 'true'], ['name' => 'order_id', 'value' => '1042']],
            ]);
    });

    it('never prints its key', function () {
        $transport = new ResendTransport(RESEND_KEY, fake(static fn (): OutboundResponse => new OutboundResponse(200)));

        expect(print_r($transport, true))->not->toContain(RESEND_KEY)
            ->and(var_export((array) $transport, true))->not->toContain(RESEND_KEY)
            ->and(fn () => new ResendTransport("re_x\r\nX-Injected: 1", fake(static fn (): OutboundResponse => new OutboundResponse(200))))->toThrow(\InvalidArgumentException::class);
    });
});

describe('Postmark', function () {
    it('posts every field into its stream with the server token, Inline images by cid:, and hands back its MessageID', function () {
        $http = fake(static fn (): OutboundResponse => new OutboundResponse(200, [], '{"To":"ada@example.test","SubmittedAt":"2026-10-31T10:00:00Z","MessageID":"b7bc2f4a-e38e-4336-af7d-e6c392c2f817","ErrorCode":0,"Message":"OK"}'));
        $sent = new PostmarkTransport(POSTMARK_TOKEN, $http, 'broadcast')->deliver(full());
        $request = $http->sent[0];

        expect($sent->messageId)->toBe('b7bc2f4a-e38e-4336-af7d-e6c392c2f817')
            ->and($request->url)->toBe('https://api.postmarkapp.com/email')
            ->and($request->headers['x-postmark-server-token'])->toBe(POSTMARK_TOKEN)
            ->and($request->destinations?->hosts)->toBe(['api.postmarkapp.com'])
            ->and($request->maxRedirects)->toBe(0)
            ->and(body($request))->toBe([
                'From' => '"Pellwick \"Supply\"" <orders@pellwick.example>',
                'To' => '"Ada Lovelace" <ada@example.test>, grace@example.test',
                'Subject' => 'Your order 1042 is on its way',
                'HtmlBody' => '<p>On its way. <img src="cid:logo"></p>',
                'TextBody' => "On its way.\nhttps://pellwick.example/orders/1042",
                'Cc' => 'carol@example.test, Ada@Example.test',
                'Bcc' => 'audit@pellwick.example',
                'ReplyTo' => '"Pellwick Help" <help@pellwick.example>',
                'Headers' => [
                    ['Name' => 'List-Unsubscribe', 'Value' => '<https://pellwick.example/_mail/unsubscribe/v1.abc.def>, <mailto:unsubscribe@pellwick.example>'],
                    ['Name' => 'List-Unsubscribe-Post', 'Value' => 'List-Unsubscribe=One-Click'],
                ],
                'Attachments' => [
                    ['Name' => 'invoice-1042.pdf', 'Content' => base64_encode('%PDF-1.7 invoice'), 'ContentType' => 'application/pdf'],
                    ['Name' => 'logo.png', 'Content' => base64_encode(PNG), 'ContentType' => 'image/png', 'ContentID' => 'cid:logo'],
                ],
                'Tag' => 'shipping',
                'Metadata' => ['order_id' => '1042'],
                'MessageStream' => 'broadcast',
            ]);
    });

    it('reads a 422\'s ErrorCode: a bad token or an unconfirmed sender is retryable, an inactive recipient is not', function (int $code, bool $retryable) {
        $e = thrown(new PostmarkTransport(POSTMARK_TOKEN, fake(static fn (): OutboundResponse => new OutboundResponse(422, [], json_encode(['ErrorCode' => $code, 'Message' => 'Refused.'], JSON_THROW_ON_ERROR)))));

        expect($e->retryable)->toBe($retryable)->and($e->status)->toBe(422)->and($e->getMessage())->toContain('Postmark answered 422: Refused.');
    })->with([
        'bad token' => [10, true],
        'sender signature not confirmed' => [401, true],
        'stream not found' => [1235, true],
        'inactive recipient' => [406, false],
        'invalid email request' => [300, false],
    ]);

    it('refuses a stream id that is not one', function () {
        expect(fn () => new PostmarkTransport(POSTMARK_TOKEN, fake(static fn (): OutboundResponse => new OutboundResponse(200)), 'out bound'))->toThrow(\InvalidArgumentException::class);
    });
});

describe('Amazon SES', function () {
    it('sends the MIME whole, signed by SigV4 for ses, Bcc on the envelope alone, and hands back its MessageId', function () {
        $http = fake(static fn (): OutboundResponse => new OutboundResponse(200, [], '{"MessageId":"0102018f-ses-message-id"}'));
        $dkim = new DkimSigner('pellwick.example', 'mail2026', DkimKey::parse(base64_encode(random_bytes(32))));
        $transport = new SesTransport(new AwsCredentials('AKIDEXAMPLE', SES_SECRET), 'eu-west-1', $http, 'transactional', $dkim, clock: static fn (): int => NOW);
        $sent = $transport->deliver(full());
        $request = $http->sent[0];
        $payload = body($request);
        $mime = base64_decode((string) $payload['Content']['Raw']['Data'], true);

        expect($sent->messageId)->toBe('0102018f-ses-message-id')
            ->and($request->url)->toBe('https://email.eu-west-1.amazonaws.com/v2/email/outbound-emails')
            ->and($request->destinations?->hosts)->toBe(['email.eu-west-1.amazonaws.com'])
            ->and($request->maxRedirects)->toBe(0)
            ->and($payload['Destination'])->toBe([
                'ToAddresses' => ['ada@example.test', 'grace@example.test'],
                'CcAddresses' => ['carol@example.test', 'Ada@Example.test'],
                'BccAddresses' => ['audit@pellwick.example'],
            ])
            ->and($payload['FromEmailAddress'])->toBe('orders@pellwick.example')
            ->and($payload['ConfigurationSetName'])->toBe('transactional')
            ->and($payload['EmailTags'])->toBe([['Name' => 'shipping', 'Value' => 'true'], ['Name' => 'order', 'Value' => 'true'], ['Name' => 'order_id', 'Value' => '1042']])
            ->and($mime)->toBeString()->toStartWith('DKIM-Signature:')
            ->toContain("\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n")
            ->toContain('Content-ID: <logo>')
            ->toContain('filename="invoice-1042.pdf"')
            ->not->toContain('audit@pellwick.example');

        // SES rewrites Date and Message-ID: the signature leaves both out.
        preg_match('/\bh=([^;]+);/', str_replace(["\r\n ", "\r\n\t"], ' ', (string) $mime), $h);
        expect($h[1])->not->toContain('date')->not->toContain('message-id')->toContain('list-unsubscribe');

        // The signature is the one SigV4 makes for these headers and this body.
        $expected = new SigV4(new AwsCredentials('AKIDEXAMPLE', SES_SECRET), 'eu-west-1', 'ses')
            ->sign('POST', '/v2/email/outbound-emails', [], ['host' => 'email.eu-west-1.amazonaws.com', 'content-type' => 'application/json'], hash('sha256', $request->body), NOW);
        expect($request->headers['authorization'])->toBe($expected['authorization'])
            ->and($request->headers['authorization'])->toContain('Credential=AKIDEXAMPLE/20270115/eu-west-1/ses/aws4_request')
            ->and($request->headers['x-amz-date'])->toBe('20270115T080000Z')
            ->and($request->headers)->not->toHaveKey('host');
    });

    it('reads a 400\'s error type: a rejected message is the message\'s, a paused account the transport\'s', function (int $status, array $headers, string $body, bool $retryable) {
        $e = thrown(new SesTransport(new AwsCredentials('AKIDEXAMPLE', SES_SECRET), 'eu-west-1', fake(static fn (): OutboundResponse => new OutboundResponse($status, $headers, $body)), clock: static fn (): int => NOW));

        expect($e->retryable)->toBe($retryable)->and($e->transport)->toBe('ses');
    })->with([
        'message rejected' => [400, ['x-amzn-ErrorType' => ['MessageRejected:http://internal.amazon.com/coral/com.amazonaws.sesv2/']], '{"message":"Email address is not verified."}', false],
        'bad request' => [400, [], '{"__type":"com.amazonaws.sesv2#BadRequestException","message":"Missing final \'@domain\'"}', false],
        'sending paused' => [400, ['x-amzn-ErrorType' => ['SendingPausedException']], '{"message":"Sending is paused for this account."}', true],
        'too many requests' => [429, ['x-amzn-ErrorType' => ['TooManyRequestsException']], '{"message":"Maximum sending rate exceeded."}', true],
        'configuration set not found' => [404, ['x-amzn-ErrorType' => ['NotFoundException']], '{"message":"Configuration set <transactional> does not exist."}', true],
        'signature refused' => [403, ['x-amzn-ErrorType' => ['SignatureDoesNotMatch']], '{"message":"The request signature we calculated does not match"}', true],
        'a plain 400' => [400, [], '', false],
    ]);

    it('refuses a region that is not one', function () {
        expect(fn () => new SesTransport(new AwsCredentials('AKIDEXAMPLE', SES_SECRET), 'evil.example/x', fake(static fn (): OutboundResponse => new OutboundResponse(200))))->toThrow(\InvalidArgumentException::class);
    });
});

describe('Mailgun', function () {
    it('posts the MIME whole as a form to its region\'s API, every reader a to field, with basic auth, and hands back its id', function (string $region, string $host) {
        $http = fake(static fn (): OutboundResponse => new OutboundResponse(200, [], '{"id":"<20261031100000.1.ABCDEF@mg.pellwick.example>","message":"Queued. Thank you."}'));
        $sent = new MailgunTransport(MAILGUN_KEY, 'mg.pellwick.example', $http, $region)->deliver(full());
        $request = $http->sent[0];

        preg_match('/boundary=(\S+)/', $request->headers['content-type'], $m);
        $parts = [];
        foreach (\array_slice(explode('--' . $m[1], $request->body), 1, -1) as $part) {
            [$head, $content] = explode("\r\n\r\n", $part, 2);
            preg_match('/name="([^"]+)"/', $head, $name);
            $parts[] = [$name[1], substr($content, 0, -2)];
        }
        $fields = array_values(array_filter($parts, static fn (array $p): bool => $p[0] !== 'message'));
        $message = array_values(array_filter($parts, static fn (array $p): bool => $p[0] === 'message'))[0][1];

        expect($sent->messageId)->toBe('20261031100000.1.ABCDEF@mg.pellwick.example')
            ->and($request->url)->toBe("https://{$host}/v3/mg.pellwick.example/messages.mime")
            ->and($request->destinations?->hosts)->toBe([$host])
            ->and($request->headers['authorization'])->toBe('Basic ' . base64_encode('api:' . MAILGUN_KEY))
            ->and($fields)->toBe([
                ['to', 'ada@example.test'], ['to', 'grace@example.test'], ['to', 'carol@example.test'], ['to', 'audit@pellwick.example'],
                ['o:tag', 'shipping'], ['o:tag', 'order'], ['v:order_id', '1042'],
            ])
            ->and($message)->toContain("\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n")
            ->toContain('Content-ID: <logo>')
            ->not->toContain('audit@pellwick.example');
    })->with([['us', 'api.mailgun.net'], ['eu', 'api.eu.mailgun.net']]);

    it('takes a 404 for a domain it does not have here as the configuration\'s, and a 400 as the message\'s', function () {
        expect(thrown(new MailgunTransport(MAILGUN_KEY, 'mg.pellwick.example', fake(static fn (): OutboundResponse => new OutboundResponse(404, [], '{"message":"Domain not found: mg.pellwick.example"}'))))->retryable)->toBeTrue()
            ->and(thrown(new MailgunTransport(MAILGUN_KEY, 'mg.pellwick.example', fake(static fn (): OutboundResponse => new OutboundResponse(400, [], '{"message":"to parameter is not a valid address"}'))))->retryable)->toBeFalse()
            ->and(fn () => new MailgunTransport(MAILGUN_KEY, 'mg.pellwick.example', fake(static fn (): OutboundResponse => new OutboundResponse(200)), 'ap'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new MailgunTransport(MAILGUN_KEY, 'mg.pellwick.example/../x', fake(static fn (): OutboundResponse => new OutboundResponse(200))))->toThrow(\InvalidArgumentException::class);
    });
});

describe('SendGrid', function () {
    it('posts every field, each reader once and the text first, and hands back the X-Message-Id', function () {
        $http = fake(static fn (): OutboundResponse => new OutboundResponse(202, ['X-Message-Id' => ['W8x8sBGqR4mHxnfwDs1Wmw']]));
        $sent = new SendGridTransport(SENDGRID_KEY, $http)->deliver(full());
        $request = $http->sent[0];

        expect($sent->messageId)->toBe('W8x8sBGqR4mHxnfwDs1Wmw')
            ->and($request->url)->toBe('https://api.sendgrid.com/v3/mail/send')
            ->and($request->headers['authorization'])->toBe('Bearer ' . SENDGRID_KEY)
            ->and($request->destinations?->hosts)->toBe(['api.sendgrid.com'])
            ->and(body($request))->toBe([
                'personalizations' => [[
                    'to' => [['email' => 'ada@example.test', 'name' => 'Ada Lovelace'], ['email' => 'grace@example.test']],
                    'cc' => [['email' => 'carol@example.test']],
                    'bcc' => [['email' => 'audit@pellwick.example']],
                ]],
                'from' => ['email' => 'orders@pellwick.example', 'name' => 'Pellwick "Supply"'],
                'subject' => 'Your order 1042 is on its way',
                'content' => [
                    ['type' => 'text/plain', 'value' => "On its way.\nhttps://pellwick.example/orders/1042"],
                    ['type' => 'text/html', 'value' => '<p>On its way. <img src="cid:logo"></p>'],
                ],
                'reply_to' => ['email' => 'help@pellwick.example', 'name' => 'Pellwick Help'],
                'headers' => [
                    'List-Unsubscribe' => '<https://pellwick.example/_mail/unsubscribe/v1.abc.def>, <mailto:unsubscribe@pellwick.example>',
                    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                ],
                'attachments' => [
                    ['content' => base64_encode('%PDF-1.7 invoice'), 'type' => 'application/pdf', 'filename' => 'invoice-1042.pdf', 'disposition' => 'attachment'],
                    ['content' => base64_encode(PNG), 'type' => 'image/png', 'filename' => 'logo.png', 'disposition' => 'inline', 'content_id' => 'logo'],
                ],
                'categories' => ['shipping', 'order'],
                'custom_args' => ['order_id' => '1042'],
            ]);
    });

    it('sends a message with no HTML as its text alone, as every other provider does', function () {
        $http = fake(static fn (): OutboundResponse => new OutboundResponse(202));
        new SendGridTransport(SENDGRID_KEY, $http)->deliver(new Message(new Address('o@pellwick.example'), [new Address('ada@example.test')], 'S', '', 'Just text.'));

        expect(body($http->sent[0])['content'])->toBe([['type' => 'text/plain', 'value' => 'Just text.']]);
    });

    it('goes to the EU API for an EU account', function () {
        $http = fake(static fn (): OutboundResponse => new OutboundResponse(202));
        new SendGridTransport(SENDGRID_KEY, $http, 'eu')->deliver(full());

        expect($http->sent[0]->url)->toBe('https://api.eu.sendgrid.com/v3/mail/send')
            ->and($http->sent[0]->destinations?->hosts)->toBe(['api.eu.sendgrid.com']);
    });
});

describe('every provider', function () {
    it('is never handed a tag and a metadata key of one name, which Resend and SES would send as two tags of that name', function () {
        $message = static fn (array $tags, array $metadata): Message => new Message(new Address('o@pellwick.example'), [new Address('ada@example.test')], 'S', '<p>x</p>', 'x', tags: $tags, metadata: $metadata);

        expect(fn () => $message(['order'], ['order' => '1042']))->toThrow(\InvalidArgumentException::class, 'order')
            ->and(fn () => $message(['order', 'order'], []))->toThrow(\InvalidArgumentException::class, 'order')
            ->and($message(['order'], ['order_id' => '1042'])->tags)->toBe(['order']);
    });

    it('takes a 429 or a 5xx as retryable, a 401 as the transport\'s, and a 400 or 422 as the message\'s', function (\Closure $make, string $secret, int $status, bool $retryable) {
        $e = thrown($make(fake(static fn (): OutboundResponse => new OutboundResponse($status, [], '{"message":"Refused."}'))));

        expect($e->retryable)->toBe($retryable)->and($e->status)->toBe($status);
    })->with(function (): \Generator {
        foreach (transports() as $name => [$make, $secret]) {
            foreach ([429 => true, 500 => true, 503 => true, 401 => true, 422 => false, 400 => false] as $status => $retryable) {
                if ($name === 'ses' && $status === 400) {
                    continue;
                }
                yield "{$name} {$status}" => [$make, $secret, $status, $retryable];
            }
        }
    });

    it('takes a request that did not finish as retryable', function (\Closure $make, string $secret) {
        $e = thrown($make(fake(static fn (OutboundRequest $r): OutboundResponse => throw new OutboundFailed(OutboundFailed::TIMEOUT, 'POST https://provider timed out.'))));

        expect($e->retryable)->toBeTrue()->and($e->getMessage())->toContain('did not answer: timeout');
    })->with(transports());

    it('cuts its key out of an error that quotes it back, and every address with it', function (\Closure $make, string $secret) {
        $e = thrown($make(fake(static fn (): OutboundResponse => new OutboundResponse(401, [], json_encode(['message' => "API key {$secret} is not valid for ada@example.test"], JSON_THROW_ON_ERROR)))));

        expect($e->getMessage())->not->toContain($secret)->not->toContain('ada@example.test')->toContain('[redacted]')
            ->and((string) $e)->not->toContain($secret);
    })->with(transports());

    it('refuses to follow a redirect to anywhere, and takes it as permanent: a misconfiguration no retry or failover mends', function (\Closure $make, string $secret) {
        $e = thrown($make(fake(static fn (): OutboundResponse => new OutboundResponse(302, ['Location' => ['https://evil.example/steal']]))));

        expect($e->status)->toBe(302)->and($e->retryable)->toBeFalse();
    })->with(transports());

    it('is held to what is left of a failover\'s deadline, and never given longer than its own timeout', function (\Closure $make, string $secret) {
        $http = fake(static fn (): OutboundResponse => new OutboundResponse(500));
        $transport = $make($http);
        expect($transport)->toBeInstanceOf(Bounded::class);
        \assert($transport instanceof Bounded && $transport instanceof Delivers);

        foreach ([$transport->within(4.5), $transport->within(90.0), $transport] as $held) {
            \assert($held instanceof Delivers);
            thrown($held);
        }

        expect(array_map(static fn (OutboundRequest $r): ?float => $r->timeout, $http->sent))->toBe([4.5, 15.0, 15.0]);
    })->with(transports());
});
