<?php

declare(strict_types=1);

/**
 * File tokens and upload tickets are their host's (ADR 0149): both hash
 * the binding Uploads::sessionOf() gives, which carries the host the
 * Kernel resolved, so one minted on admin.example.com is another session's
 * on example.com -- the same cookie notwithstanding.
 */

namespace Tinvar\Tests\Uploads\UploadHost;

use Tinvar\Http\Request;
use Tinvar\Routing\HostMatch;
use Tinvar\Storage\MemoryStorage;
use Tinvar\Uploads\ArrayUploadStore;
use Tinvar\Uploads\Uploads;

function uploads(): Uploads
{
    return Uploads::fromKey('upload-host-test-key-0123456789abcdef', new MemoryStorage(), new ArrayUploadStore(), static fn (Request $r): string => 'session-a');
}

function on(?string $host): Request
{
    $request = Request::get('/');

    return $host === null ? $request : $request->withAttribute(HostMatch::ATTRIBUTE, new HostMatch(null, $host));
}

it('binds the session a file token or upload ticket hashes to the host it is asked on', function () {
    $uploads = uploads();
    $admin = $uploads->sessionHash($uploads->sessionOf(on('admin.example.com')));
    $shop = $uploads->sessionHash($uploads->sessionOf(on('example.com')));

    expect($admin)->not->toBe($shop)
        ->and($uploads->sessionHash($uploads->sessionOf(on('admin.example.com'))))->toBe($admin);
});

it('binds to the session alone where the application routes on no host', function () {
    expect(uploads()->sessionOf(on(null)))->toBe('session-a');
});
