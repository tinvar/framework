<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Config\Secret;
use Tinvar\Mail\Bounded;
use Tinvar\Mail\Delivers;
use Tinvar\Mail\DkimSigner;
use Tinvar\Mail\Message;
use Tinvar\Mail\MimeMessage;
use Tinvar\Mail\SentMessage;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundResponse;

/**
 * Sends through Mailgun's API: `POST /v3/{domain}/messages.mime`, at
 * `api.mailgun.net` in the US region or `api.eu.mailgun.net` in the EU --
 * a domain lives in one, and its key answers there alone -- with HTTP basic
 * auth as `api` and the key (ADR 0147).
 *
 * The message goes as raw MIME, the bytes MimeMessage writes, as the
 * `message` file of a multipart form: the List-Unsubscribe headers, the
 * files, the inline images and a DKIM signature of ours survive as SMTP
 * would send them. The readers are the form's `to` fields, every one of
 * them, Bcc too: the envelope. Tags are `o:tag` (Mailgun keeps three), and
 * metadata `v:` variables, which Mailgun hands back in its events.
 *
 * A 404 is a domain Mailgun does not have under this key, or not in this
 * region: the configuration, retryable as a 401 is.
 */
final readonly class MailgunTransport implements Delivers, Bounded
{
    public const string NAME = 'mailgun';

    public const array HOSTS = ['us' => 'api.mailgun.net', 'eu' => 'api.eu.mailgun.net'];

    /** The most tags Mailgun keeps with a message. */
    public const int MAX_TAGS = 3;

    private Secret $key;

    private string $host;

    private ProviderHttp $http;

    /**
     * @param string $domain the sending domain, as Mailgun has it: `mg.pellwick.example`
     * @param string $region `us` or `eu`, where the domain was created
     */
    public function __construct(
        #[\SensitiveParameter]
        Secret|string $key,
        private string $domain,
        OutboundHttp $http,
        string $region = 'us',
        private ?DkimSigner $dkim = null,
        float $timeout = ProviderHttp::DEFAULT_TIMEOUT,
    ) {
        $this->key = $key instanceof Secret ? $key : new Secret($key);
        if (preg_match('/^[\x21-\x7e]{8,256}\z/', $this->key->reveal()) !== 1) {
            throw new \InvalidArgumentException('A Mailgun API key is printable ASCII, with no space.');
        }
        if (preg_match('/^(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]\z/', $domain) !== 1) {
            throw new \InvalidArgumentException("'{$domain}' is not a Mailgun sending domain: a lowercase domain name.");
        }
        $this->host = self::HOSTS[$region] ?? throw new \InvalidArgumentException("'{$region}' is not a Mailgun region: us or eu.");
        $this->http = new ProviderHttp($http, self::NAME, 'Mailgun', [$this->host], $timeout);
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
        $from = $message->from->email;
        $mime = MimeMessage::build($message, substr($from, (int) strrpos($from, '@') + 1), dkim: $this->dkim);

        $fields = [];
        foreach ($message->envelope() as $reader) {
            $fields[] = ['to', $reader];
        }
        foreach (\array_slice($message->tags, 0, self::MAX_TAGS) as $tag) {
            $fields[] = ['o:tag', $tag];
        }
        foreach ($message->metadata as $name => $value) {
            $fields[] = ["v:{$name}", $value];
        }
        [$contentType, $body] = self::form($fields, ['message', 'message.mime', 'message/rfc822', $mime]);

        $key = $this->key->reveal();
        $basic = base64_encode("api:{$key}");
        $response = $this->http->send('POST', "https://{$this->host}/v3/{$this->domain}/messages.mime", [
            'Authorization' => "Basic {$basic}",
            'Content-Type' => $contentType,
            'Accept' => 'application/json',
        ], $body, [$key, $basic], self::retryable(...));
        $id = ProviderHttp::json($response)['id'] ?? null;

        return new SentMessage($message, \is_string($id) && $id !== '' ? trim($id, '<>') : null, self::NAME);
    }

    /**
     * A multipart/form-data body of $fields and one file: its content type,
     * boundary and all, and its bytes. The names and values are Mailgun's
     * own or held to letters, digits, `_`, `-` and `:` before they get
     * here, and an address holds no quote or line break; the boundary is
     * random and checked against what it bounds.
     *
     * @param list<array{string, string}> $fields
     * @param array{string, string, string, string} $file name, filename, type, bytes
     * @return array{string, string}
     */
    public static function form(array $fields, array $file): array
    {
        do {
            $boundary = 'tinvar-form-' . bin2hex(random_bytes(16));
            $clash = str_contains($file[3], $boundary);
        } while ($clash);

        $body = '';
        foreach ($fields as [$name, $value]) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        [$name, $filename, $type, $bytes] = $file;
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\nContent-Type: {$type}\r\n\r\n{$bytes}\r\n--{$boundary}--\r\n";

        return ["multipart/form-data; boundary={$boundary}", $body];
    }

    private static function retryable(OutboundResponse $response): ?bool
    {
        return $response->status === 404 ? true : null;
    }
}
