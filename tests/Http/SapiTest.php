<?php

declare(strict_types=1);

/**
 * Nothing turned SAPI globals into a Request, so no application could be
 * served at all -- and Request::$rawQuery, which the version check echoes,
 * had nothing to fill it.
 */

use Tinvar\Http\NotFoundException;
use Tinvar\Http\Response;
use Tinvar\Http\Sapi;

function server(array $extra = []): array
{
    return [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/racks/r-1?tag=a&tag=b',
        'QUERY_STRING' => 'tag=a&tag=b',
        'HTTP_RSC' => '1',
        'HTTP_X_VERSION' => 'b3',
        'CONTENT_TYPE' => 'application/json',
        'SERVER_NAME' => 'not-a-header',
        ...$extra,
    ];
}

describe('a request from globals', function () {
    it('takes method, path, headers and the raw query', function () {
        $request = Sapi::request(server(), ['tag' => 'b']);

        expect($request->method)->toBe('GET')
            ->and($request->path)->toBe('/racks/r-1')
            ->and($request->header('rsc'))->toBe('1')
            ->and($request->header('x-version'))->toBe('b3')
            ->and($request->header('content-type'))->toBe('application/json')
            ->and($request->header('server-name'))->toBeNull()
            ->and($request->rawQuery)->toBe('tag=a&tag=b')
            ->and($request->query)->toBe(['tag' => 'b']);
    });

    // The peer address comes from the server. X-Forwarded-For is a header
    // the client chose; dropping REMOTE_ADDR left only that to key on.
    it('carries the peer address from the server, not from a header', function () {
        $request = Sapi::request(server(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '10.0.0.1']));

        expect($request->remoteAddress)->toBe('203.0.113.7')
            ->and($request->header('x-forwarded-for'))->toBe('10.0.0.1');
    });

    it('reads the path of an absolute-form target', function () {
        expect(Sapi::request(server(['REQUEST_URI' => 'http://app.example/racks?x=1']))->path)->toBe('/racks');
    });

    // By the URI extension's RFC 3986 parser, its path kept raw for Request
    // to decode and hold to its rules.
    it('reads an absolute-form target by RFC 3986', function (string $target, string $path) {
        expect(Sapi::request(server(['REQUEST_URI' => $target]))->path)->toBe($path);
    })->with([
        'with userinfo and a port' => ['http://user:pw@app.example:8080/racks/r1?x=1#f', '/racks/r1'],
        'with no path' => ['http://app.example', '/'],
        'with no path but a query' => ['http://app.example?x=1', '/'],
        'with an encoded segment, kept as sent' => ['http://app.example/files/caf%C3%A9', '/files/caf%C3%A9'],
    ]);

    // The guards live in Request; the adapter must not route around them.
    it('refuses a path Request refuses', function () {
        expect(fn () => Sapi::request(server(['REQUEST_URI' => '/racks/%2e%2e/admin'])))
            ->toThrow(NotFoundException::class);
    });

    it('keeps the query to strings and lists of strings', function () {
        $request = Sapi::request(server(), ['a' => 'x', 'b' => ['1', '2'], 'c' => ['k' => ['deep']], 'd' => ['k' => 'v']]);

        expect($request->query)->toBe(['a' => 'x', 'b' => ['1', '2']]);
    });

    it('keeps only string cookies', function () {
        $request = Sapi::request(server(), cookies: ['session' => 'abc', 'odd' => ['x']]);

        expect($request->cookies)->toBe(['session' => 'abc']);
    });
});

describe('emitting a response', function () {
    it('writes every header, then the status, then the body as it streams', function () {
        $log = [];
        $response = Response::stream(static function (Closure $sink): void {
            $sink('<p>one</p>');
            $sink('<p>two</p>');
        }, 200, ['content-type' => 'text/html']);

        Sapi::emit(
            $response,
            static function (int $s) use (&$log): void {
                $log[] = "status {$s}";
            },
            static function (string $n, string $v) use (&$log): void {
                $log[] = "{$n}: {$v}";
            },
            static function (string $c) use (&$log): void {
                $log[] = $c;
            },
        );

        expect($log)->toBe(['content-type: text/html', 'status 200', '<p>one</p>', '<p>two</p>']);
    });
});

