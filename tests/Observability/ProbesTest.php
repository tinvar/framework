<?php

declare(strict_types=1);

/**
 * /_health, /_ready and /_metrics (ADR 0041): "Internal network or token",
 * liveness that asks nothing, readiness that asks the database and the
 * renderers -- each renderer in a pool by the health frame ADR 0028 added.
 */

namespace Tinvar\Tests\Observability\Probes;

use Tinvar\Codec\MessagePackCodec;
use Tinvar\Http\Request;
use Tinvar\Observability\DatabaseCheck;
use Tinvar\Observability\HealthCheck;
use Tinvar\Observability\Logger;
use Tinvar\Observability\MemorySink;
use Tinvar\Observability\Metrics;
use Tinvar\Observability\ProbeAccess;
use Tinvar\Observability\Probes;
use Tinvar\Observability\RendererCheck;
use Tinvar\Orm\PdoConnection;
use Tinvar\Protocol\Frame;
use Tinvar\Render\RendererHealth;

const TOKEN = 'probe-token-0123456789abcdef0123456789';

function probe(string $path, ?string $address = '127.0.0.1', array $headers = [], string $method = 'GET'): Request
{
    return new Request($method, $path, $headers, remoteAddress: $address);
}

final class Check implements HealthCheck
{
    public function __construct(private readonly string $name, private readonly ?\Throwable $failure = null) {}

    public function name(): string
    {
        return $this->name;
    }

