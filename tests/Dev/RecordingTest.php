<?php

declare(strict_types=1);

/**
 * What a recording keeps of a request: bounded, since the renderer chooses
 * what it sends, and with every secret-named value taken out on the way in.
 */

namespace Tinvar\Tests\Dev\Recording;

use Tinvar\Codec\Bin;
use Tinvar\Dev\Recording;
use Tinvar\Http\Request;
use Tinvar\Orm\RawStatement;
use Tinvar\Render\FrameTap;

function recording(?Request $request = null): Recording
{
    return new Recording($request ?? new Request('GET', '/catalog', ['accept' => 'text/html']));
}

/** @return array<string, mixed> */
function detail(Recording $recording): array
{
    return $recording->finish(200, 's', 'u')['detail'];
}

it('keeps so many frames of a request, and counts the rest', function () {
    $recording = recording();
    for ($i = 0; $i < Recording::MAX_FRAMES + 5; $i++) {
        $recording->frame(FrameTap::FROM_RENDERER, ['t' => 'chunk', 'b' => 'x']);
    }
    $detail = detail($recording);

    expect($detail['frames'])->toHaveCount(Recording::MAX_FRAMES)
        ->and($detail['framesDropped'])->toBe(5);
});

it('keeps a frame too large to show as its size, and stops past the request\'s budget', function () {
    $recording = recording();
    $recording->frame(FrameTap::TO_RENDERER, ['t' => 'render', 'data' => str_repeat('a', Recording::MAX_FRAME_BYTES)]);
    for ($i = 0; $i < 6; $i++) {
        $recording->frame(FrameTap::TO_RENDERER, ['t' => 'result', 'r' => str_repeat('b', Recording::MAX_FRAME_BYTES - 100)]);
    }
    $detail = detail($recording);

    expect($detail['frames'][0]['body']['t'])->toBe('render')
        ->and($detail['frames'][0]['body'])->not->toHaveKey('data')
        ->and($detail['frames'][0]['body']['omitted'])->toMatch('/^\d+ bytes of JSON, more than the toolbar keeps of one frame\z/')
        ->and(\count($detail['frames']))->toBeLessThan(7)
        ->and($detail['framesDropped'])->toBeGreaterThan(0)
        ->and(\strlen((string) json_encode($detail['frames'])))->toBeLessThan(Recording::MAX_BYTES + 65536);
});

it('shows an output chunk as its size and the start of it, as text whatever its bytes', function () {
    $recording = recording();
    $recording->frame(FrameTap::FROM_RENDERER, ['t' => 'chunk', 'b' => "\xff\xfe<p>" . str_repeat('z', 5000)]);
    $frame = detail($recording)['frames'][0];

    expect($frame['bytes'])->toBe(5005)
        ->and(mb_check_encoding($frame['body']['b']['preview'], 'UTF-8'))->toBeTrue()
        ->and(\strlen($frame['body']['b']['preview']))->toBeLessThanOrEqual(Recording::PREVIEW_BYTES * 3);
});

it('takes secret-named strings out wherever they are, and keeps everything else', function () {
    expect(Recording::redact([
        'nonce' => 'n',
        'csrf' => 'c',
        'page' => ['routeToken' => 'r', 'historyKey' => 'h', 'url' => '/catalog'],
        'm' => [['id' => 0, 'token' => 'v1.x.y']],
        'data' => ['customer' => ['name' => 'Ada', 'password' => 'hunter2', 'apiKey' => 'k', 'tokenCount' => 3.5, 'signedIn' => true]],
        'tokens' => [['component' => 'Reviews', 'props' => ['sku' => 'L-1']]],
    ]))->toBe([
        'nonce' => Recording::REDACTED,
        'csrf' => Recording::REDACTED,
        'page' => ['routeToken' => Recording::REDACTED, 'historyKey' => Recording::REDACTED, 'url' => '/catalog'],
        'm' => [['id' => 0, 'token' => Recording::REDACTED]],
        'data' => ['customer' => ['name' => 'Ada', 'password' => Recording::REDACTED, 'apiKey' => Recording::REDACTED, 'tokenCount' => 3.5, 'signedIn' => true]],
        // A fragment frame's verified token contents are what it renders, not a credential.
        'tokens' => [['component' => 'Reviews', 'props' => ['sku' => 'L-1']]],
    ]);
});

