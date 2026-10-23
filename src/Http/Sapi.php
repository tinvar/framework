<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * The edge between PHP's request globals and the framework's Request and
 * Response.
 *
 * tinvar-spec.md: "PHP front on FrankenPHP or Octane workers". Both hand a
 * worker the request as SAPI globals and take the response through
 * header() and output, so this is the one adapter they share. It is pure in
 * both directions -- the globals and the output functions are arguments --
 * so it can be tested without a server, and a worker loop calls it once
 * per request rather than reading globals anywhere else.
 */
final class Sapi
{
    /**
     * Builds a Request from SAPI values. Throws NotFoundException for a path
     * Request refuses; the caller answers that as a 404.
     *
     * @param array<string, mixed> $server  $_SERVER
     * @param array<mixed>         $query   $_GET
     * @param array<mixed>         $body    $_POST
     * @param array<mixed>         $cookies $_COOKIE
     */
    public static function request(array $server, array $query = [], array $body = [], array $cookies = []): Request
    {
        $method = \is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET';
        $rawQuery = \is_string($server['QUERY_STRING'] ?? null) ? $server['QUERY_STRING'] : null;

        return new Request(
            $method,
            self::path($server['REQUEST_URI'] ?? '/'),
            self::headers($server),
            self::query($query),
            $body,
            array_filter($cookies, static fn (mixed $v, mixed $k): bool => \is_string($k) && \is_string($v), ARRAY_FILTER_USE_BOTH),
            $rawQuery,
        );
    }

    /** From the request target, which may be origin-form or absolute-form. */
    private static function path(mixed $uri): string
    {
        if (!\is_string($uri) || $uri === '') {
            return '/';
        }

        if ($uri[0] !== '/') {
            $path = parse_url($uri, PHP_URL_PATH);

            return \is_string($path) && $path !== '' ? $path : '/';
        }

        return explode('?', $uri, 2)[0];
    }

    /**
     * HTTP_* entries, plus the two the CGI convention files without the
     * prefix. Names lowercased and dashed, as Request expects.
     *
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function headers(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!\is_string($key) || !\is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }

        return $headers;
    }

    /**
     * The parsed query, held to the shape Request promises: a string, or a
     * list of strings. PHP parses `a[b][c]=1` into nested arrays, which no
     * part of the framework reads and every consumer would otherwise have
     * to guard against.
     *
     * @param array<mixed> $query
     * @return array<string, string|list<string>>
     */
    private static function query(array $query): array
    {
        $clean = [];
        foreach ($query as $key => $value) {
            $key = (string) $key;
            if (\is_string($value)) {
                $clean[$key] = $value;
            } elseif (\is_array($value) && array_is_list($value) && array_filter($value, 'is_string') === $value) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Writes a Response through the given output functions: a status, each
     * header, then the body as it streams.
     *
     * @param \Closure(int): void            $status
     * @param \Closure(string, string): void $header name, value
     * @param \Closure(string): void         $write  a chunk of body
     */
    public static function emit(Response $response, \Closure $status, \Closure $header, \Closure $write): void
    {
        $status($response->status);
        foreach ($response->headers() as $name => $value) {
            $header($name, $value);
        }
        $response->sendBody($write);
    }

    /** Writes a Response to the real SAPI output, flushing each chunk so it streams. */
    public static function send(Response $response): void
    {
        self::emit(
            $response,
            static function (int $code): void {
                http_response_code($code);
            },
            static function (string $name, string $value): void {
                header("{$name}: {$value}", true);
            },
            static function (string $chunk): void {
                echo $chunk;
                flush();
            },
        );
    }
}
