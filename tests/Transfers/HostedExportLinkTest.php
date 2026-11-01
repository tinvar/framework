<?php

declare(strict_types=1);

use Tinvar\Tests\Transfers\Fixtures\App;

require_once __DIR__ . '/Fixtures/app.php';

pest()->group('wired');

// An export held to a host by its own #[Domain] is downloaded on that host
// alone, so the link emailed for it must be built on that host -- not on the
// canonical origin, where /_download's RequireHost refuses it.
it('emails an export held to a host by its own #[Domain] a link on that host', function () {
    $app = new App(domains: new \Tinvar\Routing\Domains(['root' => 'shop.example']));

    expect($app->export(headers: ['host' => 'admin.shop.example'], name: 'hosted.widgets')->status)->toBe(202);
    $app->work();

    expect($app->notes->finished)->toHaveCount(1);
    $link = (string) $app->notes->finished[0]['link'];
    $host = (string) parse_url($link, PHP_URL_HOST);
    $path = (string) parse_url($link, PHP_URL_PATH);

    expect($app->download($path, headers: ['host' => $host])->status)->toBe(200);
});

// Every token is its host's (ADR 0149), a download link's too: one minted
// for an export started on one host opens nothing on another, even where
// the export holds no host of its own and is answered on every host.
it('opens a download link only on the host its export was started on', function () {
    $app = new App(domains: new \Tinvar\Routing\Domains(['root' => 'shop.example']));

    expect($app->export(headers: ['host' => 'shop.example'])->status)->toBe(202);
    $app->work();

    $link = (string) $app->notes->finished[0]['link'];
    $path = (string) parse_url($link, PHP_URL_PATH);

    expect(parse_url($link, PHP_URL_HOST))->toBe('shop.example')
        ->and($app->download($path, headers: ['host' => 'shop.example'])->status)->toBe(200)
        ->and($app->download($path, headers: ['host' => 'admin.shop.example'])->status)->toBe(404)
        ->and($app->download($path, headers: ['host' => 'acme.shop.example'])->status)->toBe(404);
});

// An export held to a host a parameter takes is pinned to the very host it
// was started on: acme's link opens nothing on globex's subdomain, and its
// emailed link is built on acme's.
it('pins an export held to a parameter\'s host to the host it was started on, and emails its link there', function () {
    $app = new App(domains: new \Tinvar\Routing\Domains(['root' => 'shop.example']));

    expect($app->export(headers: ['host' => 'acme.shop.example'], name: 'account.widgets')->status)->toBe(202);
    $app->work();

    $link = (string) $app->notes->finished[0]['link'];
    $path = (string) parse_url($link, PHP_URL_PATH);

    expect($link)->toStartWith('https://acme.shop.example/_download/')
        ->and($app->download($path, headers: ['host' => 'acme.shop.example'])->status)->toBe(200)
        ->and($app->download($path, headers: ['host' => 'globex.shop.example'])->status)->toBe(404)
        ->and($app->download($path, headers: ['host' => 'shop.example'])->status)->toBe(404);
});

it('lists an export with a link bound to the host it was started on', function () {
    $app = new App(domains: new \Tinvar\Routing\Domains(['root' => 'shop.example']));
    $app->export(headers: ['host' => 'acme.shop.example'], name: 'account.widgets');
    $app->work();

    $app->auth->login(new Tinvar\Tests\Transfers\Fixtures\User('staff-1'), 'e1');
    $status = $app->kernel->transferStatus;
    $mine = $app->orm->actAs('acme', fn (): array => $app->kernel->panels?->within('staff', fn (): array => $status?->recent() ?? []) ?? []);
    $path = (string) $mine[0]->download;

    expect($app->download($path, headers: ['host' => 'acme.shop.example'])->status)->toBe(200)
        ->and($app->download($path, headers: ['host' => 'globex.shop.example'])->status)->toBe(404);
});