it('draws no object it is handed, and goes no deeper than it shows', function () {
    $deep = 'bottom';
    for ($i = 0; $i < 40; $i++) {
        $deep = [$deep];
    }

    expect(Recording::redact(['b' => new Bin('abc'), 'o' => new \ArrayObject(), 'n' => NAN, 'i' => -INF]))
        ->toBe(['b' => ['bin' => 3], 'o' => '[ArrayObject]', 'n' => 'NAN', 'i' => '-INF'])
        ->and(json_encode(Recording::redact($deep)))->toContain('nested deeper than the toolbar shows');
});

it('names what each request is, as the toolbar groups them', function (Request $request, string $kind) {
    expect(Recording::kindOf($request))->toBe($kind);
})->with([
    'a document' => [new Request('GET', '/catalog', ['accept' => 'text/html']), 'document'],
    'a client navigation' => [new Request('GET', '/catalog', ['accept' => 'text/x-component', 'rsc' => '1']), 'payload'],
    'an action' => [new Request('POST', '/_action/orders.place'), 'action'],
    'a fragment' => [new Request('GET', '/_fragment/v1.abc'), 'fragment'],
    'a live trade' => [new Request('POST', '/_live/subscribe'), 'live'],
    'anything else' => [new Request('GET', '/feed.json', ['accept' => 'application/json']), 'other'],
]);

// The renderer writes what it was given back into the page: the nonce on its
// scripts, the route token in the page metadata, JSON-escaped.
it('takes the secrets PHP sent out of what the renderer sends back', function () {
    $recording = recording();
    $recording->frame(FrameTap::TO_RENDERER, ['t' => 'render', 'nonce' => 'ab+c/d==Nonce', 'page' => ['routeToken' => 'v1.route.token', 'url' => '/']]);
    $recording->frame(FrameTap::FROM_RENDERER, ['t' => 'chunk', 'b' => '<script nonce="ab+c/d==Nonce"></script>{"routeToken":"v1.route.token","nonce":"ab+c\\/d==Nonce"}']);
    $recording->frame(FrameTap::FROM_RENDERER, ['t' => 'meta', 'title' => 'ab+c/d==Nonce']);
    $frames = detail($recording)['frames'];

    expect($frames[1]['body']['b']['preview'])->toBe('<script nonce="[redacted]"></script>{"routeToken":"[redacted]","nonce":"[redacted]"}')
        ->and($frames[2]['body']['title'])->toBe(Recording::REDACTED)
        // And only those: a short value would take everything out with it.
        ->and($frames[0]['body']['page']['url'])->toBe('/');
});

it('keeps a summary to one short line, however long the path', function () {
    $summary = recording(new Request('GET', '/' . str_repeat('a', 20_000)))->finish(200, 's', 'u')['summary'];

    expect(\strlen($summary['path']))->toBe(Recording::MAX_PATH_BYTES)
        ->and(\strlen((string) json_encode($summary)))->toBeLessThan(4096);
});

