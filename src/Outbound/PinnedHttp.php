<?php

declare(strict_types=1);

namespace Tinvar\Outbound;

use Tinvar\Attributes\OutboundPolicy;

/**
 * The OutboundHttp that sends: curl, held to tinvar-spec.md's "Outbound
 * requests".
 *
 * "It resolves the host once and connects to that pinned address." The
 * name is looked up here, every address it answers with is vetted against
 * the policy (OutboundPolicy::vet), and curl is handed the one to connect
 * to (CURLOPT_RESOLVE) rather than the name -- so a DNS server answering
 * a public address to the check and a private one to the connection has
 * nothing to answer the second time. And then checked again: just before
 * curl sends the request, the address it is connected to must be that one
 * (CURLOPT_PREREQFUNCTION), so a host curl spelled differently from the
 * parser, and looked up for itself, fails closed instead of connecting.
 *
 * Proxies are off whatever the environment says (CURLOPT_PROXY ''): a
 * proxy would make the connection, and the pinning would pin nothing.
 * http and https only, TLS verified, no cookies, no decompression, no
 * connection kept for the next request.
 *
 * "Redirects are not followed": a 3xx comes back as the response. A GET
 * or HEAD may ask for up to OutboundRequest::MAX_REDIRECTS to be followed;
 * each hop is a new destination -- resolved, vetted and pinned again --
 * never from https to http, and a hop to another origin drops every header
 * the caller set. Other methods are never followed: a body sent to one
 * origin is not sent on to another.
 *
 * Bounded: the whole request, hops and name lookups included, within its
 * timeout; the connection within the connect timeout; the body within its
 * cap and the headers within 64 KB, the transfer stopped as soon as either
 * is passed. Each lookup is given what is left of the timeout, and an
 * answer that comes after it is not used: the request fails as a timeout,
 * connected to nothing. A resolver that can stop at the deadline does;
 * getaddrinfo() cannot be stopped, so SystemResolver's late answer is
 * thrown away when it comes.
 */
