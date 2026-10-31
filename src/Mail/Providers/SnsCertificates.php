<?php

declare(strict_types=1);

namespace Tinvar\Mail\Providers;

use Tinvar\Http\HttpException;
use Tinvar\Outbound\Destinations;
use Tinvar\Outbound\OutboundFailed;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\OutboundRequest;

/**
 * The certificates SNS signs its messages with, fetched from where a
 * message's SigningCertURL says -- and only from where SNS keeps them.
 *
 * The URL is the sender's to write, so it is the request the application
 * makes on an attacker's say-so: a server-side request forgery, and a
 * signature checked against a key the forger chose. So it is held, before
 * anything is fetched, to the one shape SNS's URLs have: https, no port but
 * 443, no credentials, no query or fragment, a host `sns.<region>.amazonaws.com`
 * (or `.amazonaws.com.cn`), and a path `/SimpleNotificationService-<hex>.pem`.
 * Then fetched through the application's OutboundHttp, pinned to that one
 * host, following no redirect, within five seconds and 16 KB -- TLS
 * verified, so the key is the one amazonaws.com serves. A certificate not
 * valid now is refused.
 *
 * Kept for the worker's life, by URL, a few at a time: SNS signs with one
 * certificate a region for months, and a fetch per notification would be
 * a round trip to AWS per bounce.
 */
final class SnsCertificates
{
    /** The host SNS serves its signing certificates from. */
    public const string HOST = '/^sns\.[a-z]{2}(?:-[a-z]+)+-\d{1,2}\.amazonaws\.com(?:\.cn)?\z/';

    /** The path one has. */
    public const string PATH = '/^\/SimpleNotificationService-[A-Za-z0-9]{1,64}\.pem\z/';

    /** Certificates kept at once. */
    private const int KEEP = 8;

    /** @var array<string, array{\OpenSSLAsymmetricKey, int, int}> URL => key, valid from, valid to */
    private array $kept = [];

    public function __construct(private readonly OutboundHttp $http) {}

    /**
     * Whether $url is where SNS keeps a signing certificate -- or, given
     * PATH as null, any URL of an SNS host, as a SubscribeURL is.
     */
    public static function isSnsUrl(string $url, ?string $path = self::PATH): bool
    {
        if (\strlen($url) > 2048) {
            return false;
        }
        $parts = parse_url($url);

        return \is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && preg_match(self::HOST, $parts['host'] ?? '') === 1
            && (($parts['port'] ?? 443) === 443)
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['fragment'])
            && ($path === null || (!isset($parts['query']) && preg_match($path, $parts['path'] ?? '') === 1));
    }

    /**
     * The public key the certificate at $url holds, valid at $now.
     *
     * @throws HttpException 401 for a URL that is not SNS's, a certificate that cannot be had or read, or one not valid now
     */
    public function key(string $url, int $now): \OpenSSLAsymmetricKey
    {
        if (!self::isSnsUrl($url)) {
            throw Payload::unverified('The notification\'s SigningCertURL is not where SNS keeps its certificates.');
        }

        $kept = $this->kept[$url] ?? $this->fetch($url);
        [$key, $from, $to] = $kept;
        if ($now < $from || $now > $to) {
            unset($this->kept[$url]);

            throw Payload::unverified('The SNS signing certificate is not valid now.');
        }

        return $key;
    }

    /** @return array{\OpenSSLAsymmetricKey, int, int} */
    private function fetch(string $url): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        try {
            $response = $this->http->send(new OutboundRequest(
                'GET',
                $url,
                timeout: 5.0,
                maxResponseBytes: 16_384,
                maxRedirects: 0,
                destinations: Destinations::hosts([$host]),
            ));
        } catch (OutboundRefused|OutboundFailed) {
            throw Payload::unverified('The SNS signing certificate could not be fetched.');
        }
        if ($response->status !== 200) {
            throw Payload::unverified('The SNS signing certificate could not be fetched.');
        }

        $certificate = @openssl_x509_read($response->body);
        $parsed = $certificate === false ? false : openssl_x509_parse($certificate);
        $key = $certificate === false ? false : openssl_pkey_get_public($certificate);
        if ($certificate === false || !\is_array($parsed) || $key === false) {
            throw Payload::unverified('The SNS signing certificate could not be read.');
        }

        if (\count($this->kept) >= self::KEEP) {
            array_shift($this->kept);
        }

        return $this->kept[$url] = [$key, (int) ($parsed['validFrom_time_t'] ?? PHP_INT_MAX), (int) ($parsed['validTo_time_t'] ?? 0)];
    }
}