// The request's own query was redacted where the recording keeps it, and
// left whole everywhere else: in the render frame's page url, the path and
// query, and in whatever the renderer drew with it.
it('takes a secret in the request\'s query out of every frame', function () {
    $recording = recording(new Request('GET', '/reset', ['accept' => 'text/html'], ['token' => 'reset-secret-0123456789', 'code' => 'one-time-code-0123456789']));
    $recording->frame(FrameTap::TO_RENDERER, ['t' => 'render', 'page' => ['url' => '/reset?token=reset-secret-0123456789&code=one-time-code-0123456789']]);
    $recording->frame(FrameTap::FROM_RENDERER, ['t' => 'chunk', 'b' => '<a href="/reset?token=reset-secret-0123456789">again</a> ' . rawurlencode('/x?token=reset-secret-0123456789')]);
    $detail = detail($recording);

    expect($detail['query'])->toBe(['token' => Recording::REDACTED, 'code' => Recording::REDACTED])
        ->and((string) json_encode($detail))->not->toContain('reset-secret-0123456789')
        ->and((string) json_encode($detail))->not->toContain('one-time-code-0123456789');
});

it('takes out a secret-named value given as a list', function () {
    $detail = detail(recording(new Request('GET', '/reset', ['accept' => 'text/html'], ['token' => ['reset-secret-0123456789']])));

    expect((string) json_encode($detail))->not->toContain('reset-secret-0123456789');
});

// A fragment's and a file's token are in the path, not the query.
it('keeps no signed token a path carries', function () {
    $token = 'v1.eyJwYXlsb2FkIjoiZnJhZ21lbnQifQ.c2lnbmF0dXJlLW9mLXRoZS10b2tlbg';
    $recording = recording(new Request('GET', "/_fragment/{$token}", ['accept' => 'text/x-component']));
    $recording->frame(FrameTap::TO_RENDERER, ['t' => 'render', 'page' => ['url' => "/_fragment/{$token}"]]);
    $finished = $recording->finish(200, 's', 'u');

    expect($finished['summary']['path'])->toBe('/_fragment/[token]')
        ->and((string) json_encode($finished))->not->toContain($token);
});

it('keeps raw SQL on tenant tables as the ORM flagged it, so many of them, with this request\'s secrets taken out', function () {
    $recording = recording();
    $recording->frame(FrameTap::TO_RENDERER, ['t' => 'render', 'csrf' => 'csrf-token-1234567890']);
    $recording->rawStatement(new RawStatement("SELECT * FROM racks WHERE note = '?' -- csrf-token-1234567890", ['racks'], '/app/Racks.php:9', 'acme', false));
    for ($i = 0; $i < Recording::MAX_RAW_SQL + 2; $i++) {
        $recording->rawStatement(new RawStatement('DELETE FROM units', ['units'], '', '', true));
    }
    $finished = $recording->finish(200, 's', 'u');

    expect($finished['detail']['rawSql'])->toHaveCount(Recording::MAX_RAW_SQL)
        ->and($finished['detail']['rawSql'][0])->toBe([
            'sql' => "SELECT * FROM racks WHERE note = '?' -- " . Recording::REDACTED,
            'tables' => ['racks'],
            'site' => '/app/Racks.php:9',
            'tenant' => 'acme',
            'acrossTenants' => false,
        ])
        ->and($finished['detail']['rawSqlDropped'])->toBe(3)
        ->and($finished['summary']['rawSql'])->toBe(Recording::MAX_RAW_SQL + 3);
});

// A browser extension reads what the toolbar does (docs/devtools-protocol.md):
// the names a credential is kept under in the log's Redactor are kept out
// here too, and the shapes of one in free text -- a renderer's error, a
// chunk, raw SQL -- whatever key it is under.
it('takes out the names a credential is kept under', function () {
    expect(Recording::redact([
        'dsn' => 'mysql://shop:pw@db/shop',
        'privateKey' => 'k1',
        'access_key' => 'k2',
        'appKey' => 'k3',
        'credential' => 'c',
        'sessionId' => 's1',
        'otp' => '123456',
        'xsrf' => 'x',
        'passphrase' => 'p',
        // And not what only looks like one.
        'keyboard' => 'qwerty',
        'footprint' => 'small',
        'session' => 'spring',
    ]))->toBe([
        'dsn' => Recording::REDACTED,
        'privateKey' => Recording::REDACTED,
        'access_key' => Recording::REDACTED,
        'appKey' => Recording::REDACTED,
        'credential' => Recording::REDACTED,
        'sessionId' => Recording::REDACTED,
        'otp' => Recording::REDACTED,
        'xsrf' => Recording::REDACTED,
        'passphrase' => Recording::REDACTED,
        'keyboard' => 'qwerty',
        'footprint' => 'small',
        'session' => 'spring',
    ]);
});

