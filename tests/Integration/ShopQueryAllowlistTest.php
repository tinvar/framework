<?php

declare(strict_types=1);

/**
 * The shop's per-route query allowlists, from its build.
 *
 * tinvar-spec.md, "Renderer hardening": "At build, the server-component graph
 * of each route gives the list of query names it may call. PHP rejects any
 * other name on that route's socket, so a compromised dependency can't call
 * queries the page never uses." The shop listed them by hand, a constant on
 * each page; the renderer build now lists what each view and each deferred
 * component can call, `tinvar build:fragments` joins that with the routes,
 * and the Kernel reads the result. These hold the lists to what the hand
 * lists said -- never wider -- and a renderer to them on the socket.
 */

namespace Tinvar\Tests\Integration\ShopQueryAllowlist;

use Shop\Kernel;
use Tinvar\Bench\Shop;
use Tinvar\Codec\MessagePackCodec;
use Tinvar\Http\Request;
use Tinvar\Protocol\Frame;
use Tinvar\Query\QueryError;
use Tinvar\Render\FragmentManifest;

const KEY = 'shop-query-allowlist-key-of-32-bytes';

beforeAll(function () {
    $unbuilt = Shop::ensureBuilt();
    if ($unbuilt !== null) {
        test()->markTestSkipped($unbuilt);
    }
});

/** The manifest the build wrote, which the shop's Kernel reads. */
function built(): FragmentManifest
{
    return FragmentManifest::fromFile(Shop::root() . '/' . Kernel::MANIFEST);
}

/**
 * A renderer that, asked to render, calls the queries given over the socket
 * as a compromised dependency would, and writes back what PHP answered and
 * the allowlist the render frame carried.
 *
 * @param list<array{name: string, args: array<string, mixed>}> $calls
 */
function callingRenderer(string $path, array $calls): int
{
    $server = stream_socket_server("unix://{$path}", $errno, $error);
    if ($server === false) {
        throw new \RuntimeException("Cannot listen on {$path}: {$error}");
    }

    $pid = pcntl_fork();
    if ($pid === 0) {
        $codec = new MessagePackCodec();
        $client = stream_socket_accept($server, 10);
        if ($client !== false) {
            stream_set_timeout($client, 10);
            $read = static function () use ($client, $codec): ?array {
                $buffer = '';
                while (($bytes = fread($client, 65536)) !== false && $bytes !== '') {
                    $buffer .= $bytes;
                    $offset = 0;
                    $frame = $codec->decodeFrame($buffer, $offset);
                    if ($frame !== null) {
                        return $frame;
                    }
                }

                return null;
            };

            $render = $read();
            $queries = [];
            foreach ($calls as $id => $call) {
                $queries[] = ['id' => $id, ...$call];
            }
            @fwrite($client, $codec->encodeFrame(['t' => Frame::QUERY, 'q' => $queries]));
            $answer = $read();

            $seen = json_encode(['allow' => $render['allow'] ?? null, 'results' => $answer['r'] ?? null]);
            @fwrite($client, $codec->encodeFrame(Frame::chunk("<x-seen>{$seen}</x-seen>")));
            @fwrite($client, $codec->encodeFrame(['t' => Frame::DONE]));
            @fclose($client);
        }

        fclose($server);
        // Gone without running destructors: an inherited database
        // connection would hang up on the parent's socket.
        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }

    fclose($server);

    return $pid;
}

/**
 * One page of the shop rendered by callingRenderer(), and what it saw.
 *
 * @param list<array{name: string, args: array<string, mixed>}> $calls
 * @return array{allow: list<string>, results: list<array<string, mixed>>}
 */
function renderCalling(string $path, array $calls): array
{
    $socket = sys_get_temp_dir() . '/tinvar-allow-' . bin2hex(random_bytes(6)) . '.sock';
    $pid = callingRenderer($socket, $calls);
    try {
        $kernel = Kernel::fromEnvironment($socket, key: KEY, database: Shop::database());
        $response = $kernel->handle(new Request('GET', $path, ['accept' => 'text/html']));
    } finally {
        pcntl_waitpid($pid, $status);
        @unlink($socket);
    }

    expect($response->status)->toBe(200, $response->body());
    preg_match('#<x-seen>(.*?)</x-seen>#s', $response->body(), $seen);

    return json_decode($seen[1] ?? 'null', true, 16, JSON_THROW_ON_ERROR);
}

/** A product, by id, from the shop's database. */
function product(): string
{
    return (string) new \PDO('sqlite:' . Shop::database())->query('SELECT id FROM products ORDER BY sku LIMIT 1')?->fetchColumn();
}

describe('the lists the build gives the routes', function () {
    // What the pages' QUERIES constants said, route by route: the build's
    // lists may not be wider than the hand-kept ones they replace.
    it('are the shop\'s own, route by route', function () {
        $manifest = built();

        expect($manifest->queriesFor('shop.catalog'))->toBe(['products.availability'])
            ->and($manifest->queriesFor('shop.product'))->toBe(['products.availability'])
            ->and($manifest->queriesFor('orders.index'))->toBe([])
            // Through the shipping updates' <Deferred>: its fragment renders
            // under this route's list, though the page imports none of it.
            ->and($manifest->queriesFor('orders.show'))->toBe(['orders.shipments']);
    });

    it('allow every other route nothing', function () {
        $manifest = built();
        $kernel = Kernel::fromEnvironment('/tmp/tinvar-allow-none.sock', key: KEY, database: Shop::database());
        $listed = ['shop.catalog', 'shop.product', 'orders.index', 'orders.show'];

        foreach ($kernel->routes->pages() as $page) {
            if ($page->name !== null && !\in_array($page->name, $listed, true)) {
                expect($manifest->queriesFor($page->name))->toBe([], $page->name);
            }
        }
    });
});

describe('a renderer held to them', function () {
    beforeEach(function () {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available.');
        }
    });

    // A compromised dependency on the product page asks for an order's
    // shipping updates -- a query the page never imports. PHP refuses it on
    // the socket, before resolving its arguments, and answers the page's own.
    it('refuses a query the page never imports, and answers one it does', function () {
        $product = product();
        $order = (string) new \PDO('sqlite:' . Shop::database())->query('SELECT id FROM orders LIMIT 1')?->fetchColumn();

        $seen = renderCalling("/products/{$product}", [
            ['name' => 'orders.shipments', 'args' => ['order' => $order]],
            ['name' => 'products.availability', 'args' => ['product' => $product]],
        ]);
        $results = array_column($seen['results'], null, 'id');

        expect($seen['allow'])->toBe(['products.availability'])
            ->and($results[0]['error']['code'] ?? null)->toBe(QueryError::NOT_ALLOWED)
            ->and($results[0])->not->toHaveKey('value')
            ->and($results[1])->not->toHaveKey('error')
            ->and($results[1]['value']['product'] ?? null)->toBe($product);
    });

    // The sign-in page calls nothing, so it may call nothing.
    it('refuses everything on a route that calls no query', function () {
        $seen = renderCalling('/sign-in', [['name' => 'products.availability', 'args' => ['product' => product()]]]);

        expect($seen['allow'])->toBe([])
            ->and($seen['results'][0]['error']['code'] ?? null)->toBe(QueryError::NOT_ALLOWED);
    });
});
