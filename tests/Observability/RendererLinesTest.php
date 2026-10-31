<?php

declare(strict_types=1);

/**
 * The renderers' lines, as `tinvar serve` writes them into the application's
 * log (ADR 0128): each rebuilt rather than copied, since they are the
 * renderer's, whose side runs third-party npm code.
 */

namespace Tinvar\Tests\Observability\RendererLines;

use Tinvar\Observability\MemorySink;
use Tinvar\Observability\RendererLines;

const ID = '4bf92f3577b34da6a3ce929d0e0e4736';

it('keeps a render\'s line as JSON, under the request id it carries, as the renderer\'s', function () {
    $sink = new MemorySink();
    $lines = new RendererLines($sink);

    $lines->feed('9', json_encode([
        'time' => '2026-10-30T09:14:03.512Z',
        'level' => 'error',
        'message' => 'render failed',
        'source' => 'renderer',
        'request_id' => ID,
        'code' => 'render_failed',
        'error' => ['class' => 'TypeError', 'message' => 'x is undefined'],
    ]) . "\n", true);

    [$line] = $sink->records();
    expect($line)->toMatchArray([
        'level' => 'error',
        'message' => 'render failed',
        'source' => 'renderer',
        'request_id' => ID,
        'renderer' => ['code' => 'render_failed', 'error' => ['class' => 'TypeError', 'message' => 'x is undefined']],
    ])->and($line)->not->toHaveKey('code')
        ->and($line['time'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/');
});

it('joins a line split across reads, keeps two streams apart, and writes what a stream left at its end', function () {
    $sink = new MemorySink();
    $lines = new RendererLines($sink);

    $lines->feed('1', '{"level":"info","message":"pool ', false);
    $lines->feed('2', '{"level":"warning","message":"socket error"}' . "\n" . 'half', true);
    $lines->feed('1', 'started"}' . "\n", false);
    $lines->close('2', true);

    expect(array_map(static fn (array $r): array => [$r['level'], $r['message']], $sink->records()))->toBe([
        ['warning', 'socket error'],
        ['info', 'pool started'],
        ['error', 'half'],
    ]);
});

// The renderer could say anything on its lines: what it may say is held to
// the shape PHP's own lines have, and redacted on this side as well.
it('believes no source, request id or level of the renderer\'s that is not one, and redacts what it says', function () {
    $sink = new MemorySink();
    $lines = new RendererLines($sink);

    $lines->line(json_encode([
        'level' => 'emergency',
        'message' => 'sent to ada@example.com with Bearer abcdefghijklmnop',
        'source' => 'php',
        'request_id' => 'not-an-id',
        'password' => 'hunter2',
    ]), false);
    $lines->line('{"message":"x","request_id":"' . ID . '\n{\"forged\":true}"}', true);

    [$first, $second] = $sink->records();
    expect($first['level'])->toBe('info')
        ->and($first['source'])->toBe('renderer')
        ->and($first)->not->toHaveKey('request_id')
        ->and($first['message'])->not->toContain('ada@example.com')->not->toContain('abcdefghijklmnop')
        ->and($first['renderer']['password'])->not->toBe('hunter2')
        ->and($second['level'])->toBe('error')
        ->and($second)->not->toHaveKey('request_id')
        ->and($sink->lines)->toHaveCount(2);
});

it('writes a line that is not a JSON object as a message, at its stream\'s level', function () {
    $sink = new MemorySink();
    $lines = new RendererLines($sink);

    $lines->feed('1', "node:internal/process: uncaught TypeError at ada@example.com\n[1,2]\n\n", true);
    $lines->feed('2', "listening\n", false);

    expect(array_map(static fn (array $r): array => [$r['level'], $r['source']], $sink->records()))->toBe([
        ['error', 'renderer'],
        ['error', 'renderer'],
        ['info', 'renderer'],
    ])->and($sink->lines[0])->not->toContain('ada@example.com');
});

it('cuts a line past its cap, says so, and holds nothing without end', function () {
    $sink = new MemorySink();
    $lines = new RendererLines($sink);

    $lines->feed('1', str_repeat('x', RendererLines::MAX_LINE + 10), true);
    $lines->feed('1', "tail\n", true);

    [$cut, $tail] = $sink->records();
    expect($cut['truncated'])->toBeTrue()
        ->and($tail['message'])->toBe('tail');
});

// A renderer writing without end must not fill the disk the application's
// own lines go to.
it('writes at most its budget of lines a second, and says next how many it dropped', function () {
    $sink = new MemorySink();
    $now = 1000.0;
    $lines = new RendererLines($sink, clock: static function () use (&$now): float {
        return $now;
    });

    $lines->feed('1', str_repeat("{\"level\":\"error\",\"message\":\"flood\"}\n", RendererLines::MAX_PER_SECOND + 40), true);
    $now = 1001.2;
    $lines->feed('1', "{\"level\":\"info\",\"message\":\"after\"}\n", false);

    $records = $sink->records();
    expect($records)->toHaveCount(RendererLines::MAX_PER_SECOND + 2)
        ->and($records[RendererLines::MAX_PER_SECOND])->toMatchArray(['level' => 'warning', 'message' => 'renderer lines dropped', 'dropped' => 40, 'source' => 'renderer'])
        ->and(end($records)['message'])->toBe('after');
});

it('writes nothing below the application\'s level', function () {
    $sink = new MemorySink();
    $lines = new RendererLines($sink, 'warning');

    $lines->feed('1', '{"level":"info","message":"a render"}' . "\n" . '{"level":"error","message":"a failure"}' . "\n", false);

    expect(array_column($sink->records(), 'message'))->toBe(['a failure']);
});

// A line's size, not only its count: 500 lines a second of 64 KiB each was
// 32 MiB a second into the application's log.
it('holds each stream to a budget of bytes a second, and cuts each line it relays', function () {
    $sink = new MemorySink();
    $now = 1000.0;
    $lines = new RendererLines($sink, clock: static function () use (&$now): float {
        return $now;
    });

    $long = json_encode(['level' => 'info', 'message' => str_repeat('x', 20_000)]);
    $lines->feed('1', $long . "\n", false);
    $big = str_repeat('y', RendererLines::MAX_LINE - 10);
    $lines->feed('1', str_repeat($big . "\n", 200), false);
    // Another stream's share is its own.
    $lines->feed('2', "{\"level\":\"info\",\"message\":\"other\"}\n", false);

    $written = array_sum(array_map(strlen(...), $sink->lines));
    $records = $sink->records();
    expect(RendererLines::MAX_LINE)->toBeLessThanOrEqual(8_192)
        ->and(\strlen($sink->lines[0]))->toBeLessThan(RendererLines::MAX_LINE + 512)
        ->and($records[0]['truncated'] ?? null)->toBeTrue()
        ->and($written)->toBeLessThan(RendererLines::MAX_BYTES_PER_SECOND + 64 * 1024)
        ->and(\count($records))->toBeLessThan(150)
        ->and(end($records)['message'])->toBe('other');
});

// A renderer's line is the renderer's say-so: it pages no one at critical,
// and its fields never stand beside the line's own as if PHP had set them.
it('caps a relayed line\'s level by its stream, and nests its fields under renderer', function () {
    $sink = new MemorySink();
    $lines = new RendererLines($sink);

    $lines->line('{"level":"critical","message":"a","digest":"forged","user_id":7,"exception":{"class":"X"}}', true);
    $lines->line('{"level":"error","message":"b"}', false);
    $lines->line('{"level":"critical","message":"c"}', false);
    $lines->line('{"level":"debug","message":"d"}', true);

    [$a, $b, $c] = $sink->records();
    expect($a['level'])->toBe('error')
        ->and($a)->not->toHaveKeys(['digest', 'user_id', 'exception'])
        ->and($a['renderer'])->toMatchArray(['digest' => 'forged', 'user_id' => 7])
        ->and($b['level'])->toBe('warning')
        ->and($c['level'])->toBe('warning')
        ->and($sink->lines)->toHaveCount(3);
});

// A line too long to relay whole -- a failure's stack -- is cut, and each
// piece of it is still the request's: without the id, the log could not put
// the failure beside the request it was.
it('keeps the request id on every piece of a line it cuts', function () {
    $sink = new MemorySink();
    $lines = new RendererLines($sink);
    $line = json_encode(['level' => 'error', 'message' => 'render failed', 'request_id' => ID, 'stack' => str_repeat('at frame (file.js:1:1) ', 900)]);

    $lines->feed('1', substr($line, 0, 10_000), true);
    $lines->feed('1', substr($line, 10_000) . "\n", true);
    $lines->feed('1', "{\"level\":\"info\",\"message\":\"next\"}\n", false);

    $records = $sink->records();
    expect($records)->toHaveCount(3)
        ->and([$records[0]['request_id'] ?? null, $records[1]['request_id'] ?? null])->toBe([ID, ID])
        ->and($records[0]['truncated'] ?? null)->toBeTrue()
        ->and($records[2])->not->toHaveKey('request_id');
});

// A stream's end says what its last second dropped, and forgets its budget:
// a renderer replaced takes a stream name of its own, and the old one's
// count was otherwise never said, and its budget kept for good.
it('says what a closed stream dropped, and keeps no budget for it', function () {
    $sink = new MemorySink();
    $now = 1_000.0;
    $lines = new RendererLines($sink, clock: static function () use (&$now): float {
        return $now;
    });

    $lines->feed('7', str_repeat("boom\n", RendererLines::MAX_PER_SECOND + 100), true);
    $lines->close('7', true);

    $dropped = array_values(array_filter($sink->records(), static fn (array $record): bool => $record['message'] === 'renderer lines dropped'));
    expect($dropped)->toHaveCount(1)
        ->and($dropped[0]['dropped'])->toBe(100)
        ->and((new \ReflectionProperty($lines, 'budgets'))->getValue($lines))->toBe([]);
});
