<?php

declare(strict_types=1);

/**
 * RenderChannel against a pool: a directory of renderer sockets, one per
 * process, as `renderer.js --processes=N` keeps it (ADR 0028).
 *
 * The pool's own behaviour -- restarts, rolling restarts, draining -- is the
 * renderer's and tested there; this is PHP's half: every render goes to one
 * of the pool's renderers, they take turns, a renderer that refuses is
 * passed over before anything is sent to it, and the trust checks hold for
 * the directory and for every socket in it.
 */

namespace Tinvar\Tests\Render\RendererPool;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Bench\Renderer;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Protocol\Frame;
use Tinvar\Query\QueryRegistry;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\RenderChannel;
use Tinvar\Render\RendererException;
use Tinvar\Render\SocketTrust;
use Tinvar\Tests\Orm\Fixtures\Fixture;

require_once __DIR__ . '/../Orm/Fixtures/models.php';
require_once __DIR__ . '/../Orm/Fixtures/schema.php';

$root = \dirname(__DIR__, 4);

function channel(string $path): RenderChannel
{
    $fixture = new Fixture(racks: 1, unitsPerRack: 1, sites: 1);

    return new RenderChannel(
        $path,
        new QueryRunner(new QueryRegistry(), new Orm($fixture->connection, new IdentityMap('test')), new AllowAllGate()),
        new MessagePackCodec(),
        deadlineSeconds: 5.0,
    );
}

/** Renders once and returns what the renderer wrote. */
function renderOnce(RenderChannel $channel): string
{
    $html = '';
    $channel->render(['t' => 'render'], [], static function (string $bytes) use (&$html): void {
        $html .= $bytes;
    });

    return $html;
}

/** A directory for a pool of fakes, which only this user can write. */
function poolDirectory(): string
{
    $dir = '/tmp/tvpool-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);

    return $dir;
}

/**
 * A fake renderer on $path that answers each render with one chunk naming
 * itself, then done -- as many renders as it is sent.
 */
function fakeRenderer(string $path, string $name): int
{
    $server = stream_socket_server("unix://{$path}", $errno, $error);
    if ($server === false) {
        throw new \RuntimeException("Cannot listen on {$path}: {$error}");
    }

    $pid = pcntl_fork();
    if ($pid === 0) {
        $codec = new MessagePackCodec();
        while (($client = @stream_socket_accept($server, 10)) !== false) {
            fread($client, 65536);
            fwrite($client, $codec->encodeFrame(Frame::chunk($name)) . $codec->encodeFrame(['t' => Frame::DONE]));
            fclose($client);
        }
        exit(0);
    }
    fclose($server);

    return $pid;
}

/**
 * A socket file nothing listens on: what a renderer killed outright leaves.
 * PHP's stream server leaves its path behind when closed, as a dead process
 * does.
 */
function deadSocket(string $path): void
{
    $server = stream_socket_server("unix://{$path}");
    if ($server === false) {
        throw new \RuntimeException("Cannot listen on {$path}");
    }
    fclose($server);
}

