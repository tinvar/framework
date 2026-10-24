<?php

declare(strict_types=1);

/**
 * The shop's telemetry (ADR 0041), against the real renderer pool: JSON log
 * lines in a file, filed under the id the response carries; /_health,
 * /_ready and /_metrics answered to loopback with no session issued; the
 * pool's renderers and the queues in the metrics.
 *
 * Skipped when the shop cannot be built.
 */

namespace Tinvar\Tests\Integration\ShopTelemetry;

use Shop\Kernel;
use Tinvar\Bench\Renderer;
use Tinvar\Bench\Shop;
use Tinvar\Http\Request;
use Tinvar\Observability\RequestId;

const KEY = 'shop-telemetry-test-key-32-bytes!!!!';

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/**
 * The shop on a pool of two renderers, logging to a file of its own.
 *
 * @param \Closure(Kernel, string, Renderer): void $use given the kernel, its log file and the renderers
 */
function shop(\Closure $use): void
{
    $renderer = Renderer::start('msgpack', 'telemetry', processes: 2);
    $log = (string) tempnam(sys_get_temp_dir(), 'tinvar-shop-log-');
    putenv("SHOP_LOG={$log}");
    try {
        // A database of its own size, so no other test in this process has
        // counted into the metrics file beside it.
        $use(Kernel::fromEnvironment($renderer->socketPath, key: KEY, database: Shop::database(customers: 17)), $log, $renderer);
    } finally {
        putenv('SHOP_LOG');
        $renderer->stop();
        @unlink($log);
    }
}

/** @return list<array<string, mixed>> */
function records(string $log): array
{
    return array_values(array_filter(array_map(
        static fn (string $line): mixed => json_decode($line, true),
        file($log, FILE_IGNORE_NEW_LINES) ?: [],
    ), \is_array(...)));
}

function from(string $path, string $address = '127.0.0.1'): Request
{
    return new Request('GET', $path, ['accept' => 'text/html'], remoteAddress: $address);
}

it('logs a page to its file under the id the response carries, and counts its render', function () {
    shop(function (Kernel $kernel, string $log): void {
        $response = $kernel->handle(from('/'));
        $kernel->terminate();
        $id = (string) $response->header('x-request-id');

        $access = array_values(array_filter(records($log), static fn (array $r): bool => $r['message'] === 'request'));
        expect($response->status)->toBe(200)
            ->and(RequestId::isValid($id))->toBeTrue()
            ->and($access)->toHaveCount(1)
            ->and($access[0])->toMatchArray(['request_id' => $id, 'route' => 'shop.catalog', 'status' => 200, 'source' => 'php']);

        $metrics = $kernel->handle(from('/_metrics'))->body();
        expect($metrics)->toContain('tinvar_http_requests_total{method="GET",route="shop.catalog",status="200"} 1')
            ->and($metrics)->toContain('tinvar_renders_total{kind="document",outcome="ok"} 1')
            ->and($metrics)->toMatch('/tinvar_query_calls_total\{kind="document"\} [1-9]/');
    });
});

it('answers /_health and /_ready to loopback, with no session, and asks every renderer in the pool', function () {
    shop(function (Kernel $kernel): void {
        $health = $kernel->handle(from('/_health'));
        $ready = $kernel->handle(from('/_ready'));

        expect($health->status)->toBe(200)
            ->and($health->cookies())->toBe([])
            ->and($ready->status)->toBe(200)
            ->and(json_decode($ready->body(), true))->toBe(['status' => 'ok', 'checks' => ['database' => 'ok', 'renderer' => 'ok']])
            ->and($ready->cookies())->toBe([]);

        $metrics = $kernel->handle(from('/_metrics'))->body();
        expect($metrics)->toContain("tinvar_renderers 2\n")
            ->and($metrics)->toContain("tinvar_renderers_answering 2\n")
            ->and($metrics)->toMatch('/tinvar_renderer_up\{slot="0"\} 1/')
            ->and($metrics)->toMatch('/tinvar_renderer_up\{slot="1"\} 1/')
            ->and($metrics)->toContain('tinvar_queue_depth{queue="mail"}');
    });
});

it('answers the probes to no one off this machine without the token', function () {
    shop(function (Kernel $kernel): void {
        foreach (['/_health', '/_ready', '/_metrics'] as $path) {
            $response = $kernel->handle(from($path, '203.0.113.9'));

            expect($response->status)->toBe(404)->and($response->body())->toBe('Not found.');
        }
    });
});

it('fails readiness when the renderers are gone, and says which check in the log', function () {
    shop(function (Kernel $kernel, string $log, Renderer $renderer): void {
        $renderer->stop();

        $ready = $kernel->handle(from('/_ready'));

        expect($ready->status)->toBe(503)
            ->and(json_decode($ready->body(), true)['checks'])->toBe(['database' => 'ok', 'renderer' => 'failing'])
            ->and(array_column(records($log), 'check'))->toContain('renderer');
    });
});
