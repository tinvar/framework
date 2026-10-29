<?php

declare(strict_types=1);

namespace Tinvar\Http;

/**
 * The build's client assets, served by PHP where nothing in front of it
 * serves them -- `php -S`, or a server with no Caddy -- and the CDN origin
 * the build was made for, where it was made for one (ADR 0097).
 *
 * tinvar-spec.md, "Deployment": "Static assets from Vite go to a CDN or are
 * served by PHP with long cache headers." A build for a CDN
 * (TINVAR_ASSET_ORIGIN, `assetOrigin()` from @tinvar/renderer/vite) writes
 * every asset URL on that origin and records it in `rsc/assets.json`;
 * origin() reads it back, for the page's Content-Security-Policy. The
 * files are the same either way, and the CDN fetches them from here.
 *
 * Only what the build wrote under `client/assets/`, by a name a build
 * writes: a name, a dash, a hash of eight or more characters, and an
 * extension of the types below -- one segment, no directory, no dot to
 * start it, nothing to decode. The path is matched as it arrived, before
 * any decoding, and the file is found by that one name in that one
 * directory and its real path held inside it; a link out of it is not
 * followed. Anything else under `/assets/` is a 404 here, never a page.
 *
 * Sent as Caddy's file server sends them: a year, immutable -- a name is
 * a hash of its contents, so a file at a name never changes -- with its
 * type from the extension, `nosniff`, and readable from any origin
 * (`Access-Control-Allow-Origin: *`), which a module script or a font
 * fetched from a CDN in front of this server needs and which these public
 * files can grant. No cookie is read and no session started.
 */
final readonly class BuiltAssets
{
    /** Where they are served from: Vite's `assetsDir`. */
    public const string PATH = '/assets/';

    public const string CACHE_CONTROL = 'public, max-age=31536000, immutable';

    /** The record of the asset origin, in the rsc output. */
    public const string RECORD = 'assets.json';

    /** Each extension served, and its type. */
    public const array TYPES = [
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'wasm' => 'application/wasm',
    ];

    /** A name the build writes: a name, a dash, a hash, an extension. */
    private const string NAME = '/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}-[A-Za-z0-9_-]{8,32}\.([a-z0-9]{2,5})\z/';

    /** Read in chunks this size. */
    private const int CHUNK = 65_536;

    /** @param string $build the build's directory: `dist/`, holding client/ and rsc/ */
    public function __construct(private string $build) {}

    /** Whether $path is one this serves, or answers 404 for. */
    public static function matches(string $path): bool
    {
        return str_starts_with($path, self::PATH);
    }

    /**
     * The response for a request from a front controller, or null when it
     * is not for an asset.
     *
     * @param array<array-key, mixed> $server $_SERVER
     */
    public function match(array $server): ?Response
    {
        $uri = \is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $path = explode('?', $uri, 2)[0];
        if (!self::matches($path)) {
            return null;
        }
        $method = \is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET';

        return $this->respond($method, $path);
    }

    /** The response for $method $path, a path under PATH as it arrived. */
    public function respond(string $method, string $path): Response
    {
        $notFound = Response::make(404, 'Not found', ['content-type' => 'text/plain; charset=utf-8', 'cache-control' => 'no-store', 'x-content-type-options' => 'nosniff']);
        $name = substr($path, \strlen(self::PATH));
        if (preg_match(self::NAME, $name, $m) !== 1 || !isset(self::TYPES[$m[1]])) {
            return $notFound;
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            return Response::make(405, '', ['allow' => 'GET, HEAD', 'cache-control' => 'no-store']);
        }

        $directory = realpath($this->build . '/client/assets');
        $file = $directory === false ? false : realpath("{$directory}/{$name}");
        if ($directory === false || $file === false || \dirname($file) !== $directory || basename($file) !== $name || !is_file($file)) {
            return $notFound;
        }
        $size = filesize($file);
        if ($size === false) {
            return $notFound;
        }

        $headers = [
            'content-type' => self::TYPES[$m[1]],
            'content-length' => (string) $size,
            'cache-control' => self::CACHE_CONTROL,
            'x-content-type-options' => 'nosniff',
            'access-control-allow-origin' => '*',
            'cross-origin-resource-policy' => 'cross-origin',
        ];
        if ($m[1] === 'svg') {
            // Opened on its own, an SVG is a document: nothing in it runs.
            $headers['content-security-policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }
        if ($method === 'HEAD') {
            return Response::make(200, '', $headers);
        }

        return Response::stream(static function (\Closure $sink) use ($file): void {
            $stream = fopen($file, 'rb');
            if ($stream === false) {
                return;
            }
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, self::CHUNK);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $sink($chunk);
                }
            } finally {
                fclose($stream);
            }
        }, 200, $headers)->withFile($file);
    }

    /**
     * The CDN origin the build was made for, or null for one served from
     * this origin: what `rsc/assets.json` records, held to an https origin
     * and nothing after it. A record that says anything else is refused,
     * rather than named in a policy.
     *
     * @throws \RuntimeException for a record that is not one
     */
    public function origin(): ?string
    {
        $record = $this->build . '/rsc/' . self::RECORD;
        if (!is_file($record)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($record), true);
        $origin = \is_array($data) ? ($data['origin'] ?? null) : false;
        if ($origin === null) {
            return null;
        }
        if (!\is_string($origin) || preg_match('#^https://[a-z0-9.-]+(:[1-9][0-9]{0,4})?\z#', $origin) !== 1) {
            throw new \RuntimeException("The build's asset record ({$record}) names no https origin; build again.");
        }

        return $origin;
    }
}