    public function check(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}

/**
 * Renderers that answer a health frame, one per socket, in a fork each.
 *
 * @param array<string, array<string, mixed>|null> $sockets path => the healthy frame it answers, or null
 *        for a socket nothing listens on any more
 * @return list<int>
 */
function renderers(array $sockets): array
{
    $pids = [];
    foreach ($sockets as $path => $answer) {
        $server = stream_socket_server("unix://{$path}", $errno, $error);
        if ($server === false) {
            throw new \RuntimeException("Cannot listen on {$path}: {$error}");
        }
        if ($answer === null) {
            // The file stays; nothing answers it. A renderer that died.
            fclose($server);
            continue;
        }
        $pid = pcntl_fork();
        if ($pid === 0) {
            $codec = new MessagePackCodec();
            while (($client = @stream_socket_accept($server, 5)) !== false) {
                fread($client, 4096);
                fwrite($client, $codec->encodeFrame($answer));
                fclose($client);
            }
            // Gone without running destructors: an inherited database
            // connection would hang up on the parent's socket.
            posix_kill(getmypid(), SIGKILL);
            exit(0);
        }
        fclose($server);
        $pids[] = $pid;
    }

    return $pids;
}

/** @param list<int> $pids */
function stop(array $pids, string $directory): void
{
    foreach ($pids as $pid) {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }
    foreach (glob("{$directory}/*") ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($directory);
}

function pool(): string
{
    $directory = '/tmp/tvprobe-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);

    return $directory;
}

describe('who may ask', function () {
    it('lets in loopback, and nothing else, unless told otherwise', function (string $address, bool $allowed) {
        expect(new ProbeAccess()->allows(probe('/_health', $address)))->toBe($allowed);
    })->with([
        ['127.0.0.1', true],
        ['127.8.9.10', true],
        ['::1', true],
        ['::ffff:127.0.0.1', true],
        ['10.0.0.5', false],
        ['::2', false],
        ['2001:db8::1', false],
        ['not an address', false],
    ]);

    // DNS rebinding: a page's own name pointed at 127.0.0.1 reaches the
    // probes from loopback, naming its domain. So does a reverse proxy on
    // the same machine, under the public name.
    it('refuses a loopback peer naming a host that is not this machine', function (string $host, bool $allowed) {
        expect(new ProbeAccess()->allows(probe('/_metrics', '127.0.0.1', ['host' => $host])))->toBe($allowed);
    })->with([
        ['localhost:8080', true],
        ['127.0.0.1', true],
        ['[::1]:9000', true],
        ['rebound.attacker.example', false],
        ['shop.example.com', false],
    ]);

    // The whole loopback network, as the allow rule reads it, not three
    // spellings of it: 127.8.9.10 naming a rebound domain is no different.
    it('holds every loopback address to the Host rule', function (string $address) {
        expect(new ProbeAccess()->allows(probe('/_metrics', $address, ['host' => 'rebound.attacker.example'])))->toBeFalse()
            ->and(new ProbeAccess()->allows(probe('/_metrics', $address, ['host' => 'localhost'])))->toBeTrue();
    })->with(['127.0.0.1', '127.8.9.10', '::1', '::ffff:127.0.0.2']);

    // DNS rebinding on an internal network: a page's own name pointed at
    // this server's address there reaches the probes from its visitor's
    // address on that network, naming its domain -- over plain http, with
    // no Fetch Metadata at all. A peer there names this server by an
    // address, which no page can rebind, or by a name it is listed as.
    it('holds a peer on a network it is given to naming this server', function (?string $host, bool $allowed) {
        $access = new ProbeAccess(['10.0.0.0/8', 'fd00::/8'], hosts: ['Shop.Default.svc.cluster.local.']);
        $headers = $host === null ? [] : ['host' => $host];

        expect($access->allows(probe('/_metrics', '10.1.2.3', $headers)))->toBe($allowed)
            ->and($access->allows(probe('/_metrics', 'fd12::7', $headers)))->toBe($allowed);
    })->with([
        'a rebound name' => ['rebound.attacker.example:8080', false],
        'a rebound name, no port' => ['rebound.attacker.example', false],
        'loopback\'s name' => ['localhost:8080', false],
        'an unlisted name' => ['shop.example.com', false],
        'its address' => ['10.0.0.5:8080', true],
        'its address, no port' => ['10.0.0.5', true],
        'its IPv6 address' => ['[fd00::5]:8080', true],
        'a listed name' => ['shop.default.svc.cluster.local:8080', true],
        'no Host' => [null, true],
    ]);

    // And, a layer beside that, a browser that says it is one is no probe,
    // whatever it names.
    it('refuses a browser on a network it is given', function (string $header, string $value) {
        $access = new ProbeAccess(['10.0.0.0/8']);

        expect($access->allows(probe('/_metrics', '10.1.2.3', ['host' => '10.0.0.5:8080', $header => $value])))->toBeFalse()
            ->and($access->allows(probe('/_ready', '10.1.2.3', ['host' => '10.0.0.5:8080', 'user-agent' => 'kube-probe/1.31'])))->toBeTrue();
    })->with([
        ['sec-fetch-site', 'same-origin'],
        ['sec-fetch-mode', 'cors'],
        ['sec-fetch-dest', 'empty'],
        ['origin', 'http://10.0.0.5:8080'],
    ]);

    it('refuses a name for this server that is not one', function () {
        expect(fn () => new ProbeAccess(['10.0.0.0/8'], hosts: ['not a host']))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => new ProbeAccess(['10.0.0.0/8'], hosts: ['10.0.0.5']))->toThrow(\InvalidArgumentException::class);
    });

    // A proxy in front that keeps the loopback Host, or sits on an internal
    // network, still says what it is in the headers it adds.
    it('refuses what a proxy passed on, by address alone', function (string $header) {
        expect(new ProbeAccess()->allows(probe('/_health', '127.0.0.1', ['host' => 'localhost', $header => '203.0.113.9'])))->toBeFalse()
            ->and(new ProbeAccess(['10.0.0.0/8'])->allows(probe('/_health', '10.1.2.3', [$header => '203.0.113.9'])))->toBeFalse();
    })->with(['forwarded', 'x-forwarded-for', 'x-forwarded-host', 'x-real-ip', 'via']);

    it('lets the token in whatever host is named', function () {
        $access = new ProbeAccess(token: TOKEN);

        expect($access->allows(probe('/', '127.0.0.1', ['host' => 'shop.example.com', 'authorization' => 'Bearer ' . TOKEN])))->toBeTrue()
            ->and($access->allows(probe('/', '127.0.0.1', ['x-forwarded-for' => '203.0.113.9', 'authorization' => 'Bearer ' . TOKEN])))->toBeTrue();
    });

    it('lets in the networks it is given', function () {
        $access = new ProbeAccess(['10.0.0.0/8', 'fd00::/8', '192.168.1.17']);

        expect($access->allows(probe('/', '10.200.3.4')))->toBeTrue()
            ->and($access->allows(probe('/', 'fd12:3456::1')))->toBeTrue()
            ->and($access->allows(probe('/', '192.168.1.17')))->toBeTrue()
            ->and($access->allows(probe('/', '192.168.1.18')))->toBeFalse()
            ->and($access->allows(probe('/', '11.0.0.1')))->toBeFalse()
            ->and($access->allows(probe('/', '127.0.0.1')))->toBeFalse();
    });

    it('lets in the token from anywhere, and nothing like it', function () {
        $access = new ProbeAccess([], TOKEN);

        expect($access->allows(probe('/', '203.0.113.9', ['authorization' => 'Bearer ' . TOKEN])))->toBeTrue()
            ->and($access->allows(probe('/', '203.0.113.9', ['authorization' => 'bearer ' . TOKEN])))->toBeTrue()
            ->and($access->allows(probe('/', '203.0.113.9', ['authorization' => 'Bearer ' . TOKEN . 'x'])))->toBeFalse()
            ->and($access->allows(probe('/', '203.0.113.9', ['authorization' => TOKEN])))->toBeFalse()
            ->and($access->allows(probe('/', '203.0.113.9', ['x-forwarded-for' => '127.0.0.1'])))->toBeFalse()
            ->and($access->allows(probe('/', '127.0.0.1')))->toBeFalse();
    });

    it('refuses a short token and a network that is not one', function (array $networks, ?string $token) {
        new ProbeAccess($networks, $token);
    })->with([
        'short token' => [[], 'too-short'],
        'not a network' => [['10.0.0.0/33'], null],
        'not an address' => [['localhost'], null],
    ])->throws(\InvalidArgumentException::class);
});

describe('the endpoints', function () {
    it('answers /_health with ok, asking nothing', function () {
        $failing = new Check('database', new \RuntimeException('down'));
        $response = new Probes(checks: [$failing])->handle(probe('/_health'));

        expect($response->status)->toBe(200)
            ->and(json_decode($response->body(), true))->toBe(['status' => 'ok'])
            ->and($response->header('cache-control'))->toBe('no-store');
    });

    it('answers /_ready with each check by name, and why one failed in the log alone', function () {
        $sink = new MemorySink();
        $probes = new Probes(
            checks: [new Check('database'), new Check('renderer', new \RuntimeException('No renderer at /tmp/x.sock for ada@example.com'))],
            logger: new Logger($sink),
        );

        $response = $probes->handle(probe('/_ready'));

        expect($response->status)->toBe(503)
            ->and(json_decode($response->body(), true))->toBe(['status' => 'unavailable', 'checks' => ['database' => 'ok', 'renderer' => 'failing']])
            ->and($response->body())->not->toContain('No renderer')
            ->and($sink->records()[0]['check'] ?? null)->toBe('renderer')
            ->and($sink->records()[0]['message'])->toBe('RuntimeException: No renderer at /tmp/x.sock for [email]');

        $ready = new Probes(checks: [new Check('database')])->handle(probe('/_ready'));
        expect($ready->status)->toBe(200)
            ->and(json_decode($ready->body(), true))->toBe(['status' => 'ok', 'checks' => ['database' => 'ok']]);
    });

    it('answers /_metrics in the text format, and only with metrics to show', function () {
        $metrics = new Metrics()->counter('shop_orders_total', 'Orders placed.');
        $metrics->increment('shop_orders_total');

        $response = new Probes(metrics: $metrics)->handle(probe('/_metrics'));

        expect($response->status)->toBe(200)
            ->and($response->header('content-type'))->toBe(Metrics::CONTENT_TYPE)
            ->and($response->body())->toContain("shop_orders_total 1\n")
            ->and(new Probes()->matches(probe('/_metrics')))->toBeFalse();
    });

    // Not there to find: the same answer a missing path gets.
    it('answers a caller it does not let in with a plain 404, whatever the method', function (string $path, string $method) {
        $response = new Probes(metrics: new Metrics())->handle(probe($path, '203.0.113.9', method: $method));

        expect($response->status)->toBe(404)->and($response->body())->toBe('Not found.');
    })->with([['/_health', 'GET'], ['/_ready', 'GET'], ['/_metrics', 'GET'], ['/_health', 'POST']]);

    it('answers GET and HEAD alone', function () {
        $probes = new Probes();

        expect($probes->handle(probe('/_health', method: 'HEAD'))->status)->toBe(200)
            ->and($probes->handle(probe('/_health', method: 'POST'))->status)->toBe(405)
            ->and($probes->handle(probe('/_health', method: 'POST'))->header('allow'))->toBe('GET, HEAD');
    });

    it('refuses a check whose name would not read as one', function () {
        new Probes(checks: [new Check('Renderer Pool!')]);
    })->throws(\InvalidArgumentException::class);
});

describe('the checks', function () {
    it('asks the database a statement', function () {
        $check = new DatabaseCheck(PdoConnection::sqlite(':memory:', 'probe'));
        $check->check();

        expect($check->name())->toBe('database');
    });

    it('asks every renderer in a pool, and reports each by its slot', function () {
        if (!\function_exists('pcntl_fork')) {
            test()->markTestSkipped('pcntl is not available.');
        }
        $directory = pool();
        $pids = renderers([
            "{$directory}/renderer-0-3.sock" => ['t' => Frame::HEALTHY, 'pid' => 101, 'inFlight' => 2, 'served' => 40],
            "{$directory}/renderer-1-7.sock" => ['t' => Frame::HEALTHY, 'pid' => 102, 'inFlight' => 0, 'served' => 9],
            "{$directory}/renderer-2-5.sock" => null,
        ]);
        try {
            $check = new RendererCheck($directory, new RendererHealth(timeoutSeconds: 1.0));
            $check->check();
            $text = new Metrics()->collect($check)->exposition();
        } finally {
            stop($pids, $directory);
        }

        expect($text)->toContain("tinvar_renderers 3\n")
            ->and($text)->toContain("tinvar_renderers_answering 2\n")
            ->and($text)->toContain('tinvar_renderer_up{slot="0"} 1')
            ->and($text)->toContain('tinvar_renderer_up{slot="2"} 0')
            ->and($text)->toContain('tinvar_renderer_in_flight{slot="0"} 2')
            ->and($text)->toContain("# TYPE tinvar_renderer_renders_total counter\ntinvar_renderer_renders_total{slot=\"0\"} 40\ntinvar_renderer_renders_total{slot=\"1\"} 9");
    });

    it('fails when no renderer answers, saying which and why', function () {
        if (!\function_exists('pcntl_fork')) {
            test()->markTestSkipped('pcntl is not available.');
        }
        $directory = pool();
        renderers(["{$directory}/renderer-0-1.sock" => null]);
        try {
            $check = new RendererCheck($directory, new RendererHealth(timeoutSeconds: 0.5));

            expect(static fn () => $check->check())->toThrow(\RuntimeException::class, 'No renderer answered (renderer-0-1.sock:')
                ->and(new Metrics()->collect($check)->exposition())->toContain("tinvar_renderers_answering 0\n");
        } finally {
            stop([], $directory);
        }
    });

    it('fails, and reports nothing up, when there is nothing at the path', function () {
        $check = new RendererCheck('/tmp/tvprobe-none-' . bin2hex(random_bytes(4)) . '.sock');

        expect(static fn () => $check->check())->toThrow(\RuntimeException::class)
            ->and(new Metrics()->collect($check)->exposition())->toContain("tinvar_renderers_answering 0\n");
    });

    it('asks a lone renderer as slot 0', function () {
        if (!\function_exists('pcntl_fork')) {
            test()->markTestSkipped('pcntl is not available.');
        }
        $directory = pool();
        $pids = renderers(["{$directory}/renderer.sock" => ['t' => Frame::HEALTHY, 'pid' => 7, 'inFlight' => 1, 'served' => 3]]);
        try {
            $text = new Metrics()->collect(new RendererCheck("{$directory}/renderer.sock"))->exposition();
        } finally {
            stop($pids, $directory);
        }

        expect($text)->toContain('tinvar_renderer_up{slot="0"} 1')->and($text)->toContain('tinvar_renderer_in_flight{slot="0"} 1');
    });
});
