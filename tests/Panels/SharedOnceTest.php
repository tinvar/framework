<?php

declare(strict_types=1);

/**
 * Once-shared values and shared data in fragments, through the Kernel
 * (ADR 0065): a once key is sent with a signed hold, read as held on a
 * navigation that hands the hold back under the same session, user, panel
 * and build -- and produced again on anything else -- and a <Deferred>
 * component's render is handed the shared data of its route's panel, made
 * behind the route's chain.
 */

namespace Tinvar\Tests\Panels\SharedOnce;

use Tinvar\Auth\AllowAllGate;
use Tinvar\Data\SharedReads;
use Tinvar\Data\SharedRegistry;
use Tinvar\Http\Endpoints\FragmentEndpoint;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Query\QueryRunner;
use Tinvar\Render\SharedHold;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Tests\Panels\Fixtures\Admin\ReportsShared;
use Tinvar\Tests\Panels\Fixtures\App;
use Tinvar\Tests\Panels\Fixtures\EveryoneShared;
use Tinvar\Tests\Panels\Fixtures\ResellerShared;
use Tinvar\Tests\Panels\Fixtures\StaffShared;
use Tinvar\Tests\Panels\Fixtures\Trace;

require_once __DIR__ . '/Fixtures/app.php';
require_once __DIR__ . '/Fixtures/shared.php';

const KEYS = ['banner', 'welcome', 'staffNote'];

function onceApp(): App
{
    return new App(shared: new SharedRegistry([EveryoneShared::class, StaffShared::class, ResellerShared::class, ReportsShared::class]));
}

/**
 * Every key in KEYS read inside a navigation to $path, as the renderer
 * would: its value, or its error's code. With `$fresh`, read from shared
 * data made for a request carrying the same headers that never went
 * through the navigation -- as an action's re-render's does.
 *
 * @param array<string, string> $headers
 * @return array<string, mixed>
 */
function navigate(App $app, string $path, string $user, array $headers = [], bool $fresh = false): array
{
    $response = $app->kernel->handle(
        new Request('GET', $path, ['host' => 'shop.test', 'x-user' => $user, ...$headers]),
        static function (LoadedRoute $loaded, Request $passed) use ($app, $fresh, $path, $headers): Response {
            $shared = $app->kernel->shared($loaded, $fresh ? new Request('GET', $path, $headers) : $passed);
            $calls = array_map(
                static fn (int $i, string $key): array => ['id' => $i, 'name' => SharedReads::PREFIX . $key, 'args' => []],
                array_keys(KEYS),
                KEYS,
            );
            $out = [];
            foreach ((new QueryRunner($app->queries, $app->orm, new AllowAllGate()))->run($calls, [], $shared) as $result) {
                $out[KEYS[$result['id']]] = $result['value'] ?? $result['error']['code'];
            }

            return Response::make(200, json_encode($out, JSON_THROW_ON_ERROR));
        },
    );
    expect($response->status)->toBe(200, $response->body());

    return json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
}

/** A payload navigation's headers, handing back $holds. */
function payload(string ...$holds): array
{
    return ['rsc' => '1', 'x-version' => 'build-1', ...($holds === [] ? [] : [SharedHold::HEADER => implode(',', $holds)])];
}

/** The hold a first visit to $path was sent 'welcome' with. */
function holdFrom(App $app, string $path, string $user): string
{
    $welcome = navigate($app, $path, $user, payload())['welcome'];
    expect($welcome)->toBeArray()->toHaveKey('hold');

    return $welcome['hold'];
}

