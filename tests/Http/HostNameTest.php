<?php

declare(strict_types=1);

/**
 * One spelling for a host, which the realms, the probes, the external
 * origins and tenancy's host lookup all compare in.
 */

use Tinvar\Http\HostName;

it('normalizes a Host header to one spelling, and refuses what is no host name', function (string $header, ?string $normalized) {
    expect(HostName::normalize($header))->toBe($normalized);
})->with([
    ['acme.shop.test', 'acme.shop.test'],
    ['ACME.Shop.Test', 'acme.shop.test'],
    ['acme.shop.test:8443', 'acme.shop.test'],
    ['acme.shop.test.', 'acme.shop.test'],
    ['  acme.shop.test  ', 'acme.shop.test'],
    ['localhost', 'localhost'],
    // Machines, not names, and things that are not hosts at all.
    ['127.0.0.1', null],
    ['127.0.0.1:80', null],
    ['[::1]:8080', null],
    ['', null],
    ['acme_shop.test', null],
    ['acme.shop.test/evil', null],
    ['-acme.test', null],
    ['acme..test', null],
    ['acme.shop.test:99999999', null],
]);
