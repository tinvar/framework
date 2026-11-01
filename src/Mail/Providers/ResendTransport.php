<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Config\Secret;
use Tinvar\Mail\Attachment;
use Tinvar\Mail\Bounded;
use Tinvar\Mail\Delivers;
use Tinvar\Mail\Message;
use Tinvar\Mail\SentMessage;
use Tinvar\Outbound\OutboundHttp;

/**
 * Sends through Resend's API: `POST https://api.resend.com/emails`, the key
 * as a bearer token (ADR 0147).
 *
 * Resend takes JSON alone, never MIME of ours, so the message is mapped
 * field by field: from, to, cc, bcc, reply_to, subject, html, text; the
 * List-Unsubscribe headers under `headers`; each file under `attachments`,
 * an inline image with its `content_id`; tags and metadata as Resend's
 * `tags`, a tag as `name` with the value `true`. Resend signs with DKIM for
 * the domain it verified; a DkimSigner of ours is not used here.
 *
 * The key is a Secret: a dump of this shows `[redacted]`, and every error
 * has it cut out. The id Resend answers with is the SentMessage's.
 */
final readonly class ResendTransport implements Delivers, Bounded
{
    public const string NAME = 'resend';

    public const string HOST = 'api.resend.com';

    private Secret $key;

    private ProviderHttp $http;

    public function __construct(#[\SensitiveParameter] Secret|string $key, OutboundHttp $http, float $timeout = ProviderHttp::DEFAULT_TIMEOUT)
    {
        $this->key = $key instanceof Secret ? $key : new Secret($key);
        if (preg_match('/^[\x21-\x7e]{8,256}\z/', $this->key->reveal()) !== 1) {
            throw new \InvalidArgumentException('A Resend API key is printable ASCII, with no space.');
        }
        $this->http = new ProviderHttp($http, self::NAME, 'Resend', [self::HOST], $timeout);
    }

    public function within(float $seconds): static
    {
        return clone($this, ['http' => $this->http->within($seconds)]);
    }

    public function send(Message $message): void
    {
        $this->deliver($message);
    }

    public function deliver(Message $message): SentMessage
    {
        $payload = [
            'from' => Fields::address($message->from),
            'to' => Fields::addresses($message->to),
            'subject' => $message->subject,
            'html' => $message->html,
            'text' => $message->text,
        ];
        if ($message->cc !== []) {
            $payload['cc'] = Fields::addresses($message->cc);
        }
        if ($message->bcc !== []) {
            $payload['bcc'] = Fields::addresses($message->bcc);
        }
        if ($message->replyTo !== null) {
            $payload['reply_to'] = Fields::address($message->replyTo);
        }
        $headers = Fields::headers($message);
        if ($headers !== []) {
            $payload['headers'] = $headers;
        }
        if ($message->attachments !== []) {
            $payload['attachments'] = array_map(static fn (Attachment $a): array => array_filter([
                'filename' => $a->name,
                'content' => Fields::content($a),
                'content_type' => $a->type,
                'content_id' => $a->contentId,
            ], static fn (?string $value): bool => $value !== null), $message->attachments);
        }
        $tags = [
            ...array_map(static fn (string $tag): array => ['name' => $tag, 'value' => 'true'], $message->tags),
            ...array_map(static fn (string $name, string $value): array => ['name' => $name, 'value' => $value], array_keys($message->metadata), $message->metadata),
        ];
        if ($tags !== []) {
            $payload['tags'] = $tags;
        }

        $key = $this->key->reveal();
        $response = $this->http->send('POST', 'https://' . self::HOST . '/emails', [
            'Authorization' => "Bearer {$key}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $this->http->encode($payload), [$key]);
        $id = ProviderHttp::json($response)['id'] ?? null;

        return new SentMessage($message, \is_string($id) && $id !== '' ? $id : null, self::NAME);
    }
}
