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