// PHP rewrites the status for WWW-Authenticate (to 401) and Location (to
// 302): set after them, a 403 naming a missing scope stays a 403 (ADR 0159).
it('sets the status after a header PHP would rewrite it for', function () {
    $log = [];
    Sapi::emit(
        Response::make(403, '', ['www-authenticate' => 'Bearer error="insufficient_scope"']),
        static function (int $s) use (&$log): void {
            $log[] = $s;
        },
        static function (string $n, string $v) use (&$log): void {
            $log[] = $n;
        },
        static function (string $c): void {},
    );

    expect($log)->toBe(['www-authenticate', 403]);
});

describe('files', function () {
    it('carries the files PHP received in this request, each under its plain name', function () {
        $received = '/tmp/php-received';
        $request = Sapi::request(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/_upload'],
            files: [
                'file' => ['name' => '../me.png', 'type' => 'image/png', 'tmp_name' => $received, 'error' => UPLOAD_ERR_OK, 'size' => 10],
                'big' => ['name' => 'big.png', 'type' => 'image/png', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0],
                // A path PHP did not write in this request.
                'planted' => ['name' => 'x', 'type' => 'x', 'tmp_name' => '/etc/passwd', 'error' => UPLOAD_ERR_OK, 'size' => 10],
                // file[] and file[a] nest; nothing reads them.
                'many' => ['name' => ['a'], 'type' => ['x'], 'tmp_name' => [$received], 'error' => [0], 'size' => [1]],
            ],
            isUploaded: static fn (string $path): bool => $path === $received,
        );

        expect(array_keys($request->files))->toBe(['file', 'big'])
            ->and($request->files['file']->path)->toBe($received)
            ->and($request->files['file']->clientName)->toBe('../me.png')
            ->and($request->files['file']->isOk())->toBeTrue()
            ->and($request->files['big']->error)->toBe(UPLOAD_ERR_INI_SIZE)
            ->and($request->files['big']->isOk())->toBeFalse();
    });

    it('trusts no path PHP did not receive, by default', function () {
        $request = Sapi::request(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/_upload'],
            files: ['file' => ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK, 'size' => 1]],
        );

        expect($request->files)->toBe([]);
    });
});

