<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Config\Secret;
use Tinvar\Mail\Attachment;
use Tinvar\Mail\Delivers;
use Tinvar\Mail\Message;
use Tinvar\Mail\SentMessage;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundResponse;

/**
 * Sends through Postmark's API: `POST https://api.postmarkapp.com/email`,
 * the server token in `X-Postmark-Server-Token`, into a message stream --
 * `outbound` unless told, `broadcast` for marketing (ADR 0146).
 *
 * Postmark takes JSON alone, so the message is mapped field by field:
 * From, To, Cc, Bcc, ReplyTo, Subject, HtmlBody, TextBody; the
 * List-Unsubscribe headers under `Headers`; each file under `Attachments`,
 * an inline image's `ContentID` as `cid:` and its id. Postmark keeps one
 * `Tag` a message: the first of its tags. Its metadata is `Metadata`.
 *
 * A 422 is judged by Postmark's ErrorCode: a bad token (10), a sender
 * signature missing or unconfirmed (400, 401), an account that may not send
 * (405, 412, 413) or a stream that is not there (1235) is the transport's
 * configuration, and retryable -- a failover gets past it; an inactive
 * recipient (406), an address it will not take (300) or anything else
 * about the message is not.
 */
final readonly class PostmarkTransport implements Delivers
{
    public const string NAME = 'postmark';

    public const string HOST = 'api.postmarkapp.com';

    /** Postmark's error codes that say the configuration, not the message, is wrong. */
    public const array RETRYABLE_CODES = [10, 400, 401, 405, 412, 413, 1235];

    private Secret $token;

    private ProviderHttp $http;

    /**
     * @param string|null $stream the message stream's id: `outbound` when null
     */
    public function __construct(
        #[\SensitiveParameter]
        Secret|string $token,
        OutboundHttp $http,
        private ?string $stream = null,
        float $timeout = ProviderHttp::DEFAULT_TIMEOUT,
    ) {
        $this->token = $token instanceof Secret ? $token : new Secret($token);
        if (preg_match('/^[\x21-\x7e]{8,256}\z/', $this->token->reveal()) !== 1) {
            throw new \InvalidArgumentException('A Postmark server token is printable ASCII, with no space.');
        }
        if ($stream !== null && preg_match('/^[a-z0-9_-]{1,64}\z/', $stream) !== 1) {
            throw new \InvalidArgumentException("'{$stream}' is not a Postmark message stream's id.");
        }
        $this->http = new ProviderHttp($http, self::NAME, 'Postmark', [self::HOST], $timeout);
    }

    public function send(Message $message): void
    {
        $this->deliver($message);
    }

    public function deliver(Message $message): SentMessage
    {
        $payload = [
            'From' => Fields::address($message->from),
            'To' => implode(', ', Fields::addresses($message->to)),
            'Subject' => $message->subject,
            'HtmlBody' => $message->html,
            'TextBody' => $message->text,
        ];
        if ($message->cc !== []) {
            $payload['Cc'] = implode(', ', Fields::addresses($message->cc));
        }
        if ($message->bcc !== []) {
            $payload['Bcc'] = implode(', ', Fields::addresses($message->bcc));
        }
        if ($message->replyTo !== null) {
            $payload['ReplyTo'] = Fields::address($message->replyTo);
        }
        $headers = Fields::headers($message);
        if ($headers !== []) {
            $payload['Headers'] = array_map(static fn (string $name, string $value): array => ['Name' => $name, 'Value' => $value], array_keys($headers), $headers);
        }
        if ($message->attachments !== []) {
            $payload['Attachments'] = array_map(static fn (Attachment $a): array => array_filter([
                'Name' => $a->name,
                'Content' => Fields::content($a),
                'ContentType' => $a->type,
                'ContentID' => $a->contentId === null ? null : "cid:{$a->contentId}",
            ], static fn (?string $value): bool => $value !== null), $message->attachments);
        }
        if ($message->tags !== []) {
            $payload['Tag'] = $message->tags[0];
        }
        if ($message->metadata !== []) {
            $payload['Metadata'] = $message->metadata;
        }
        if ($this->stream !== null) {
            $payload['MessageStream'] = $this->stream;
        }

        $token = $this->token->reveal();
        $response = $this->http->send('POST', 'https://' . self::HOST . '/email', [
            'X-Postmark-Server-Token' => $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $this->http->encode($payload), [$token], self::retryable(...));
        $id = ProviderHttp::json($response)['MessageID'] ?? null;

        return new SentMessage($message, \is_string($id) && $id !== '' ? $id : null, self::NAME);
    }

    /** A 422's ErrorCode, read: retryable for the configuration's, permanent for the message's. */
    private static function retryable(OutboundResponse $response): ?bool
    {
        if ($response->status !== 422) {
            return null;
        }
        $code = ProviderHttp::json($response)['ErrorCode'] ?? null;

        return \is_int($code) && \in_array($code, self::RETRYABLE_CODES, true);
    }
}
