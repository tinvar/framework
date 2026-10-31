<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Config\Secret;
use Tinvar\Mail\Address;
use Tinvar\Mail\Attachment;
use Tinvar\Mail\Delivers;
use Tinvar\Mail\Message;
use Tinvar\Mail\SentMessage;
use Tinvar\Outbound\OutboundHttp;

/**
 * Sends through SendGrid's v3 API: `POST /v3/mail/send` at
 * `api.sendgrid.com`, or `api.eu.sendgrid.com` for an EU account, the key
 * as a bearer token (ADR 0147).
 *
 * SendGrid takes JSON alone, so the message is mapped field by field: one
 * personalization of To, Cc and Bcc -- each address once, as SendGrid
 * refuses one named twice -- from, reply_to, subject, and `content` with
 * the text first, as SendGrid requires; the List-Unsubscribe headers under
 * `headers`; each file under `attachments`, an inline image `inline` with
 * its `content_id`. Tags are `categories` and metadata `custom_args`, both
 * of which SendGrid's events carry back.
 *
 * SendGrid answers 202 with no body; the message's id is its X-Message-Id
 * header.
 */
final readonly class SendGridTransport implements Delivers
{
    public const string NAME = 'sendgrid';

    public const array HOSTS = ['global' => 'api.sendgrid.com', 'eu' => 'api.eu.sendgrid.com'];

    private Secret $key;

    private string $host;

    private ProviderHttp $http;

    /**
     * @param string $region `global`, or `eu` for an account whose data SendGrid keeps in the EU
     */
    public function __construct(
        #[\SensitiveParameter]
        Secret|string $key,
        OutboundHttp $http,
        string $region = 'global',
        float $timeout = ProviderHttp::DEFAULT_TIMEOUT,
    ) {
        $this->key = $key instanceof Secret ? $key : new Secret($key);
        if (preg_match('/^[\x21-\x7e]{8,256}\z/', $this->key->reveal()) !== 1) {
            throw new \InvalidArgumentException('A SendGrid API key is printable ASCII, with no space.');
        }
        $this->host = self::HOSTS[$region] ?? throw new \InvalidArgumentException("'{$region}' is not a SendGrid region: global or eu.");
        $this->http = new ProviderHttp($http, self::NAME, 'SendGrid', [$this->host], $timeout);
    }

    public function send(Message $message): void
    {
        $this->deliver($message);
    }

    public function deliver(Message $message): SentMessage
    {
        [$to, $cc, $bcc] = Fields::distinctReaders($message);
        $personalization = ['to' => self::people($to)];
        if ($cc !== []) {
            $personalization['cc'] = self::people($cc);
        }
        if ($bcc !== []) {
            $personalization['bcc'] = self::people($bcc);
        }

        $payload = [
            'personalizations' => [$personalization],
            'from' => self::person($message->from),
            'subject' => $message->subject,
            'content' => [
                ['type' => 'text/plain', 'value' => $message->text],
                ['type' => 'text/html', 'value' => $message->html],
            ],
        ];
        if ($message->replyTo !== null) {
            $payload['reply_to'] = self::person($message->replyTo);
        }
        $headers = Fields::headers($message);
        if ($headers !== []) {
            $payload['headers'] = $headers;
        }
        if ($message->attachments !== []) {
            $payload['attachments'] = array_map(static fn (Attachment $a): array => array_filter([
                'content' => Fields::content($a),
                'type' => $a->type,
                'filename' => $a->name,
                'disposition' => $a->isInline() ? 'inline' : 'attachment',
                'content_id' => $a->contentId,
            ], static fn (?string $value): bool => $value !== null), $message->attachments);
        }
        if ($message->tags !== []) {
            $payload['categories'] = $message->tags;
        }
        if ($message->metadata !== []) {
            $payload['custom_args'] = $message->metadata;
        }

        $key = $this->key->reveal();
        $response = $this->http->send('POST', "https://{$this->host}/v3/mail/send", [
            'Authorization' => "Bearer {$key}",
            'Content-Type' => 'application/json',
        ], $this->http->encode($payload), [$key]);
        $id = $response->header('x-message-id');

        return new SentMessage($message, $id !== null && $id !== '' ? $id : null, self::NAME);
    }

    /** @return array{email: string, name?: string} */
    private static function person(Address $address): array
    {
        return $address->name === '' ? ['email' => $address->email] : ['email' => $address->email, 'name' => $address->name];
    }

    /**
     * @param list<Address> $addresses
     * @return list<array{email: string, name?: string}>
     */
    private static function people(array $addresses): array
    {
        return array_map(self::person(...), $addresses);
    }
}