// ADR 0053: no worker writes to a client. The shop's Caddyfile holds a body
// whole and sends it after the worker is done, unless the body is marked to
// pass on as it comes; a stored file goes out by the file server, named by a
// header the Caddyfile removes.
describe('a response as the server takes it', function () {
    it('leaves a body Caddy may hold whole unmarked', function () {
        $response = Sapi::forServer(Response::make(200, str_repeat('x', Sapi::BUFFERED_BYTES)), null);

        expect($response->header(Sapi::STREAM_HEADER))->toBeNull()
            ->and($response->header(Sapi::FILE_HEADER))->toBeNull()
            ->and($response->body())->toBe(str_repeat('x', Sapi::BUFFERED_BYTES));
    });

    it('marks a body larger than that to be passed on as it comes', function () {
        $response = Sapi::forServer(Response::make(200, str_repeat('x', Sapi::BUFFERED_BYTES + 1)), null);

        expect($response->header(Sapi::STREAM_HEADER))->toBe('no');
    });

    it('holds a stream whole only when it says a length that fits', function () {
        $stream = static fn (array $headers): Response => Response::stream(static function (Closure $sink): void {
            $sink('chunk');
        }, 200, $headers);

        expect(Sapi::forServer($stream([]), null)->header(Sapi::STREAM_HEADER))->toBe('no')
            ->and(Sapi::forServer($stream(['content-length' => '5']), null)->header(Sapi::STREAM_HEADER))->toBeNull()
            ->and(Sapi::forServer($stream(['content-length' => (string) (Sapi::BUFFERED_BYTES + 1)]), null)->header(Sapi::STREAM_HEADER))->toBe('no')
            ->and(Sapi::forServer($stream(['content-length' => '5, 5']), null)->header(Sapi::STREAM_HEADER))->toBe('no');
    });

    it('hands a file inside the root the server names to the server, with no body and every header and cookie', function () {
        $root = sys_get_temp_dir() . '/tinvar-sapi-files-' . bin2hex(random_bytes(4));
        mkdir("{$root}/receipts", 0o700, true);
        file_put_contents("{$root}/receipts/a1.png", 'bytes');

        try {
            $sent = false;
            $response = Response::stream(static function (Closure $sink) use (&$sent): void {
                $sent = true;
                $sink('bytes');
            }, 200, ['content-type' => 'image/png', 'content-length' => '5', 'x-content-type-options' => 'nosniff'])
                ->withCookie('__Host-a', 'b')
                ->withFile("{$root}/receipts/a1.png");

            $handed = Sapi::forServer($response, $root);

            expect($handed->header(Sapi::FILE_HEADER))->toBe('/receipts/a1.png')
                ->and($handed->isStreaming())->toBeFalse()
                ->and($handed->body())->toBe('')
                ->and($handed->status)->toBe(200)
                ->and($handed->header('content-type'))->toBe('image/png')
                ->and($handed->header('x-content-type-options'))->toBe('nosniff')
                ->and(array_keys($handed->cookies()))->toBe(['__Host-a'])
                ->and($sent)->toBeFalse();

            // Told no root, or another one, the worker sends the file itself.
            mkdir("{$root}-other", 0o700);
            foreach ([null, '', "{$root}-other", "{$root}-missing"] as $elsewhere) {
                $kept = Sapi::forServer($response, $elsewhere);
                expect($kept->header(Sapi::FILE_HEADER))->toBeNull()
                    ->and($kept->body())->toBe('bytes');
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg("{$root}-other"));
        }
    });

    it('hands over no file outside the root, through a link, or under a name it would have to escape', function () {
        $root = sys_get_temp_dir() . '/tinvar-sapi-files-' . bin2hex(random_bytes(4));
        $outside = sys_get_temp_dir() . '/tinvar-sapi-outside-' . bin2hex(random_bytes(4));
        mkdir($root, 0o700);
        mkdir($outside, 0o700);
        file_put_contents("{$outside}/secret", 'secret');
        symlink("{$outside}/secret", "{$root}/link");
        file_put_contents("{$root}/a name?x=1", 'odd');
        file_put_contents("{$root}/.hidden", 'hidden');

        try {
            $from = static fn (string $path): Response => Response::stream(static function (Closure $sink): void {}, 200)->withFile($path);

            // Sent by the worker -- or, of no known length, spooled under a
            // name of its own (ADR 0109) -- never handed over by its path.
            foreach (["{$outside}/secret", "{$root}/link", "{$root}/a name?x=1", "{$root}/.hidden", "{$root}/missing", $root] as $path) {
                expect(Sapi::forServer($from($path), $root)->header(Sapi::FILE_HEADER) ?? '/tinvar-spool/')->toStartWith('/tinvar-spool/', $path);
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg($outside));
        }
    });

    // Only the file a response was built from is ever named: a header an
    // application set itself is not passed to the server.
    it('drops either header an application set itself', function () {
        $response = Response::make(200, 'ok', ['x-accel-redirect' => '/etc/passwd', 'x-accel-buffering' => 'no']);

        $sent = Sapi::forServer($response, '/');

        expect($sent->header(Sapi::FILE_HEADER))->toBeNull()
            ->and($sent->header(Sapi::STREAM_HEADER))->toBeNull()
            ->and($sent->body())->toBe('ok');
    });

    it('sends only a streamed response from a file, and only by its absolute path', function () {
        expect(fn () => Response::make(200, 'x')->withFile('/tmp/x'))->toThrow(LogicException::class)
            ->and(fn () => Response::stream(static function (Closure $sink): void {})->withFile('relative'))->toThrow(InvalidArgumentException::class);
    });

    it('keeps the file through what runs around the producer: a sessionless endpoint\'s emptied cookies', function () {
        $response = Response::stream(static function (Closure $sink): void {
            $sink(isset($_COOKIE['sid']) ? 'saw the cookie' : 'no cookie');
        }, 200, ['content-type' => 'image/png'])->withCookie('__Host-a', 'b')->withFile('/srv/files/receipt.png');
        $_COOKIE['sid'] = 'owner';

        try {
            $wrapped = \Tinvar\Endpoints\CookielessGlobals::stream($response);

            expect($wrapped->file())->toBe('/srv/files/receipt.png')
                ->and($wrapped->header('content-type'))->toBe('image/png')
                ->and($wrapped->cookies())->toHaveKey('__Host-a')
                ->and($wrapped->body())->toBe('no cookie');
        } finally {
            unset($_COOKIE['sid']);
        }
    });
});