final readonly class PinnedHttp implements OutboundHttp
{
    public const string USER_AGENT = 'Tinvar-OutboundHttp/1';

    /** Seconds a request may take, its lookups included, unless the client or the request says otherwise. */
    public const float DEFAULT_TIMEOUT = 10.0;

    private const int MAX_HEADER_BYTES = 65_536;

    /** curl's TLS failures: handshake, certificate, verification, pinning. */
    private const array TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91, 98];

    /**
     * @param float $timeout seconds a request may take, its lookups included, unless it says otherwise
     * @param float $connectTimeout seconds a connection may take to open
     * @param int $maxResponseBytes the largest body read unless a request says otherwise
     */
    public function __construct(
        private OutboundPolicy $policy = new OutboundPolicy(),
        private Resolver $resolver = new SystemResolver(),
        private float $timeout = self::DEFAULT_TIMEOUT,
        private float $connectTimeout = 5.0,
        private int $maxResponseBytes = 1_048_576,
    ) {
        if ($timeout <= 0 || $connectTimeout <= 0 || $maxResponseBytes < 0) {
            throw new \InvalidArgumentException('Timeouts are positive, and the response cap is not negative.');
        }
    }

    public function send(OutboundRequest $request): OutboundResponse
    {
        if (!\function_exists('curl_init')) {
            throw new \LogicException('PinnedHttp sends with curl, and the curl extension is not loaded.');
        }

        $started = self::clock();
        $deadline = $started + (int) (($request->timeout ?? $this->timeout) * 1e9);
        $maxBytes = $request->maxResponseBytes ?? $this->maxResponseBytes;
        $target = Target::parse($request->url);
        $headers = $request->headers;

        for ($hops = 0; ; $hops++) {
            $address = $this->vet($request, $target, $started, $deadline);
            $response = $this->transfer($request, $target, $address, $headers, $maxBytes, $started, $deadline);

            $follows = $request->maxRedirects > 0 && ($request->method === 'GET' || $request->method === 'HEAD');
            if (!$follows || !$response->redirect()) {
                return $response;
            }
            if ($hops >= $request->maxRedirects) {
                throw new OutboundFailed(
                    OutboundFailed::TOO_MANY_REDIRECTS,
                    "{$request->method} {$target->origin()} was redirected more than {$request->maxRedirects} times.",
                    $response->status,
                    $response->milliseconds,
                );
            }

            $next = Target::parse((string) $response->header('location'), $target);
            if ($target->scheme === 'https' && $next->scheme !== 'https') {
                throw new OutboundRefused('downgrade', "{$target->origin()} redirected to plain http.");
            }
            if ($next->origin() !== $target->origin()) {
                $headers = [];
            }
            $target = $next;
        }
    }

    /**
     * The address $target may be connected to, its name looked up within
     * what is left of the request's time. The resolver is told how long
     * that is; an answer that comes after it -- from a resolver that could
     * not stop, or did not -- is not used.
     */
    private function vet(OutboundRequest $request, Target $target, int $started, int $deadline): string
    {
        $origin = "{$request->method} {$target->origin()}";
        $remaining = $deadline - self::clock();
        if ($remaining <= 0) {
            throw new OutboundFailed(OutboundFailed::TIMEOUT, "{$origin} ran out of time.", null, self::since($started));
        }

        $address = $this->policy->vet($target, $this->resolver, $remaining / 1e9);
        if (self::clock() >= $deadline) {
            throw new OutboundFailed(OutboundFailed::TIMEOUT, "{$origin} ran out of time looking up its name.", null, self::since($started));
        }

        return $address;
    }

    /**
     * One request to one vetted address.
     *
     * @param array<string, string> $headers
     */
    private function transfer(
        OutboundRequest $request,
        Target $target,
        string $address,
        #[\SensitiveParameter]
        array $headers,
        int $maxBytes,
        int $started,
        int $deadline,
    ): OutboundResponse {
        $method = $request->method;
        $remaining = intdiv($deadline - self::clock(), 1_000_000);
        if ($remaining <= 0) {
            throw new OutboundFailed(OutboundFailed::TIMEOUT, "{$method} {$target->origin()} ran out of time.", null, self::since($started));
        }

        $status = 0;
        $responseHeaders = [];
        $headerBytes = 0;
        $received = '';
        $overLimit = false;
        $stoppedAtBody = false;
        $connectedTo = null;
        $expected = (string) inet_pton($address);

        // A header given an empty value is written `Name;`: `Name:` is how
        // curl is told to send no such header at all.
        $lines = ['Expect:'];
        if (!isset($headers['user-agent'])) {
            $lines[] = 'User-Agent: ' . self::USER_AGENT;
        }
        foreach ($headers as $name => $value) {
            $lines[] = $value === '' ? "{$name};" : "{$name}: {$value}";
        }

        $options = [
            CURLOPT_URL => $target->serialized(),
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'http,https',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, min((int) ($this->connectTimeout * 1000), $remaining)),
            CURLOPT_TIMEOUT_MS => $remaining,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_PREREQFUNCTION => static function (\CurlHandle $handle, string $ip) use ($expected, &$connectedTo): int {
                if (@inet_pton($ip) !== $expected) {
                    $connectedTo = $ip;

                    return CURL_PREREQFUNC_ABORT;
                }

                return CURL_PREREQFUNC_OK;
            },
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$status, &$responseHeaders, &$headerBytes, &$overLimit): int {
                $headerBytes += \strlen($line);
                if ($headerBytes > self::MAX_HEADER_BYTES) {
                    $overLimit = true;

                    return 0;
                }
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                    // A new status line -- after a 100 Continue, say -- starts
                    // the headers over.
                    $status = (int) $m[1];
                    $responseHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))][] = trim($value);
                }

                return \strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use ($request, $maxBytes, &$received, &$overLimit, &$stoppedAtBody): int {
                if (!$request->readBody) {
                    $stoppedAtBody = true;

                    return 0;
                }
                if (\strlen($received) + \strlen($chunk) > $maxBytes) {
                    $overLimit = true;

                    return 0;
                }
                $received .= $chunk;

                return \strlen($chunk);
            },
        ];
        if ($maxBytes > 0 && $request->readBody) {
            // A Content-Length over the cap is refused before any of the
            // body is read.
            $options[CURLOPT_MAXFILESIZE_LARGE] = $maxBytes;
        }
        if ($target->literal === null) {
            $options[CURLOPT_RESOLVE] = ["{$target->host}:{$target->port}:" . (str_contains($address, ':') ? "[{$address}]" : $address)];
        }
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($request->body !== '' || \in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                $options[CURLOPT_POSTFIELDS] = $request->body;
            }
        }

        $handle = curl_init();
        if (!curl_setopt_array($handle, $options)) {
            throw new OutboundFailed(OutboundFailed::FAILED, 'curl refused a transfer option; it may be older than PinnedHttp needs (7.85).');
        }
        curl_exec($handle);
        $errno = curl_errno($handle);
        $milliseconds = self::since($started);
        $origin = "{$method} {$target->origin()}";

        if ($connectedTo !== null) {
            throw new OutboundFailed(OutboundFailed::ADDRESS_CHANGED, "{$origin} was connected to an address other than the one vetted, and was stopped before it was sent.", null, $milliseconds);
        }
        if ($overLimit || $errno === CURLE_FILESIZE_EXCEEDED) {
            throw new OutboundFailed(OutboundFailed::TOO_LARGE, "{$origin} answered with more than the response cap.", $status === 0 ? null : $status, $milliseconds);
        }
        if ($errno !== 0 && !($stoppedAtBody && $errno === CURLE_WRITE_ERROR)) {
            throw new OutboundFailed(self::reason($errno), "{$origin} failed: " . curl_strerror($errno) . '.', $status === 0 ? null : $status, $milliseconds);
        }
        if ($status === 0) {
            throw new OutboundFailed(OutboundFailed::FAILED, "{$origin} answered with no status line.", null, $milliseconds);
        }

        return new OutboundResponse($status, $responseHeaders, $received, $milliseconds, $address);
    }

    private static function reason(int $errno): string
    {
        return match (true) {
            $errno === CURLE_OPERATION_TIMEDOUT => OutboundFailed::TIMEOUT,
            $errno === CURLE_COULDNT_CONNECT => OutboundFailed::CONNECT,
            $errno === CURLE_COULDNT_RESOLVE_HOST => OutboundFailed::DNS,
            \in_array($errno, self::TLS_ERRORS, true) => OutboundFailed::TLS,
            default => OutboundFailed::FAILED,
        };
    }

    /** Nanoseconds on the monotonic clock: a wall clock moving does not move a deadline. */
    private static function clock(): int
    {
        return (int) hrtime(true);
    }

    private static function since(int $started): int
    {
        return (int) round((self::clock() - $started) / 1e6);
    }
}