it('cuts a credential out of free text wherever it is', function () {
    $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl';
    $signed = 'v1.eyJwYXlsb2FkIjoiZnJhZ21lbnQifQ.c2lnbmF0dXJlLW9mLXRoZS10b2tlbg';
    $recording = recording();
    $recording->frame(FrameTap::FROM_RENDERER, [
        't' => 'error',
        'message' => "connect failed: mysql://shop:hunter2-pw@db:3306/shop, Authorization: Bearer abcdef0123456789, jwt {$jwt}",
    ]);
    $recording->frame(FrameTap::FROM_RENDERER, ['t' => 'chunk', 'b' => "<a href=\"/_fragment/{$signed}\">x</a> http://localhost:8000/catalog"]);
    $recording->rawStatement(new RawStatement("SELECT '?' -- postgres://app:hunter2-pw@db/app", ['racks'], '', '', false));
    $json = (string) json_encode(detail($recording));

    expect($json)->not->toContain('hunter2-pw')
        ->and($json)->not->toContain('abcdef0123456789')
        ->and($json)->not->toContain($jwt)
        ->and($json)->not->toContain($signed)
        ->and($json)->toContain('mysql:\/\/shop:[redacted]@db:3306')
        ->and($json)->toContain('Bearer [redacted]')
        // A URL with a port and no credentials is left alone.
        ->and($json)->toContain('http:\/\/localhost:8000\/catalog');
});

it('cuts a credential the preview\'s end would split, a password holding an @ or a /, and Basic', function () {
    $recording = recording();
    $recording->frame(FrameTap::FROM_RENDERER, ['t' => 'chunk', 'b' => str_repeat('a ', (Recording::PREVIEW_BYTES - 20) / 2) . 'mysql://u:hunter2-split-password@db/x']);
    $recording->frame(FrameTap::FROM_RENDERER, ['t' => 'error', 'message' => 'mysql://u:p@ss-word-1@h pg://u:ab/cd-word-2@h Authorization: Basic dXNlcjpwYXNzd29yZA==']);
    $detail = detail($recording);
    $json = (string) json_encode($detail);

    expect(\strlen($detail['frames'][0]['body']['b']['preview']))->toBeLessThanOrEqual(Recording::PREVIEW_BYTES)
        ->and($json)->not->toContain('hunter2')
        ->and($json)->not->toContain('ss-word-1')
        ->and($json)->not->toContain('cd-word-2')
        ->and($json)->not->toContain('dXNlcjpwYXNzd29yZA')
        ->and(Recording::redact(['pwd' => 'x', 'pass' => 'y', 'jwt' => 'z', 'auth' => 'w', 'author' => 'Ada']))
        ->toBe(['pwd' => Recording::REDACTED, 'pass' => Recording::REDACTED, 'jwt' => Recording::REDACTED, 'auth' => Recording::REDACTED, 'author' => 'Ada']);
});

// The shapes of a secret in free text are the log's Redactor's, one list: a
// copy here drifted, and a URL whose user is an address -- ops@example.com
// -- kept its password in the toolbar while the log took it out.
it('cuts the same secrets out of free text as the log does', function (string $text) {
    $redacted = Recording::redact(['message' => $text])['message'];

    expect($redacted)->not->toContain('hunter2pass')
        ->and($redacted)->toContain(Recording::REDACTED);
})->with([
    'a URL whose user is an address' => ['connect failed: smtp://ops@example.com:hunter2pass@mail.example.com:587'],
    'a password holding an @' => ['redis://:hunter2pass@x@cache:6379'],
]);