// ADR 0109: a body too large to hold whole, or a stream of no known length,
// was still written by its worker to the client, under `parallel`'s mutex.
// It is written to a file under the file root instead, for the server to
// send as it sends a stored file.
describe('a body too large to hold', function () {
    /** A file root of its own, removed after. */
    $root = static function (): string {
        $root = sys_get_temp_dir() . '/tinvar-sapi-spool-' . bin2hex(random_bytes(4));
        mkdir($root, 0o700);
        register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

        return $root;
    };
    $spooled = static fn (string $root, Response $handed): string => $root . (string) $handed->header(Sapi::FILE_HEADER);

    it('is written whole to a file of its own under the file root, and handed over with every header and cookie', function () use ($root, $spooled) {
        $files = $root();
        $body = str_repeat('x', Sapi::BUFFERED_BYTES + 1);
        $response = Response::make(200, $body, ['content-type' => 'text/html; charset=utf-8', 'content-length' => (string) \strlen($body), 'cache-control' => 'no-store'])
            ->withCookie('__Host-a', 'b');

        $handed = Sapi::forServer($response, $files);

        expect($handed->header(Sapi::FILE_HEADER))->toMatch('#^/tinvar-spool/[0-9a-f]{32}\z#')
            ->and($handed->header(Sapi::STREAM_HEADER))->toBeNull()
            ->and($handed->body())->toBe('')
            ->and($handed->status)->toBe(200)
            ->and($handed->header('content-type'))->toBe('text/html; charset=utf-8')
            ->and($handed->header('cache-control'))->toBe('no-store')
            ->and($handed->header('content-length'))->toBeNull()
            ->and(array_keys($handed->cookies()))->toBe(['__Host-a'])
            ->and(file_get_contents($spooled($files, $handed)))->toBe($body)
            ->and(fileperms($spooled($files, $handed)) & 0o777)->toBe(0o600)
            ->and(fileperms("{$files}/tinvar-spool") & 0o777)->toBe(0o700);
    });

    // A body past the limit is not sent at all; its producer went on
    // rendering every chunk after, into a sink that dropped them.
    it('stops the producer once the spool has failed, and answers 500 with nothing left on disk', function () use ($root) {
        $files = $root();
        $produced = 0;
        $response = Response::stream(static function (Closure $sink) use (&$produced): void {
            for ($i = 0; $i < 100; $i++) {
                $produced++;
                $sink('xxxx');
            }
        }, 200, ['content-type' => 'text/csv']);

        $log = ini_set('error_log', '/dev/null');
        try {
            $handed = Sapi::forServer($response, $files, spoolMaxBytes: 10);
        } finally {
            ini_set('error_log', (string) $log);
        }

        expect($handed->status)->toBe(500)
            ->and($handed->header(Sapi::FILE_HEADER))->toBeNull()
            ->and($produced)->toBe(3)
            ->and(glob("{$files}/tinvar-spool/*"))->toBe([]);
    });

    it('takes a stream of no known length, run once', function () use ($root, $spooled) {
        $files = $root();
        $runs = 0;
        $response = Response::stream(static function (Closure $sink) use (&$runs): void {
            $runs++;
            $sink('a');
            $sink('b');
        }, 200, ['content-type' => 'text/csv']);

        $handed = Sapi::forServer($response, $files);

        expect(file_get_contents($spooled($files, $handed)))->toBe('ab')
            ->and($runs)->toBe(1)
            ->and(Sapi::forServer($response, $files)->header(Sapi::FILE_HEADER))->not->toBe($handed->header(Sapi::FILE_HEADER));
    });

    // The file server answers 200; an event stream must arrive as it is
    // written; and with nowhere to write it, the worker passes it on.
    it('is passed on as it comes when it is not a 200, an event stream, or there is no file root', function () use ($root) {
        $files = $root();
        $big = str_repeat('x', Sapi::BUFFERED_BYTES + 1);
        $events = Response::stream(static function (Closure $sink): void {
            $sink("data: 1\n\n");
        }, 200, ['content-type' => 'text/event-stream']);

        foreach ([
            [Response::make(404, $big), $files],
            [$events, $files],
            [Response::make(200, $big), null],
            [Response::make(200, $big), "{$files}/missing"],
            [Response::stream(static function (Closure $sink): void {}, 200, ['content-length' => (string) (Sapi::SPOOL_MAX_BYTES + 1)]), $files],
        ] as [$response, $at]) {
            $sent = Sapi::forServer($response, $at);
            expect($sent->header(Sapi::FILE_HEADER))->toBeNull()
                ->and($sent->header(Sapi::STREAM_HEADER))->toBe('no');
        }
    });

    it('is never spooled into a directory a link points elsewhere', function () use ($root) {
        $files = $root();
        $elsewhere = $root();
        symlink($elsewhere, "{$files}/tinvar-spool");

        $sent = Sapi::forServer(Response::make(200, str_repeat('x', Sapi::BUFFERED_BYTES + 1)), $files);

        expect($sent->header(Sapi::FILE_HEADER))->toBeNull()
            ->and($sent->header(Sapi::STREAM_HEADER))->toBe('no')
            ->and(glob("{$elsewhere}/*"))->toBe([]);
    });

    it('removes what it spooled long ago as it writes the next', function () use ($root, $spooled) {
        $files = $root();
        mkdir("{$files}/tinvar-spool", 0o700);
        $old = "{$files}/tinvar-spool/" . str_repeat('a', 32);
        $recent = "{$files}/tinvar-spool/" . str_repeat('b', 32);
        file_put_contents($old, 'old');
        file_put_contents($recent, 'recent');
        touch($old, time() - Sapi::SPOOL_KEPT_SECONDS - 5);

        $handed = Sapi::forServer(Response::make(200, str_repeat('x', Sapi::BUFFERED_BYTES + 1)), $files);

        expect(is_file($old))->toBeFalse()
            ->and(is_file($recent))->toBeTrue()
            ->and(is_file($spooled($files, $handed)))->toBeTrue();
    });

    it('removes a body whose producer failed, and lets the failure through', function () use ($root) {
        $files = $root();
        $failing = Response::stream(static function (Closure $sink): void {
            $sink('part');

            throw new RuntimeException('render failed');
        }, 200);

        expect(fn () => Sapi::forServer($failing, $files))->toThrow(RuntimeException::class, 'render failed')
            ->and(glob("{$files}/tinvar-spool/*"))->toBe([]);
    });
});
