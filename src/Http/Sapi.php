<?php

declare(strict_types=1);

namespace Tinvar\Http;

use Uri\Rfc3986\Uri;

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
     * The largest body Caddy is let hold whole for a client, in bytes: the
     * shop's catalog is about 100 KB as a document and 45 KB as a payload.
     */
    public const int BUFFERED_BYTES = 1_048_576;

    /**
     * The server variable a Caddyfile names the directory it sends stored
     * files from with (`php { env TINVAR_FILE_ROOT ... }`): one the request
     * cannot set, since a client's headers arrive as HTTP_*.
     */
    public const string FILE_ROOT = 'TINVAR_FILE_ROOT';

    /** Says which stored file the server is to send in the body's place. */
    public const string FILE_HEADER = 'x-accel-redirect';

    /** Says the server is to pass the body on as it comes, not hold it whole. */
    public const string STREAM_HEADER = 'x-accel-buffering';

    /**
     * Where a body too large to hold in memory is written for the server to
     * send as a file: a directory of the file root's own (ADR 0109).
     */
    public const string SPOOL_DIRECTORY = 'tinvar-spool';

    /** The largest body written there; past it, the worker passes it on itself. */
    public const int SPOOL_MAX_BYTES = 1_073_741_824;

    /**
     * How long a spooled body is kept: the server opens the file as soon as
     * the worker is done, and a file it has open is still sent once removed.
     */
    public const int SPOOL_KEPT_SECONDS = 600;

    /**
     * The largest raw body read for a request (ADR 0207): the most an
     * endpoint may be configured to take (Endpoints\Limits::
     * MAX_BODY_BYTES_CEILING), and well over a resumable chunk. A file
     * larger than this is a multipart upload, which PHP parses itself,
     * within post_max_size, before any of this runs.
     */
    public const int MAX_BODY_BYTES = 8 * 1_048_576;

    /**
     * The request's raw body, read from $source (php://input), never more
     * than MAX_BODY_BYTES of it (ADR 0207). A Content-Length over the
     * ceiling is refused before a byte is read; a body that runs past it --
     * chunked, or longer than it said -- is refused once it does, having
     * read one byte more than the ceiling and no further.
     *
     * php://input is opened first, whatever comes of it: PHP reads and
     * discards whatever of a body is left unread when a request ends --
     * holding the worker until the last byte comes or `read_body` cuts it
     * off -- unless the request's input was opened. Opened, nothing is
     * drained, and the server closes a connection whose body was refused.
     *
     * @param array<string, mixed> $server $_SERVER
     * @throws BodyTooLarge
     */
    public static function body(array $server, string $source = 'php://input'): string
    {
        $in = @fopen($source, 'rb');

        $length = $server['CONTENT_LENGTH'] ?? null;
        if (\is_string($length) && preg_match('/^\d+$/D', $length) === 1
            && (\strlen(ltrim($length, '0')) > 18 || (int) $length > self::MAX_BODY_BYTES)) {
            if ($in !== false) {
                fclose($in);
            }

            throw new BodyTooLarge();
        }

        if ($in === false) {
            return '';
        }
        try {
            $body = stream_get_contents($in, self::MAX_BODY_BYTES + 1);
        } finally {
            fclose($in);
        }
        if ($body === false) {
            return '';
        }
        if (\strlen($body) > self::MAX_BODY_BYTES) {
            throw new BodyTooLarge();
        }

        return $body;
    }

    /**
     * Builds a Request from SAPI values. Throws NotFoundException for a path
     * Request refuses; the caller answers that as a 404.
     *
     * @param array<string, mixed> $server  $_SERVER
     * @param array<mixed>         $query   $_GET
     * @param array<mixed>         $body    $_POST
     * @param array<mixed>         $cookies $_COOKIE
     * @param array<mixed>         $files   $_FILES; see UploadedFile::fromGlobals()
     * @param (\Closure(string): bool)|null $isUploaded is_uploaded_file() unless given
     */
    public static function request(
        array $server,
        array $query = [],
        array $body = [],
        array $cookies = [],
        array $files = [],
        ?\Closure $isUploaded = null,
    ): Request {
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
            \is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : null,
            UploadedFile::fromGlobals($files, $isUploaded),
        );
    }

    /** From the request target, which may be origin-form or absolute-form. */
    private static function path(mixed $uri): string
    {
        if (!\is_string($uri) || $uri === '') {
            return '/';
        }

        // Absolute-form, `GET http://host/path` -- what a request through a
        // proxy names -- read by PHP 8.5's URI extension, as the spec has
        // URLs parsed (RFC 3986, the grammar a request target is written
        // in), and its path kept raw: Request decodes each segment itself.
        if ($uri[0] !== '/') {
            $path = Uri::parse($uri)?->getRawPath() ?? '';

            return str_starts_with($path, '/') ? $path : '/';
        }

        return explode('?', $uri, 2)[0];
    }

    /**
     * HTTP_* entries, plus the two the CGI convention files without the
     * prefix. Names lowercased and dashed, as Request expects.
     *
     * @param array<array-key, mixed> $server numeric keys can occur
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
     * The response as the server is to take it.
     *
     * FrankenPHP's static binary has the `parallel` extension built in,
     * which takes one process-wide mutex around every write PHP makes to the
     * server. A write the client is not reading blocks, holding it, and every
     * other worker's output waits behind it (ADR 0051). So a worker must not
     * write to a client at all: the shop's Caddyfile holds a body whole
     * (`intercept`) and sends it once the worker is done -- in Go, outside
     * the mutex -- and the worker's writes only fill memory (ADR 0081).
     *
     * Held whole only up to BUFFERED_BYTES, known before the first byte: a
     * body that is a string, or a stream that says its Content-Length. A
     * larger one, or a stream of no known length -- a GET endpoint's
     * Response::stream() -- is spooled (ADR 0109): written whole to a file
     * of its own under the file root, which the server sends as it sends a
     * stored file, so the worker's writes go to disk and never to a client
     * that stopped reading. Only a 200 -- the file server answers with that
     * -- and never an event stream, which must arrive as it is written, nor
     * a body past SPOOL_MAX_BYTES. Those, and every large body where there
     * is no file root, are marked to be passed on as they come
     * (`X-Accel-Buffering: no`, the convention nginx reads too), and their
     * writes can still block.
     *
     * A file the server sends itself goes out with no body at all. When the
     * Caddyfile names a directory it sends stored files from ($fileRoot) and
     * the response's file is inside it, the response carries the file's path
     * below that directory (`X-Accel-Redirect`) and Caddy's file server sends
     * it. Neither header reaches the client: the Caddyfile removes both. An
     * application's own value for either is dropped here first, so the only
     * file the server is ever told to send is one a response was built from.
     *
     * @param int $spoolMaxBytes the most a spooled body may be: SPOOL_MAX_BYTES, unless a
     *        disk that holds less, or a test, says otherwise
     */
    public static function forServer(Response $response, ?string $fileRoot, int $spoolMaxBytes = self::SPOOL_MAX_BYTES): Response
    {
        $response = $response->withoutHeader(self::FILE_HEADER)->withoutHeader(self::STREAM_HEADER);

        $file = $response->file();
        $relative = $file === null || $fileRoot === null || $fileRoot === '' ? null : self::below($fileRoot, $file);
        if ($relative !== null) {
            return Response::make($response->status, '', $response->headers())
                ->withCookiesOf($response)
                ->withHeader(self::FILE_HEADER, $relative);
        }

        $length = $response->isStreaming() ? $response->header('content-length') : (string) \strlen($response->body());
        $known = $length !== null && preg_match('/^\d{1,15}\z/', $length) === 1 ? (int) $length : null;
        if ($known !== null && $known <= self::BUFFERED_BYTES) {
            return $response;
        }

        $spooled = $fileRoot === null || $fileRoot === '' ? null : self::spool($response, $fileRoot, $known, $spoolMaxBytes);

        return $spooled ?? $response->withHeader(self::STREAM_HEADER, 'no');
    }

    /**
     * $response with its body written to a file of its own under the
     * spool directory, for the server to send, or null when it is not one
     * to spool or the directory cannot take it -- and then its body has not
     * been read.
     *
     * The name is 128 random bits, the file 0600 in a 0700 directory, and
     * only this response names it to the server; a body that fails to be
     * written whole is not sent at all, rather than sent cut short. Files
     * older than SPOOL_KEPT_SECONDS go as the next one is written.
     */
    private static function spool(Response $response, string $fileRoot, ?int $length, int $max): ?Response
    {
        $type = strtolower((string) $response->header('content-type'));
        if ($response->status !== 200 || str_starts_with($type, 'text/event-stream')
            || ($length !== null && $length > $max)) {
            return null;
        }

        $root = realpath($fileRoot);
        if ($root === false || !is_dir($root)) {
            return null;
        }
        $directory = $root . '/' . self::SPOOL_DIRECTORY;
        if (!is_dir($directory) && !@mkdir($directory, 0o700) && !is_dir($directory)) {
            return null;
        }
        // Its own directory, never one a link put somewhere else.
        if (is_link($directory) || realpath($directory) !== $directory) {
            return null;
        }
        self::sweep($directory);

        $name = bin2hex(random_bytes(16));
        $path = "{$directory}/{$name}";
        $file = @fopen($path, 'xb');
        if ($file === false) {
            return null;
        }
        chmod($path, 0o600);

        // Once a write fails the body is not sent at all, so the sink stops
        // the producer there rather than let it render the rest into
        // nothing: it throws, every time it is called after, and the throw
        // is told apart from the producer's own by being this one.
        $written = 0;
        $failed = false;
        $stop = new \RuntimeException('The response body could not be spooled whole.');
        try {
            $response->sendBody(static function (string $chunk) use ($file, $max, $stop, &$written, &$failed): void {
                if ($failed) {
                    throw $stop;
                }
                $written += \strlen($chunk);
                if ($written > $max || fwrite($file, $chunk) !== \strlen($chunk)) {
                    $failed = true;

                    throw $stop;
                }
            });
        } catch (\Throwable $e) {
            if (!$failed) {
                fclose($file);
                @unlink($path);

                throw $e;
            }
        }
        fclose($file);

        if ($failed) {
            @unlink($path);
            error_log("tinvar: a response body could not be spooled whole ({$written} bytes, at most {$max}); answered 500.");

            return Response::make(500, 'The response could not be sent.', ['content-type' => 'text/plain; charset=utf-8', 'cache-control' => 'no-store']);
        }

        return Response::make($response->status, '', $response->headers())
            ->withCookiesOf($response)
            ->withoutHeader('content-length')
            ->withHeader(self::FILE_HEADER, '/' . self::SPOOL_DIRECTORY . "/{$name}");
    }

    /** Removes what was spooled more than SPOOL_KEPT_SECONDS ago: at most once a minute a process, for each directory. */
    private static function sweep(string $directory): void
    {
        /** @var array<string, int> $last */
        static $last = [];
        $now = time();
        if ($now - ($last[$directory] ?? 0) < 60) {
            return;
        }
        $last[$directory] = $now;

        foreach (glob("{$directory}/*") ?: [] as $file) {
            $modified = @filemtime($file);
            if (preg_match('#/[0-9a-f]{32}\z#', $file) === 1 && $modified !== false && $now - $modified > self::SPOOL_KEPT_SECONDS) {
                @unlink($file);
            }
        }
    }

    /**
     * $file's path below $root, as a URL path, when it is a regular file
     * inside it, both resolved; null otherwise. Only names Caddy's rewrite
     * takes as they are: no `.` or `..` segment, nothing that needs escaping.
     */
    private static function below(string $root, string $file): ?string
    {
        $root = realpath($root);
        $real = realpath($file);
        if ($root === false || $real === false || !is_file($real) || !str_starts_with($real, $root . '/')) {
            return null;
        }

        $relative = substr($real, \strlen($root));
        if (preg_match('#^(?:/[A-Za-z0-9_-][A-Za-z0-9._-]*)+\z#', $relative) !== 1) {
            return null;
        }

        return $relative;
    }

    /**
     * Writes a Response through the given output functions: each header,
     * each cookie as a Set-Cookie line of its own, the status, then the body
     * as it streams.
     *
     * @param \Closure(int): void            $status
     * @param \Closure(string, string): void $header name, value
     * @param \Closure(string): void         $write  a chunk of body
     */
    public static function emit(Response $response, \Closure $status, \Closure $header, \Closure $write): void
    {
        foreach ($response->headers() as $name => $value) {
            $header($name, $value);
        }
        foreach ($response->cookies() as $line) {
            $header('set-cookie', $line);
        }
        // After the headers: PHP rewrites the status for some of them -- a
        // 401 for WWW-Authenticate, a 302 for Location -- so a 403 naming
        // the scope a token lacks (ADR 0159) left as a 401. Set last, it is
        // the response's own.
        $status($response->status);
        $response->sendBody($write);
    }

    /**
     * Sends a 103 Early Hints response carrying $link, ahead of the response
     * itself, where the server can: FrankenPHP's `headers_send()`. The
     * browser starts on the stylesheets and modules it names while the page
     * renders. Elsewhere -- `php -S`, the CLI -- nothing is sent.
     *
     * The header is set for the hint alone and removed after, so the
     * response that follows carries only the Link header it sets itself.
     * Nothing a worker writes waits on the client here: the hint is a few
     * hundred bytes of headers, written by the server, not through PHP's
     * output (ADR 0108).
     *
     * @param (\Closure(string): bool)|null $send sends the hint with the
     *        given Link value; FrankenPHP's, by default
     */
    public static function earlyHints(string $link, ?\Closure $send = null): bool
    {
        if ($link === '' || preg_match('/[\r\n\0]/', $link) === 1) {
            return false;
        }

        return ($send ?? self::sendEarlyHints(...))($link);
    }

    private static function sendEarlyHints(string $link): bool
    {
        if (!\function_exists('headers_send') || headers_sent()) {
            return false;
        }
        // The hint carries the Link header and nothing else PHP holds: a
        // header set before it -- PHP's own X-Powered-By -- would go out
        // ahead of the response, past anything the server strips from it.
        header_remove();
        header("link: {$link}");
        headers_send(103);
        header_remove('link');

        return true;
    }

    /**
     * Writes a Response to the real SAPI output, flushing each chunk so it
     * streams -- as the server is to take it (forServer()), told by this
     * request's server variables whether it sends stored files itself.
     *
     * @param (\Closure(): void)|null $writing told once, as the first byte of
     *        the body is written: until then nothing has left, and a failure
     *        can still be answered with another status (Worker)
     */
    public static function send(Response $response, ?\Closure $writing = null): void
    {
        $root = $_SERVER[self::FILE_ROOT] ?? null;

        self::emit(
            self::forServer($response, \is_string($root) ? $root : null),
            static function (int $code): void {
                http_response_code($code);
            },
            static function (string $name, string $value): void {
                // Every other header replaces; Set-Cookie adds, or a second
                // cookie would silently take the first one's place.
                header("{$name}: {$value}", $name !== 'set-cookie');
            },
            static function (string $chunk) use (&$writing): void {
                if ($writing !== null && $chunk !== '') {
                    [$told, $writing] = [$writing, null];
                    $told();
                }
                echo $chunk;
                flush();
            },
        );
    }
}
