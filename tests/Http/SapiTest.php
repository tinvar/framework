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
    it('writes the status, every header, then the body as it streams', function () {
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

        expect($log)->toBe(['status 200', 'content-type: text/html', '<p>one</p>', '<p>two</p>']);
    });
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

            foreach (["{$outside}/secret", "{$root}/link", "{$root}/a name?x=1", "{$root}/.hidden", "{$root}/missing", $root] as $path) {
                expect(Sapi::forServer($from($path), $root)->header(Sapi::FILE_HEADER))->toBeNull($path);
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
});
