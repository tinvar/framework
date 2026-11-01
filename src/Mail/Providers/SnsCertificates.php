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
 *
 * Nothing about a message is verified before its certificate is had, so
 * anyone who knows a topic's ARN can have the worker fetch. So the URL's
 * host must be the SNS host of the region and partition the message's
 * topic is in (hostFor()) -- anything else is refused outright, fetching
 * nothing and spending nothing. A URL that could not be fetched or read is
 * remembered for FAILURE_TTL seconds and refused without asking again, and
 * URLs the worker does not hold are fetched from a small bucket per client
 * address -- the address the throttles read, through the trusted proxies
 * -- FETCH_BURST at once, one more every FETCH_EVERY seconds, past which a
 * message is answered 429, which SNS retries. A forger cannot send from
 * AWS's addresses, so a flood of made-up paths on the right host drains the
 * forger's own bucket alone, never the one SNS's deliveries draw on when it
 * rotates its certificate. The last certificate whose signature verified
 * is remembered for each host (verified()), outside the few kept, and a
 * certificate held is answered without touching any bucket.
 */
final class SnsCertificates
{
    /** The host SNS serves its signing certificates from. */
    public const string HOST = '/^sns\.[a-z]{2}(?:-[a-z]+)+-\d{1,2}\.amazonaws\.com(?:\.cn)?\z/';

    /** The path one has. */
    public const string PATH = '/^\/SimpleNotificationService-[A-Za-z0-9]{1,64}\.pem\z/';

    /** Certificates kept at once. */
    private const int KEEP = 8;

    /** How long a URL that could not be fetched or read is refused without asking again, in seconds. */
    public const int FAILURE_TTL = 60;

    /** Fetches of URLs not held, at once, per worker. */
    public const int FETCH_BURST = 4;

    /** Seconds until the bucket has one more fetch. */
    public const int FETCH_EVERY = 15;

    /** URLs remembered as failed at once. */
    private const int FAILURES = 64;

    /** @var array<string, array{\OpenSSLAsymmetricKey, int, int}> URL => key, valid from, valid to */
    private array $kept = [];

    /** @var array<string, int> URL => until when it is refused without a fetch */
    private array $failed = [];

    /** Client addresses whose buckets are kept at once. */
    private const int BUCKETS = 256;

    /** @var array<string, array{float, int}> client address => fetches left in its bucket, when it was last filled */
    private array $buckets = [];

    /** @var array<string, array{string, array{\OpenSSLAsymmetricKey, int, int}}> host => the URL of the last certificate a signature verified with, and it */
    private array $verified = [];

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
     * The SNS host that serves the signing certificates for messages from
     * the topic $topicArn names -- `sns.<region>.amazonaws.com`, or
     * `.amazonaws.com.cn` in the China partition -- or null for an ARN that
     * is not an SNS topic's.
     */
    public static function hostFor(string $topicArn): ?string
    {
        if (preg_match('/^arn:(aws(?:-cn|-us-gov)?):sns:([a-z]{2}(?:-[a-z]+)+-\d{1,2}):/', $topicArn, $m) !== 1) {
            return null;
        }

        return "sns.{$m[2]}.amazonaws.com" . ($m[1] === 'aws-cn' ? '.cn' : '');
    }

    /**
     * The public key the certificate at $url holds, valid at $now.
     *
     * @param string|null $host the host the URL must name: hostFor() the message's topic
     * @param string $client the address the request came from, whose bucket a fetch is taken from; '' for one shared by every caller not named
     * @throws HttpException 401 for a URL that is not SNS's or not $host's, a certificate that cannot be had or read, or one not valid now
     */
    public function key(string $url, int $now, ?string $host = null, string $client = ''): \OpenSSLAsymmetricKey
    {
        if (!self::isSnsUrl($url)) {
            throw Payload::unverified('The notification\'s SigningCertURL is not where SNS keeps its certificates.');
        }
        if ($host !== null && parse_url($url, PHP_URL_HOST) !== $host) {
            throw Payload::unverified('The notification\'s SigningCertURL is not the SNS host of its topic\'s region.');
        }

        $last = $this->verified[(string) parse_url($url, PHP_URL_HOST)] ?? null;
        $kept = ($last !== null && $last[0] === $url ? $last[1] : null) ?? $this->kept[$url] ?? $this->fetchOnce($url, $now, $client);
        [$key, $from, $to] = $kept;
        if ($now < $from || $now > $to) {
            unset($this->kept[$url]);

            throw Payload::unverified('The SNS signing certificate is not valid now.');
        }

        return $key;
    }

    /**
     * Records that a signature verified with the certificate at $url, which
     * key() has handed out: the last such for its host is answered from
     * then on whatever is kept, fetched or failed.
     */
    public function verified(string $url): void
    {
        $kept = $this->kept[$url] ?? null;
        if ($kept !== null) {
            $this->verified[(string) parse_url($url, PHP_URL_HOST)] = [$url, $kept];
        }
    }

    /**
     * The certificate at $url, fetched unless it failed a moment ago or the
     * bucket is empty.
     *
     * @return array{\OpenSSLAsymmetricKey, int, int}
     * @throws HttpException
     */
    private function fetchOnce(string $url, int $now, string $client): array
    {
        if (($this->failed[$url] ?? \PHP_INT_MIN) > $now) {
            throw Payload::unverified('The SNS signing certificate could not be fetched a moment ago.');
        }
        unset($this->failed[$url]);

        [$tokens, $filledAt] = $this->buckets[$client] ?? [(float) self::FETCH_BURST, $now];
        $tokens = min(self::FETCH_BURST, $tokens + max(0, $now - $filledAt) / self::FETCH_EVERY);
        unset($this->buckets[$client]);
        if (\count($this->buckets) >= self::BUCKETS) {
            array_shift($this->buckets);
        }
        $this->buckets[$client] = [$tokens, $now];
        if ($tokens < 1) {
            throw new HttpException(429, 'Too many SNS signing certificates fetched; try again shortly.', 'too_many_requests', [
                'retry-after' => (string) self::FETCH_EVERY,
            ]);
        }
        $this->buckets[$client] = [$tokens - 1, $now];

        try {
            return $this->fetch($url);
        } catch (HttpException $e) {
            if (\count($this->failed) >= self::FAILURES) {
                array_shift($this->failed);
            }
            $this->failed[$url] = $now + self::FAILURE_TTL;

            throw $e;
        }
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