describe('a once-shared value', function () {
    it('is sent as a once field, with the hold the browser claims it by', function () {
        $app = onceApp();
        Trace::reset();

        $reads = navigate($app, '/home', 'anyone', payload());

        expect($reads['welcome'])->toMatchArray([
            '$once' => '$shared.welcome',
            'ttl' => 3600,
            'tags' => ['notes'],
            'value' => ['note' => 'welcome', 'panel' => '', 'tenant' => ''],
        ])
            ->and($reads['welcome']['hold'])->toBeString()->toStartWith('v1.')
            // A key that is not once is as it was.
            ->and($reads['banner'])->toBe(['note' => 'for everyone', 'panel' => '', 'tenant' => ''])
            ->and(Trace::$events)->toContain('welcome');
    });

    it('is read as held, and not produced, on a navigation handing its hold back', function () {
        $app = onceApp();
        $hold = holdFrom($app, '/home', 'anyone');
        Trace::reset();

        $reads = navigate($app, '/home', 'anyone', payload($hold));

        expect($reads['welcome'])->toBe(['$once' => '$shared.welcome', 'held' => true])
            ->and(Trace::$events)->not->toContain('welcome')
            ->and($reads['banner']['note'])->toBe('for everyone');
    });

    it('is produced for another user handing the hold back', function () {
        $app = onceApp();
        $hold = holdFrom($app, '/home', 'anyone');
        Trace::reset();

        $reads = navigate($app, '/home', 'someone-else', payload($hold));

        expect($reads['welcome'])->toHaveKey('value')
            ->and(Trace::$events)->toContain('welcome');
    });

    it('is produced in another panel than the one it was held in', function () {
        $app = onceApp();
        $hold = holdFrom($app, '/home', 'staff');

        $reads = navigate($app, '/admin/dashboard', 'staff', payload($hold));

        expect($reads['welcome'])->toHaveKey('value')
            ->and($reads['welcome']['value']['panel'])->toBe('admin');
    });

    it('is produced on a document load, whatever it hands back', function () {
        $app = onceApp();
        $hold = holdFrom($app, '/home', 'anyone');

        $reads = navigate($app, '/home', 'anyone', [SharedHold::HEADER => $hold]);

        expect($reads['welcome'])->toHaveKey('value');
    });

    it('is produced for a hold that has been tampered with', function () {
        $app = onceApp();
        $hold = holdFrom($app, '/home', 'anyone');
        [$version, $body, $mac] = explode('.', $hold);

        $reads = navigate($app, '/home', 'anyone', payload("{$version}.{$body}x.{$mac}"));

        expect($reads['welcome'])->toHaveKey('value');
    });

    it('is produced for shared data made outside the navigation, even with the hold in its headers', function () {
        $app = onceApp();
        $hold = holdFrom($app, '/home', 'anyone');

        $reads = navigate($app, '/home', 'anyone', payload($hold), fresh: true);

        expect($reads['welcome'])->toHaveKey('value');
    });

    it('says a payload varies on the holds handed back', function () {
        $app = onceApp();
        $response = $app->kernel->handle(
            new Request('GET', '/home', ['host' => 'shop.test', 'x-user' => 'anyone', ...payload()]),
            static fn (): Response => Response::make(200, ''),
        );

        expect($response->header('vary'))->toContain('X-Have-Shared');
    });
});

describe('a fragment render', function () {
    it('is handed the shared data of no panel, and none of a panel it is not in', function () {
        $app = onceApp();
        $app->fragmentKeys = KEYS;
        $token = $app->fragmentToken('home', 'anyone', '');

        expect($app->get(FragmentEndpoint::PREFIX . $token, 'anyone')->status)->toBe(200)
            ->and($app->fragmentShared)->toBeInstanceOf(SharedReads::class)
            ->and($app->fragmentReads['banner'])->toBe(['note' => 'for everyone', 'panel' => '', 'tenant' => ''])
            ->and($app->fragmentReads['staffNote'])->toBe('query_unknown');
    });

    it('reads its panel\'s keys inside that panel, behind its stack', function () {
        $app = onceApp();
        $app->fragmentKeys = KEYS;
        $token = $app->fragmentToken('admin.orders', 'staff', 'admin');

        expect($app->get(FragmentEndpoint::PREFIX . $token, 'staff')->status)->toBe(200)
            ->and(\array_slice(Trace::$events, 0, 4))->toBe(['guard:admin', 'membership', 'mfa', 'fragment'])
            ->and($app->fragmentReads['staffNote'])->toBe(['note' => 'for staff', 'panel' => 'admin', 'tenant' => ''])
            ->and($app->fragmentReads['banner']['panel'])->toBe('admin');
    });

    it('produces a once key whatever holds the request carries', function () {
        $app = onceApp();
        $hold = holdFrom($app, '/home', 'anyone');
        $app->fragmentKeys = KEYS;
        $token = $app->fragmentToken('home', 'anyone', '');

        $response = $app->get(FragmentEndpoint::PREFIX . $token, 'anyone', payload($hold));

        expect($response->status)->toBe(200)
            ->and($app->fragmentReads['welcome'])->toHaveKey('value')
            ->and($app->fragmentReads['welcome'])->toHaveKey('hold');
    });

    it('binds its reads to the panel it was made in', function () {
        $app = onceApp();
        $token = $app->fragmentToken('admin.orders', 'staff', 'admin');
        $app->get(FragmentEndpoint::PREFIX . $token, 'staff');

        // Outside the request now, so outside the panel: no read is answered.
        expect($app->fragmentShared?->scopeRefusal())->toBe('the render left the panel its shared data was made for');
    });

    it('is handed nothing when the token is refused', function () {
        $app = onceApp();
        $token = $app->fragmentToken('admin.orders', 'clerk', 'admin');

        expect($app->get(FragmentEndpoint::PREFIX . $token, 'clerk')->status)->toBe(403)
            ->and($app->fragmentShared)->toBeNull();
    });
});
