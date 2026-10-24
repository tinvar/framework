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