/** @param list<int> $pids */
function cleanUpPool(string $dir, array $pids = []): void
{
    foreach ($pids as $pid) {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }
    @chmod($dir, 0700);
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

describe('a pool of real renderers', function () use ($root) {
    // The point of the pool: every renderer in it does its share.
    it('takes the renderers in turn, so every one of them renders', function () use ($root) {
        $app = '/tmp/tvapp-' . bin2hex(random_bytes(4));
        $entry = "{$app}/dist/rsc/index.js";
        mkdir(\dirname($entry), 0700, true);
        file_put_contents($entry, <<<'JS'
            const once = (text) => new ReadableStream({ start(c) { c.enqueue(new TextEncoder().encode(text)); c.close(); } });
            export async function renderHtml() {
              return { htmlStream: once('<p>pid ' + process.pid + '</p>'), rscStream: once('0:"row"\n') };
            }
            export function renderRsc() { return once('0:"row"\n'); }
            JS);

        $renderer = Renderer::start('msgpack', 'pool', $entry, processes: 3);
        try {
            $channel = channel($renderer->socketPath);
            $pids = [];
            for ($i = 0; $i < 12; $i++) {
                preg_match('/pid (\d+)/', renderOnce($channel), $m);
                $pids[] = (int) ($m[1] ?? 0);
            }
        } finally {
            $renderer->stop();
            unlink($entry);
            rmdir("{$app}/dist/rsc");
            rmdir("{$app}/dist");
            rmdir($app);
        }

        // Three processes, four renders each: turns, not chance.
        $counts = array_count_values($pids);
        expect($counts)->toHaveCount(3)
            ->and(array_values($counts))->toBe([4, 4, 4])
            ->and(array_keys($counts))->not->toContain(0);
    })->skip(fn () => !is_file($root . '/node_modules/@msgpack/msgpack/package.json'), 'npm ci first');
});

describe('a renderer in the pool that refuses', function () {
    // Killed, or draining since the directory was listed: nothing has been
    // sent to it, so the render goes to another.
    it('is passed over for one that answers, whichever turn it is', function () {
        $dir = poolDirectory();
        deadSocket("{$dir}/renderer-0-1.sock");
        $pid = fakeRenderer("{$dir}/renderer-1-2.sock", 'live');

        try {
            $channel = channel($dir);
            $rendered = [];
            for ($i = 0; $i < 4; $i++) {
                $rendered[] = renderOnce($channel);
            }
        } finally {
            cleanUpPool($dir, [$pid]);
        }

        expect($rendered)->toBe(['live', 'live', 'live', 'live']);
    });

    // Draining, a renderer removes its socket at once, and can do so after
    // the listing and before the socket is inspected. Seen once by an
    // existence check and gone at the inspection, it failed the render as a
    // socket that could not be inspected; gone is now the inspection's own
    // answer, and the socket is passed over like any that refuses.
    it('is passed over when its socket is gone by the time it is inspected', function () {
        $dir = poolDirectory();

        try {
            expect(new SocketTrust()->assertSocket("{$dir}/renderer-0-1.sock"))->toBeFalse()
                ->and(new SocketTrust()->assertSocket("{$dir}/missing/renderer-0-1.sock"))->toBeFalse();
        } finally {
            cleanUpPool($dir);
        }
    });

    it('fails the render, naming each refusal, when none in the pool answers', function () {
        $dir = poolDirectory();
        deadSocket("{$dir}/renderer-0-1.sock");
        deadSocket("{$dir}/renderer-1-2.sock");

        try {
            expect(fn () => renderOnce(channel($dir)))
                ->toThrow(function (RendererException $e) use ($dir): void {
                    expect($e->code_)->toBe('renderer_unreachable')
                        ->and($e->getMessage())->toContain("Cannot reach the renderer at {$dir}")
                        ->toContain('no renderer in the pool answered')
                        ->toContain('renderer-0-1.sock')
                        ->toContain('renderer-1-2.sock');
                });
        } finally {
            cleanUpPool($dir);
        }
    });

    it('fails the render when the pool has no renderers at all', function () {
        $dir = poolDirectory();

        try {
            expect(fn () => renderOnce(channel($dir)))
                ->toThrow(RendererException::class, 'no renderer is listening in that directory');
        } finally {
            cleanUpPool($dir);
        }
    });
});

// tinvar-spec.md, "Renderer hardening": a private socket, owned by the app
// user. A pool is found by listing its directory, so the directory is held
// to more than a single socket's is: nobody else may write it at all.
describe('trusting a pool', function () {
    it('refuses a pool directory other users can write', function (int $mode) {
        $dir = poolDirectory();
        $pid = fakeRenderer("{$dir}/renderer-0-1.sock", 'live');
        chmod($dir, $mode);

        try {
            expect(fn () => renderOnce(channel($dir)))
                ->toThrow(RendererException::class, 'the pool directory is writable by other users');
        } finally {
            cleanUpPool($dir, [$pid]);
        }
    })->with([
        'shared' => [0777],
        // Sticky is enough for one socket at a known path -- a stranger
        // cannot replace ours -- and not for a pool, where a stranger's
        // added beside ours would be listed with them.
        'shared and sticky, as /tmp is' => [01777],
    ]);

    it('renders from a pool directory only this user can write', function () {
        $dir = poolDirectory();
        $pid = fakeRenderer("{$dir}/renderer-0-1.sock", 'live');

        try {
            expect(renderOnce(channel($dir)))->toBe('live');
        } finally {
            cleanUpPool($dir, [$pid]);
        }
    });
})->skip(fn () => !\function_exists('posix_geteuid') || posix_geteuid() === 0, 'needs POSIX ownership, and not root');
