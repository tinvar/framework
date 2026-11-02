<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Tinvar\Routing\Domains;

/**
 * Serving the pages `tinvar build:static` wrote, from a front controller --
 * `php -S`, which has no file server of its own. The app's Caddyfile applies
 * the same rule before a request reaches PHP at all.
 *
 * Only to a request nothing per-visitor could have been rendered for: a
 * plain document GET or HEAD, with no query, from a visitor without the
 * application's session cookie. Everyone else goes to PHP:
 *
 * - a session cookie means a visitor PHP has met -- signed in perhaps, with a
 *   flash waiting, a CSRF token to put in their forms -- and a file is the
 *   same for everyone;
 * - `RSC: 1` is the client router asking for a payload, which a document is
 *   not;
 * - a query is something the build never rendered;
 * - another host than the primary one, where the application routes by host
 *   (ADR 0149): a page is built for the primary host, and the same path on
 *   another is another page, or none.
 *
 * Sent `no-cache` with `Vary: Cookie, RSC`: the same URL answers a visitor
 * with a cookie from PHP, privately, and a shared cache keying on the URL
 * alone must not hand one to the other.
 */
final class StaticFiles
{
    /** A path a built page could be at: the shape StaticBuilder allows its params. */
    private const string PATH = '#^/(?:[A-Za-z0-9._~-]+/)*(?:[A-Za-z0-9._~-]+)?\z#';

    /**
     * The built page for this request, or null when PHP must answer it.
     *
     * @param array<array-key, mixed> $server $_SERVER
     * @param array<array-key, mixed> $cookies $_COOKIE
     * @param Domains|null $domains the hosts the application serves: given, a page is sent on
     *        the primary host, or an alias of it, alone
     */
    public static function match(string $root, array $server, array $cookies, string $sessionCookie, ?Domains $domains = null): ?string
    {
        $method = $server['REQUEST_METHOD'] ?? 'GET';
        if (($method !== 'GET' && $method !== 'HEAD') || isset($server['HTTP_RSC']) || isset($cookies[$sessionCookie])) {
            return null;
        }
        if ($domains !== null && !self::onPrimaryHost($domains, $server)) {
            return null;
        }

        $uri = \is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        [$path, $query] = [...explode('?', $uri, 2), ''];
        if ($query !== '' || preg_match(self::PATH, $path) !== 1 || str_contains($path, '..')) {
            return null;
        }

        $base = realpath($root);
        if ($base === false) {
            return null;
        }

        $file = realpath($base . rtrim($path, '/') . '/index.html');

        return $file !== false && str_starts_with($file, $base . \DIRECTORY_SEPARATOR) && is_file($file) ? $file : null;
    }

    /**
     * Whether the request names the primary host, as Domains reads a host --
     * a pinned proxy's X-Forwarded-Host included.
     *
     * @param array<array-key, mixed> $server
     */
    private static function onPrimaryHost(Domains $domains, array $server): bool
    {
        // What TrustedProxies::host() reads: the proxy's proof with the host
        // it names, so a request is read here as the Kernel reads it.
        $headers = array_filter([
            'host' => $server['HTTP_HOST'] ?? null,
            'x-forwarded-host' => $server['HTTP_X_FORWARDED_HOST'] ?? null,
            TrustedProxies::SECRET_HEADER => $server['HTTP_X_TINVAR_PROXY'] ?? null,
            TrustedProxies::HOST_HEADER => $server['HTTP_X_TINVAR_HOST'] ?? null,
        ], \is_string(...));
        $address = $server['REMOTE_ADDR'] ?? null;
        $host = $domains->hostOf(new Request('GET', '/', $headers, remoteAddress: \is_string($address) ? $address : null));
        $match = $host === null ? null : $domains->match($host);

        return $match !== null && $match->pattern === null;
    }

    /** The response a built page is sent as. */
    public static function response(string $file): Response
    {
        $html = file_get_contents($file);
        if ($html === false) {
            throw new \RuntimeException("Cannot read the built page {$file}.");
        }

        return Response::make(200, $html, [
            'content-type' => 'text/html; charset=utf-8',
            'cache-control' => 'no-cache',
            'vary' => 'Cookie, RSC',
        ]);
    }
}
