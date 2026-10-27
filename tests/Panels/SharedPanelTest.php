<?php

declare(strict_types=1);

/**
 * "A panel's shared data never reaches another panel's pages" (ADR 0060):
 * the keys a render may read are those of no panel and those of the panel
 * whose stack it is inside, placed by #[Panel] or by namespace, and each
 * is produced in the render's own tenant.
 */

namespace Tinvar\Tests\Panels\SharedPanel;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Data\SharedReads;
use Tinvar\Data\SharedRegistry;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Query\QueryRunner;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Tests\Panels\Fixtures\Admin\ReportsShared;
use Tinvar\Tests\Panels\Fixtures\App;
use Tinvar\Tests\Panels\Fixtures\EveryoneShared;
use Tinvar\Tests\Panels\Fixtures\ResellerShared;
use Tinvar\Tests\Panels\Fixtures\StaffShared;
use Tinvar\Tests\Panels\Fixtures\UndefinedPanelShared;

require_once __DIR__ . '/Fixtures/app.php';
require_once __DIR__ . '/Fixtures/shared.php';

const KEYS = ['banner', 'staffNote', 'reportNote', 'resellerNote'];

function sharedApp(): App
{
    return new App(shared: new SharedRegistry([EveryoneShared::class, StaffShared::class, ResellerShared::class, ReportsShared::class]));
}

/**
 * Every key read from inside the page's render, as the renderer would: by
 * key, its value, or its error's code.
 *
 * @return array<string, mixed>
 */
function readsOn(App $app, string $path, string $user, string $host = 'shop.test'): array
{
    $response = $app->kernel->handle(
        new Request('GET', $path, ['host' => $host, 'x-user' => $user]),
        static function (LoadedRoute $loaded, Request $passed) use ($app): Response {
            $shared = $app->kernel->shared($loaded, $passed);
            $calls = array_map(
                static fn (int $i, string $key): array => ['id' => $i, 'name' => SharedReads::PREFIX . $key, 'args' => []],
                array_keys(KEYS),
                KEYS,
            );
            $results = (new QueryRunner($app->queries, $app->orm, new AllowAllGate()))->run($calls, [], $shared);

            $out = [];
            foreach ($results as $result) {
                $out[KEYS[$result['id']]] = $result['value'] ?? $result['error']['code'];
            }

            return Response::make(200, json_encode($out, JSON_THROW_ON_ERROR));
        },
    );
    expect($response->status)->toBe(200, $response->body());

    return json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
}

it('gives a page in no panel the keys of no panel alone', function () {
    $reads = readsOn(sharedApp(), '/home', 'anyone');

    expect($reads['banner'])->toBe(['note' => 'for everyone', 'panel' => '', 'tenant' => ''])
        ->and($reads['staffNote'])->toBe('query_unknown')
        ->and($reads['reportNote'])->toBe('query_unknown')
        ->and($reads['resellerNote'])->toBe('query_unknown');
});

it('gives an admin page the admin panel\'s keys, by #[Panel] and by namespace, and no other panel\'s', function () {
    $reads = readsOn(sharedApp(), '/admin/dashboard', 'staff');

    expect($reads['banner'])->toBe(['note' => 'for everyone', 'panel' => 'admin', 'tenant' => ''])
        ->and($reads['staffNote'])->toBe(['note' => 'for staff', 'panel' => 'admin', 'tenant' => ''])
        ->and($reads['reportNote'])->toBe(['note' => 'for reports', 'panel' => 'admin', 'tenant' => ''])
        ->and($reads['resellerNote'])->toBe('query_unknown');
});

it('produces a reseller page\'s keys in the tenant its request resolved', function () {
    $reads = readsOn(sharedApp(), '/reseller', 'partner', host: 'acme.test');

    expect($reads['resellerNote'])->toBe(['note' => 'for resellers', 'panel' => 'reseller', 'tenant' => 'acme'])
        ->and($reads['banner']['tenant'])->toBe('acme')
        ->and($reads['staffNote'])->toBe('query_unknown');
});

it('refuses, when the Kernel is built, a provider naming a panel nobody defined', function () {
    expect(fn () => new App(shared: new SharedRegistry([UndefinedPanelShared::class])))
        ->toThrow(\LogicException::class, 'nowhere');
});

it('reads none without a registry', function () {
    $app = new App();
    $shared = null;
    $app->kernel->handle(
        new Request('GET', '/home', ['host' => 'shop.test', 'x-user' => 'anyone']),
        static function (LoadedRoute $loaded, Request $passed) use ($app, &$shared): Response {
            $shared = $app->kernel->shared($loaded, $passed);

            return Response::make(200, '');
        },
    );

    expect($shared)->toBeNull();
});
