<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Aws\AwsCredentials;
use Tinvar\Aws\SigV4;
use Tinvar\Mail\Address;
use Tinvar\Mail\Delivers;
use Tinvar\Mail\DkimSigner;
use Tinvar\Mail\Message;
use Tinvar\Mail\MimeMessage;
use Tinvar\Mail\SentMessage;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundResponse;

/**
 * Sends through Amazon SES's v2 API: `POST /v2/email/outbound-emails` at
 * `email.{region}.amazonaws.com`, signed with SigV4 for the `ses` service
 * by the signer S3 uses (Tinvar\Aws) (ADR 0147).
 *
 * The message goes as raw MIME -- the bytes MimeMessage writes, base64'd as
 * `Content.Raw.Data` -- so what SMTP would send is what SES sends: the
 * List-Unsubscribe headers, the files, the inline images, and a DKIM
 * signature of ours where a DkimSigner is given. SES writes its own Date
 * and Message-ID over ours, so the signature leaves those two out
 * (DkimSigner::excluding()); SES's own Easy DKIM signature, where the
 * domain has one, goes beside it. The readers are named in `Destination`
 * as well, the envelope, which is the only place Bcc is.
 *
 * Tags and metadata are SES's `EmailTags`, a tag as its name with the value
 * `true`; a configuration set, which is where SES publishes the message's
 * bounces and complaints from, is `ConfigurationSetName`.
 *
 * A 400 is judged by SES's error type: MessageRejected and BadRequest are
 * the message's own; a paused or suspended account, a sending limit, a
 * domain not verified or a configuration set not found are the
 * transport's, and retryable.
 */
final readonly class SesTransport implements Delivers
{
    public const string NAME = 'ses';

    /** The error types that are the message's own; every other 4xx but a plain 400's is the transport's. */
    public const array PERMANENT_TYPES = ['MessageRejected', 'BadRequestException', 'InvalidParameterValue', 'ValidationException'];

    private string $host;

    private SigV4 $signer;

    private ?DkimSigner $dkim;

    private ProviderHttp $http;

    /**
     * @param (\Closure(): int)|null $clock seconds since the epoch, for the signature's date; time() unless given
     */
    public function __construct(
        private AwsCredentials $credentials,
        string $region,
        OutboundHttp $http,
        private ?string $configurationSet = null,
        ?DkimSigner $dkim = null,
        float $timeout = ProviderHttp::DEFAULT_TIMEOUT,
        private ?\Closure $clock = null,
    ) {
        if (preg_match('/^[a-z]{2}(-[a-z]+)+-\d{1,2}\z/', $region) !== 1) {
            throw new \InvalidArgumentException("'{$region}' is not an AWS region: eu-west-1, us-east-1...");
        }
        if ($configurationSet !== null && preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $configurationSet) !== 1) {
            throw new \InvalidArgumentException("'{$configurationSet}' is not an SES configuration set's name.");
        }
        $this->host = "email.{$region}.amazonaws.com";
        $this->signer = new SigV4($credentials, $region, 'ses');
        $this->dkim = $dkim?->excluding('date', 'message-id');
        $this->http = new ProviderHttp($http, self::NAME, 'Amazon SES', [$this->host], $timeout, $credentials->redact(...));
    }

    public function send(Message $message): void
    {
        $this->deliver($message);
    }

    public function deliver(Message $message): SentMessage
    {
        $from = $message->from->email;
        $mime = MimeMessage::build($message, substr($from, (int) strrpos($from, '@') + 1), $this->now(), $this->dkim);
        $emails = static fn (array $readers): array => array_map(static fn (Address $a): string => $a->email, $readers);

        $destination = ['ToAddresses' => $emails($message->to)];
        if ($message->cc !== []) {
            $destination['CcAddresses'] = $emails($message->cc);
        }
        if ($message->bcc !== []) {
            $destination['BccAddresses'] = $emails($message->bcc);
        }
        $payload = [
            'FromEmailAddress' => $from,
            'Destination' => $destination,
            'Content' => ['Raw' => ['Data' => base64_encode($mime)]],
        ];
        $tags = [
            ...array_map(static fn (string $tag): array => ['Name' => $tag, 'Value' => 'true'], $message->tags),
            ...array_map(static fn (string $name, string $value): array => ['Name' => $name, 'Value' => $value], array_keys($message->metadata), $message->metadata),
        ];
        if ($tags !== []) {
            $payload['EmailTags'] = $tags;
        }
        if ($this->configurationSet !== null) {
            $payload['ConfigurationSetName'] = $this->configurationSet;
        }

        $body = $this->http->encode($payload);
        $path = '/v2/email/outbound-emails';
        $headers = ['host' => $this->host, 'content-type' => 'application/json'];
        $signed = $this->signer->sign('POST', $path, [], $headers, hash('sha256', $body), $this->now());
        unset($headers['host']);

        $secrets = array_values(array_filter([$signed['authorization'] ?? null, $signed['x-amz-security-token'] ?? null]));
        $response = $this->http->send('POST', "https://{$this->host}{$path}", [...$headers, ...$signed, 'accept' => 'application/json'], $body, $secrets, self::retryable(...));
        $id = ProviderHttp::json($response)['MessageId'] ?? null;

        return new SentMessage($message, \is_string($id) && $id !== '' ? $id : null, self::NAME);
    }

    /** SES's error type, from its header or its body. */
    public static function errorType(OutboundResponse $response): ?string
    {
        $type = $response->header('x-amzn-errortype');
        if ($type === null) {
            $json = ProviderHttp::json($response);
            $type = \is_string($json['__type'] ?? null) ? $json['__type'] : (\is_string($json['code'] ?? null) ? $json['code'] : null);
        }
        if ($type === null) {
            return null;
        }
        // `MessageRejected:http://internal.amazon.com/...`, or a namespaced `...#MessageRejected`.
        $type = explode(':', $type, 2)[0];

        return substr($type, (int) strrpos('#' . $type, '#'));
    }

    private static function retryable(OutboundResponse $response): ?bool
    {
        if ($response->status !== 400) {
            return null;
        }
        $type = self::errorType($response);

        return $type !== null && !\in_array($type, self::PERMANENT_TYPES, true);
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'accessKey' => $this->credentials->accessKey, 'configurationSet' => $this->configurationSet];
    }
}
