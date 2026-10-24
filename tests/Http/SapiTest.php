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
